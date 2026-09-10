<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use PDO;
use RuntimeException;

final class FlightExportService
{
    public function __construct(
        private PDO $pdo,
        private FlightRestClient $client,
        private FlightPersonResolver $personResolver
    ) {
    }

    public function preview(string $from, string $to): array
    {
        $this->assertDateRange($from, $to);
        $rows = $this->loadCandidates($from, $to);
        $items = [];
        $blocked = 0;

        foreach ($rows as $row) {
            [$resolvedRow, $resolvedTow, $personResolution, $personIssues]
                = $this->resolvePeople($row, $row['_tow_entry']);

            $issues = array_merge(
                $this->validate($resolvedRow, $resolvedTow),
                $personIssues
            );
            $payload = [];

            if ($issues === []) {
                try {
                    $payload = FlightPayloadBuilder::build(
                        $resolvedRow,
                        $resolvedTow
                    );
                } catch (\Throwable $error) {
                    $issues[] = $error->getMessage();
                }
            }

            $previous = $row['_successful_export'];
            $action = 'flight/add';

            if ($previous !== null) {
                $action = $payload !== []
                    && FlightPayloadBuilder::hash($payload)
                        === (string)$previous['payload_hash']
                    ? 'none'
                    : 'blocked_edit_not_enabled';

                $issues[] = $action === 'none'
                    ? 'Bereits mit unverändertem Payload exportiert.'
                    : 'Bereits exportiert und lokal geändert; '
                        . 'flight/edit ist in Stufe C1 gesperrt.';
            }

            if ($issues !== []) {
                $blocked++;
            }

            $items[] = [
                'entry_id' => (int)$row['id'],
                'operation_id' => (int)$row['operation_id'],
                'entry_type' => (string)$row['entry_type'],
                'callsign' => $payload['callsign']
                    ?? (string)$row['callsign'],
                'pilot' => $payload['pilotname']
                    ?? (string)($row['pilot_name'] ?? ''),
                'departuretime' => $payload['departuretime'] ?? '',
                'arrivaltime' => $payload['arrivaltime'] ?? '',
                'action' => $action,
                'send_allowed' => $issues === []
                    && $action === 'flight/add',
                'issues' => array_values(array_unique($issues)),
                'person_resolution' => $personResolution,
                'payload' => $payload,
            ];
        }

        return [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'candidate_count' => count($items),
            'sendable_count' => count(array_filter(
                $items,
                static fn(array $item): bool => $item['send_allowed']
            )),
            'blocked_count' => $blocked,
            'items' => $items,
            'safety' => [
                'single_flight_only' => true,
                'edit_disabled' => true,
                'delete_disabled' => true,
                'batch_disabled' => true,
                'jointow_disabled' => true,
                'person_resolution_required' => true,
            ],
        ];
    }

    public function sendOne(
        int $entryId,
        int $actorId,
        bool $confirmed
    ): array {
        if (!$confirmed) {
            throw new RuntimeException(
                'Der Testexport muss ausdrücklich bestätigt werden.'
            );
        }

        $row = $this->loadOne($entryId);

        if ($row === null) {
            throw new RuntimeException(
                'Flugeintrag nicht gefunden oder nicht exportierbar.'
            );
        }

        [$resolvedRow, $resolvedTow, $personResolution, $personIssues]
            = $this->resolvePeople($row, $row['_tow_entry']);

        $issues = array_merge(
            $this->validate($resolvedRow, $resolvedTow),
            $personIssues
        );

        if ($issues !== []) {
            throw new RuntimeException(
                'Export gesperrt: ' . implode(', ', array_unique($issues))
            );
        }

        if ($row['_successful_export'] !== null) {
            throw new RuntimeException(
                'Dieser Flugeintrag wurde bereits erfolgreich übertragen.'
            );
        }

        $payload = FlightPayloadBuilder::build(
            $resolvedRow,
            $resolvedTow
        );
        $hash = FlightPayloadBuilder::hash($payload);

        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare(
                'SELECT id, approval_status, vf_exported_at '
                . 'FROM accounting_entries '
                . 'WHERE id = ? '
                . 'FOR UPDATE'
            );
            $lock->execute([$entryId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);

            if (
                !$locked
                || $locked['approval_status'] !== 'approved'
                || $locked['vf_exported_at'] !== null
            ) {
                throw new RuntimeException(
                    'Der Flugstatus hat sich seit der Vorschau geändert.'
                );
            }

            $exists = $this->pdo->prepare(
                "SELECT 1 FROM vf_flight_exports "
                . "WHERE accounting_entry_id = ? AND status = 'success' "
                . 'LIMIT 1'
            );
            $exists->execute([$entryId]);

            if ($exists->fetchColumn()) {
                throw new RuntimeException(
                    'Dublettenschutz: Flug wurde bereits übertragen.'
                );
            }

            $pending = $this->pdo->prepare(
                'INSERT INTO vf_flight_exports '
                . '(accounting_entry_id, operation_id, api_action, '
                . 'payload_hash, payload_json, status, attempted_by) '
                . "VALUES (?, ?, 'flight/add', ?, ?, 'pending', ?)"
            );
            $pending->execute([
                $entryId,
                (int)$row['operation_id'],
                $hash,
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
                $actorId,
            ]);
            $logId = (int)$this->pdo->lastInsertId();

            $response = $this->client->addFlight($payload);
            $flid = $this->extractFlid($response);

            if ($flid === '') {
                throw new RuntimeException(
                    'VF meldete Erfolg, aber keine flid. '
                    . 'Flug wurde lokal nicht als exportiert markiert.'
                );
            }

            $updateLog = $this->pdo->prepare(
                "UPDATE vf_flight_exports SET "
                . "vf_flid = ?, status = 'success', http_status = 200, "
                . 'response_json = ?, succeeded_at = NOW() '
                . 'WHERE id = ?'
            );
            $updateLog->execute([
                $flid,
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
                $logId,
            ]);

            $ids = [$entryId];
            if ($row['_tow_entry'] !== null) {
                $ids[] = (int)$row['_tow_entry']['id'];
            }

            $marks = implode(',', array_fill(0, count($ids), '?'));
            $batch = 'VF-API-' . date('Ymd-His') . '-' . $entryId;
            $mark = $this->pdo->prepare(
                'UPDATE accounting_entries SET '
                . 'vf_exported_at = NOW(), '
                . 'exported_at = COALESCE(exported_at, NOW()), '
                . 'export_batch = ?, approval_status = \'exported\' '
                . "WHERE id IN ({$marks}) "
                . "AND approval_status = 'approved' "
                . 'AND vf_exported_at IS NULL'
            );
            $mark->execute(array_merge([$batch], $ids));

            if ($mark->rowCount() !== count($ids)) {
                throw new RuntimeException(
                    'Lokale Exportmarkierung war nicht vollständig; '
                    . 'Transaktion wird zurückgerollt.'
                );
            }

            $this->pdo->commit();

            return [
                'entry_id' => $entryId,
                'flid' => $flid,
                'action' => 'flight/add',
                'export_batch' => $batch,
                'person_resolution' => $personResolution,
                'response' => $response,
            ];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logFailure(
                $entryId,
                (int)$row['operation_id'],
                $hash,
                $payload,
                $actorId,
                $error->getMessage()
            );

            throw $error;
        }
    }

    /**
     * @return array{0:array<string,mixed>,1:?array<string,mixed>,2:array<string,mixed>,3:list<string>}
     */
    private function resolvePeople(array $entry, ?array $towEntry): array
    {
        $resolvedEntry = $entry;
        $resolvedTow = $towEntry;
        $issues = [];
        $resolution = [];

        $pilotInput = (string)($entry['entry_type'] ?? '') === 'glider_flight'
            ? ($entry['pilot_name'] ?? null)
            : $this->firstNonEmpty(
                $entry['tow_pilot_name'] ?? null,
                $entry['pilot_name'] ?? null
            );

        $pilot = $this->personResolver->resolve($pilotInput);
        $resolution['pilot'] = $pilot;

        if ($pilot['status'] !== 'resolved') {
            $issues[] = $pilot['issue'] !== ''
                ? $pilot['issue']
                : 'Pilot fehlt.';
        } else {
            $resolvedEntry['pilot_name'] = $pilot['display_name'];
            $resolvedEntry['pilot_vf_user_no'] = $pilot['vf_user_no'];
        }

        $attendantName = trim((string)($entry['attendant_name'] ?? ''));
        if ($attendantName !== '') {
            $attendant = $this->personResolver->resolve($attendantName);
            $resolution['attendant'] = $attendant;

            if ($attendant['status'] !== 'resolved') {
                $issues[] = $attendant['issue'];
            } else {
                $resolvedEntry['attendant_name'] = $attendant['display_name'];
                $resolvedEntry['attendant_vf_user_no'] = $attendant['vf_user_no'];
            }
        } else {
            $resolution['attendant'] = $this->personResolver->resolve('');
        }

        $towPilotName = $this->firstNonEmpty(
            $entry['tow_pilot_name'] ?? null,
            $towEntry['tow_pilot_name'] ?? null,
            $towEntry['pilot_name'] ?? null
        );

        if ($towPilotName !== '') {
            $towPilot = $this->personResolver->resolve($towPilotName);
            $resolution['tow_pilot'] = $towPilot;

            if ($towPilot['status'] !== 'resolved') {
                $issues[] = $towPilot['issue'];
            } else {
                $resolvedEntry['tow_pilot_name'] = $towPilot['display_name'];
                $resolvedEntry['tow_pilot_vf_user_no'] = $towPilot['vf_user_no'];

                if ($resolvedTow !== null) {
                    $resolvedTow['tow_pilot_name'] = $towPilot['display_name'];
                    $resolvedTow['pilot_name'] = $towPilot['display_name'];
                    $resolvedTow['tow_pilot_vf_user_no'] = $towPilot['vf_user_no'];
                    $resolvedTow['pilot_vf_user_no'] = $towPilot['vf_user_no'];
                }
            }
        } else {
            $resolution['tow_pilot'] = $this->personResolver->resolve('');
        }

        return [
            $resolvedEntry,
            $resolvedTow,
            $resolution,
            array_values(array_filter($issues)),
        ];
    }

    private function loadCandidates(string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM accounting_entries "
            . 'WHERE DATE(departure_time) BETWEEN ? AND ? '
            . "AND approval_status = 'approved' "
            . 'AND vf_exported_at IS NULL '
            . "AND entry_type IN ('glider_flight','towplane_own','tow_charge') "
            . 'ORDER BY operation_id, departure_time, id'
        );
        $statement->execute([$from, $to]);

        return $this->prepareRows(
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function loadOne(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM accounting_entries "
            . 'WHERE id = ? '
            . "AND approval_status = 'approved' "
            . 'AND vf_exported_at IS NULL '
            . "AND entry_type IN ('glider_flight','towplane_own','tow_charge')"
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->prepareRows([$row])[0] ?? null;
    }

    private function prepareRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ($row['entry_type'] === 'tow_charge') {
                $glider = $this->pdo->prepare(
                    "SELECT id FROM accounting_entries "
                    . 'WHERE operation_id = ? '
                    . "AND entry_type = 'glider_flight' "
                    . 'LIMIT 1'
                );
                $glider->execute([$row['operation_id']]);

                if ($glider->fetchColumn()) {
                    continue;
                }
            }

            $row['_tow_entry'] = null;

            if ($row['entry_type'] === 'glider_flight') {
                $tow = $this->pdo->prepare(
                    "SELECT * FROM accounting_entries "
                    . 'WHERE operation_id = ? '
                    . "AND entry_type = 'tow_charge' "
                    . 'ORDER BY id LIMIT 1'
                );
                $tow->execute([$row['operation_id']]);
                $row['_tow_entry'] = $tow->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $previous = $this->pdo->prepare(
                'SELECT vf_flid, payload_hash FROM vf_flight_exports '
                . 'WHERE accounting_entry_id = ? '
                . "AND status = 'success' "
                . 'ORDER BY id DESC LIMIT 1'
            );
            $previous->execute([$row['id']]);
            $row['_successful_export'] = $previous->fetch(PDO::FETCH_ASSOC)
                ?: null;

            $result[] = $row;
        }

        return $result;
    }

    private function validate(array $entry, ?array $tow): array
    {
        $issues = [];
        $required = [
            'callsign' => 'Flugzeug',
            'departure_time' => 'Startzeit',
            'vf_flight_type_id' => 'Flugart',
            'charge_mode' => 'Abrechnung',
        ];

        foreach ($required as $field => $label) {
            if (trim((string)($entry[$field] ?? '')) === '') {
                $issues[] = $label . ' fehlt';
            }
        }

        $type = (string)$entry['entry_type'];

        if ($type === 'glider_flight') {
            if (trim((string)($entry['arrival_time'] ?? '')) === '') {
                $issues[] = 'Landezeit fehlt';
            }
            if ((int)($entry['flight_minutes'] ?? 0) <= 0) {
                $issues[] = 'Flugzeit ungültig';
            }

            if ((int)($entry['start_type'] ?? 0) === 3) {
                $towCall = trim((string)(
                    $entry['tow_callsign']
                    ?? $tow['tow_callsign']
                    ?? ''
                ));
                $towMinutes = (int)(
                    $entry['tow_minutes']
                    ?? $tow['tow_minutes']
                    ?? 0
                );

                if ($towCall === '') {
                    $issues[] = 'Schleppflugzeug fehlt';
                }
                if ($towMinutes <= 0) {
                    $issues[] = 'Schleppzeit ungültig';
                }
            }
        } else {
            $minutes = (int)(
                $entry['tow_minutes']
                ?? $entry['flight_minutes']
                ?? 0
            );
            if ($minutes <= 0) {
                $issues[] = 'Motorflugzeit ungültig';
            }
        }

        return array_values(array_unique($issues));
    }

    private function extractFlid(array $response): string
    {
        foreach (['flid', 'id', 'flightid'] as $key) {
            $value = trim((string)(
                $response[$key]
                ?? ($response['data'][$key] ?? '')
            ));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function logFailure(
        int $entryId,
        int $operationId,
        string $hash,
        array $payload,
        int $actorId,
        string $message
    ): void {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO vf_flight_exports '
                . '(accounting_entry_id, operation_id, api_action, '
                . 'payload_hash, payload_json, status, error_message, '
                . 'attempted_by) '
                . "VALUES (?, ?, 'flight/add', ?, ?, 'failed', ?, ?)"
            );
            $statement->execute([
                $entryId,
                $operationId,
                $hash,
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                $this->limitText($message, 1000),
                $actorId,
            ]);
        } catch (\Throwable $logError) {
            error_log(
                'VF flight failure log failed: '
                . $logError->getMessage()
            );
        }
    }

    private function assertDateRange(string $from, string $to): void
    {
        foreach ([$from, $to] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new RuntimeException('Ungültiges Datum.');
            }
        }

        if ($to < $from) {
            throw new RuntimeException('Bis darf nicht vor Von liegen.');
        }

        $days = (new \DateTimeImmutable($from))
            ->diff(new \DateTimeImmutable($to))
            ->days;

        if ($days > 31) {
            throw new RuntimeException(
                'Vorschau ist auf 31 Tage begrenzt.'
            );
        }
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function limitText(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
