<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Rekonstruiert den aktuellen VF-Payload eines bereits exportierten Flugs,
 * vergleicht ihn mit dem letzten erfolgreichen Export und protokolliert
 * einen D1-Pruefsnapshot.
 *
 * Diese Klasse schreibt niemals nach Vereinsflieger.
 */
final class FlightExportChangeService
{
    private const STATUS_IN_SYNC = 'in_sync';
    private const STATUS_CHANGED = 'local_change_pending';
    private const STATUS_PERSON_BLOCKED = 'blocked_person_resolution';
    private const STATUS_PAYLOAD_BLOCKED = 'blocked_payload_generation';
    private const STATUS_RECONCILIATION = 'reconciliation_required';
    private const STATUS_ENTRY_MISSING = 'local_entry_missing';
    private const STATUS_OPERATION_MISSING = 'local_operation_missing';

    public function __construct(
        private PDO $pdo,
        private FlightPersonResolver $personResolver,
        private FlightPayloadComparator $comparator,
        private ?FlightSpecialCaseGuard $specialCaseGuard = null
    ) {
    }

    /**
     * Prueft genau einen bereits erfolgreich exportierten Buchungseintrag.
     *
     * @return array<string,mixed>
     */
    public function checkOne(int $accountingEntryId, int $actorId): array
    {
        if ($accountingEntryId <= 0) {
            throw new RuntimeException('Ungueltige Buchungs-ID.');
        }

        $export = $this->loadLatestSuccessfulExport($accountingEntryId);

        if ($export === null) {
            $reconciliation = $this->loadLatestReconciliation(
                $accountingEntryId
            );

            if ($reconciliation !== null) {
                return $this->persistTerminalCheck(
                    $accountingEntryId,
                    $reconciliation,
                    self::STATUS_RECONCILIATION,
                    $actorId,
                    'Vor dem D1-Vergleich ist eine Exportabstimmung erforderlich.'
                );
            }

            throw new RuntimeException(
                'Fuer diese Buchung existiert kein erfolgreicher VF-Export.'
            );
        }

        $historicalPayload = $this->comparator->decodePayloadJson(
            (string)$export['payload_json']
        );
        $historicalHash = $this->comparator->hash($historicalPayload);
        $entry = $this->loadAccountingEntry($accountingEntryId);

        if ($entry === null) {
            return $this->persistCheck([
                'accounting_entry_id' => $accountingEntryId,
                'operation_id' => null,
                'export' => $export,
                'status' => self::STATUS_ENTRY_MISSING,
                'old_hash' => $historicalHash,
                'current_hash' => null,
                'differences' => null,
                'current_payload' => null,
                'person_resolution' => null,
                'special_case' => null,
                'highest_risk' => null,
                'difference_count' => 0,
                'error_message' => 'Lokaler Buchungseintrag fehlt.',
                'actor_id' => $actorId,
            ]);
        }

        $operationId = (int)($entry['operation_id'] ?? 0);

        if ($operationId <= 0 || !$this->operationExists($operationId)) {
            return $this->persistCheck([
                'accounting_entry_id' => $accountingEntryId,
                'operation_id' => $operationId > 0 ? $operationId : null,
                'export' => $export,
                'status' => self::STATUS_OPERATION_MISSING,
                'old_hash' => $historicalHash,
                'current_hash' => null,
                'differences' => null,
                'current_payload' => null,
                'person_resolution' => null,
                'special_case' => null,
                'highest_risk' => null,
                'difference_count' => 0,
                'error_message' => 'Zugehoerige lokale Operation fehlt.',
                'actor_id' => $actorId,
            ]);
        }

        $towEntry = $this->loadTowEntry($entry);
        $resolution = $this->resolvePeople($entry, $towEntry);

        if ($resolution['issues'] !== []) {
            return $this->persistCheck([
                'accounting_entry_id' => $accountingEntryId,
                'operation_id' => $operationId,
                'export' => $export,
                'status' => self::STATUS_PERSON_BLOCKED,
                'old_hash' => $historicalHash,
                'current_hash' => null,
                'differences' => null,
                'current_payload' => null,
                'person_resolution' => $resolution['details'],
                'special_case' => null,
                'highest_risk' => null,
                'difference_count' => 0,
                'error_message' => implode(' ', $resolution['issues']),
                'actor_id' => $actorId,
            ]);
        }

        try {
            $currentPayload = FlightPayloadBuilder::build(
                $resolution['entry'],
                $resolution['tow_entry']
            );
        } catch (Throwable $error) {
            return $this->persistCheck([
                'accounting_entry_id' => $accountingEntryId,
                'operation_id' => $operationId,
                'export' => $export,
                'status' => self::STATUS_PAYLOAD_BLOCKED,
                'old_hash' => $historicalHash,
                'current_hash' => null,
                'differences' => null,
                'current_payload' => null,
                'person_resolution' => $resolution['details'],
                'special_case' => null,
                'highest_risk' => null,
                'difference_count' => 0,
                'error_message' => $this->limit($error->getMessage()),
                'actor_id' => $actorId,
            ]);
        }

        $specialCase = $this->inspectSpecialCase(
            $entry,
            $currentPayload,
            $resolution['details']
        );
        $comparison = $this->comparator->compare(
            $historicalPayload,
            $currentPayload
        );

        return $this->persistCheck([
            'accounting_entry_id' => $accountingEntryId,
            'operation_id' => $operationId,
            'export' => $export,
            'status' => $comparison['status'],
            'old_hash' => $comparison['old_hash'],
            'current_hash' => $comparison['current_hash'],
            'differences' => $comparison['differences'],
            'current_payload' => $comparison['current_payload'],
            'person_resolution' => $resolution['details'],
            'special_case' => $specialCase,
            'highest_risk' => $comparison['highest_risk'],
            'difference_count' => $comparison['difference_count'],
            'error_message' => null,
            'actor_id' => $actorId,
            'comparison' => $comparison,
        ]);
    }

    /**
     * Prueft eine begrenzte Liste sichtbarer Exporte einzeln.
     * Fehler eines Eintrags stoppen die restlichen Pruefungen nicht.
     *
     * @param list<int> $accountingEntryIds
     * @return array<string,mixed>
     */
    public function checkMany(
        array $accountingEntryIds,
        int $actorId,
        int $maximum = 100
    ): array {
        $ids = $this->normalizeIds($accountingEntryIds);
        $maximum = max(1, min(500, $maximum));

        if (count($ids) > $maximum) {
            throw new RuntimeException(
                'Pro D1-Pruefung sind hoechstens '
                . $maximum . ' Fluege erlaubt.'
            );
        }

        $items = [];
        $counts = [
            self::STATUS_IN_SYNC => 0,
            self::STATUS_CHANGED => 0,
            self::STATUS_PERSON_BLOCKED => 0,
            self::STATUS_PAYLOAD_BLOCKED => 0,
            self::STATUS_RECONCILIATION => 0,
            self::STATUS_ENTRY_MISSING => 0,
            self::STATUS_OPERATION_MISSING => 0,
            'error' => 0,
        ];

        foreach ($ids as $id) {
            try {
                $result = $this->checkOne($id, $actorId);
                $items[] = $result;
                $status = (string)$result['check_status'];
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            } catch (Throwable $error) {
                $counts['error']++;
                $items[] = [
                    'accounting_entry_id' => $id,
                    'check_status' => 'error',
                    'error_message' => $this->limit($error->getMessage()),
                ];
            }
        }

        return [
            'ok' => true,
            'checked_count' => count($ids),
            'counts' => $counts,
            'items' => $items,
        ];
    }

    /**
     * Liefert die neuesten erfolgreichen Exporte fuer die D1-Auswahl.
     *
     * @return list<array<string,mixed>>
     */
    public function listCandidates(
        string $from,
        string $to,
        string $status = 'all',
        bool $changedOnly = false,
        int $limit = 200
    ): array {
        $this->assertDate($from);
        $this->assertDate($to);

        if ($to < $from) {
            throw new RuntimeException('Bis darf nicht vor Von liegen.');
        }

        $allowedStatuses = [
            'all',
            self::STATUS_IN_SYNC,
            self::STATUS_CHANGED,
            self::STATUS_PERSON_BLOCKED,
            self::STATUS_PAYLOAD_BLOCKED,
            self::STATUS_RECONCILIATION,
            self::STATUS_ENTRY_MISSING,
            self::STATUS_OPERATION_MISSING,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Ungueltiger D1-Statusfilter.');
        }

        $limit = max(1, min(500, $limit));
        $sql = "SELECT
                    ae.id AS accounting_entry_id,
                    ae.operation_id,
                    ae.callsign,
                    ae.pilot_name,
                    ae.attendant_name,
                    ae.departure_time,
                    ae.approval_status,
                    ae.vf_exported_at,
                    ae.vf_sync_status,
                    ae.vf_sync_checked_at,
                    ae.vf_local_changed_at,
                    ae.vf_local_change_reason,
                    ae.row_version,
                    vfe.id AS vf_flight_export_id,
                    vfe.vf_flid,
                    vfe.succeeded_at,
                    latest_check.id AS latest_check_id,
                    latest_check.check_status AS latest_check_status,
                    latest_check.difference_count,
                    latest_check.highest_risk,
                    latest_check.checked_at
                FROM accounting_entries ae
                JOIN vf_flight_exports vfe
                  ON vfe.id = (
                      SELECT x.id
                      FROM vf_flight_exports x
                      WHERE x.accounting_entry_id = ae.id
                        AND x.status = 'success'
                        AND x.vf_flid IS NOT NULL
                      ORDER BY x.id DESC
                      LIMIT 1
                  )
                LEFT JOIN vf_flight_export_change_checks latest_check
                  ON latest_check.id = (
                      SELECT c.id
                      FROM vf_flight_export_change_checks c
                      WHERE c.vf_flight_export_id = vfe.id
                      ORDER BY c.id DESC
                      LIMIT 1
                  )
                WHERE DATE(ae.departure_time) BETWEEN ? AND ?";
        $parameters = [$from, $to];

        if ($status !== 'all') {
            $sql .= ' AND COALESCE(latest_check.check_status, '
                . 'ae.vf_sync_status, \'in_sync\') = ?';
            $parameters[] = $status;
        }

        if ($changedOnly) {
            $sql .= " AND COALESCE(
                latest_check.check_status,
                ae.vf_sync_status,
                'in_sync'
            ) <> 'in_sync'";
        }

        $sql .= ' ORDER BY ae.departure_time DESC, ae.id DESC LIMIT '
            . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function latestCheck(int $accountingEntryId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM vf_flight_export_change_checks
             WHERE accounting_entry_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute([$accountingEntryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        foreach (
            [
                'differences_json' => 'differences',
                'current_payload_json' => 'current_payload',
                'person_resolution_json' => 'person_resolution',
                'special_case_json' => 'special_case',
            ] as $column => $target
        ) {
            $row[$target] = $this->decodeNullableJson($row[$column] ?? null);
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function loadLatestSuccessfulExport(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM vf_flight_exports
             WHERE accounting_entry_id = ?
               AND status = 'success'
               AND vf_flid IS NOT NULL
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute([$entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function loadLatestReconciliation(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM vf_flight_exports
             WHERE accounting_entry_id = ?
               AND status = 'reconciliation_required'
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute([$entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function loadAccountingEntry(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM accounting_entries WHERE id = ? LIMIT 1'
        );
        $statement->execute([$entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function operationExists(int $operationId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM operations WHERE id = ? LIMIT 1'
        );
        $statement->execute([$operationId]);

        return (bool)$statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function loadTowEntry(array $entry): ?array
    {
        if ((string)($entry['entry_type'] ?? '') !== 'glider_flight') {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT *
             FROM accounting_entries
             WHERE operation_id = ?
               AND entry_type = 'tow_charge'
             ORDER BY id
             LIMIT 1"
        );
        $statement->execute([(int)$entry['operation_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array{
     *   entry:array<string,mixed>,
     *   tow_entry:?array<string,mixed>,
     *   details:array<string,mixed>,
     *   issues:list<string>
     * }
     */
    private function resolvePeople(array $entry, ?array $towEntry): array
    {
        $resolvedEntry = $entry;
        $resolvedTow = $towEntry;
        $details = [];
        $issues = [];
        $entryType = (string)($entry['entry_type'] ?? '');

        $pilotInput = $entryType === 'glider_flight'
            ? ($entry['pilot_name'] ?? null)
            : $this->firstNonEmpty(
                $entry['tow_pilot_name'] ?? null,
                $entry['pilot_name'] ?? null
            );
        $pilot = $this->personResolver->resolve($pilotInput);
        $details['pilot'] = $pilot;

        if (($pilot['status'] ?? '') !== 'resolved') {
            $issues[] = $this->resolutionIssue($pilot, 'Pilot fehlt.');
        } else {
            if ($entryType === 'glider_flight') {
                $resolvedEntry['pilot_name'] = $pilot['display_name'];
                $resolvedEntry['pilot_vf_user_no'] = $pilot['vf_user_no'];
            } else {
                $resolvedEntry['tow_pilot_name'] = $pilot['display_name'];
                $resolvedEntry['tow_pilot_vf_user_no'] = $pilot['vf_user_no'];
                $resolvedEntry['pilot_name'] = $pilot['display_name'];
                $resolvedEntry['pilot_vf_user_no'] = $pilot['vf_user_no'];
            }
        }

        $attendantName = trim((string)($entry['attendant_name'] ?? ''));
        $attendant = $this->personResolver->resolve($attendantName);
        $details['attendant'] = $attendant;

        if ($attendantName !== '') {
            if (($attendant['status'] ?? '') !== 'resolved') {
                $issues[] = $this->resolutionIssue(
                    $attendant,
                    'Begleiter konnte nicht aufgeloest werden.'
                );
            } else {
                $resolvedEntry['attendant_name'] = $attendant['display_name'];
                $resolvedEntry['attendant_vf_user_no'] =
                    $attendant['vf_user_no'];
            }
        }

        $towPilotName = $this->firstNonEmpty(
            $entry['tow_pilot_name'] ?? null,
            $towEntry['tow_pilot_name'] ?? null,
            $towEntry['pilot_name'] ?? null
        );
        $towPilot = $this->personResolver->resolve($towPilotName);
        $details['tow_pilot'] = $towPilot;

        if ($towPilotName !== '') {
            if (($towPilot['status'] ?? '') !== 'resolved') {
                $issues[] = $this->resolutionIssue(
                    $towPilot,
                    'Schlepppilot konnte nicht aufgeloest werden.'
                );
            } else {
                $resolvedEntry['tow_pilot_name'] =
                    $towPilot['display_name'];
                $resolvedEntry['tow_pilot_vf_user_no'] =
                    $towPilot['vf_user_no'];

                if ($resolvedTow !== null) {
                    $resolvedTow['tow_pilot_name'] =
                        $towPilot['display_name'];
                    $resolvedTow['pilot_name'] =
                        $towPilot['display_name'];
                    $resolvedTow['tow_pilot_vf_user_no'] =
                        $towPilot['vf_user_no'];
                    $resolvedTow['pilot_vf_user_no'] =
                        $towPilot['vf_user_no'];
                }
            }
        }

        return [
            'entry' => $resolvedEntry,
            'tow_entry' => $resolvedTow,
            'details' => $details,
            'issues' => array_values(array_filter(array_unique($issues))),
        ];
    }

    /** @return array<string,mixed>|null */
    private function inspectSpecialCase(
        array $entry,
        array $payload,
        array $personResolution
    ): ?array {
        if ($this->specialCaseGuard === null) {
            return null;
        }

        return $this->specialCaseGuard->inspect([
            'entry_type' => (string)($entry['entry_type'] ?? ''),
            'payload' => $payload,
            'person_resolution' => $personResolution,
        ]);
    }

    /** @param array<string,mixed> $data */
    private function persistCheck(array $data): array
    {
        $export = $data['export'];
        $differencesJson = $this->encodeNullableJson($data['differences']);
        $currentPayloadJson = $this->encodeNullableJson(
            $data['current_payload']
        );
        $personResolutionJson = $this->encodeNullableJson(
            $data['person_resolution']
        );
        $specialCaseJson = $this->encodeNullableJson(
            $data['special_case']
        );

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO vf_flight_export_change_checks (
                    accounting_entry_id,
                    operation_id,
                    vf_flight_export_id,
                    vf_flid,
                    check_status,
                    old_payload_hash,
                    current_payload_hash,
                    difference_count,
                    highest_risk,
                    differences_json,
                    current_payload_json,
                    person_resolution_json,
                    special_case_json,
                    error_message,
                    checked_by,
                    checked_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $insert->execute([
                $data['accounting_entry_id'],
                $data['operation_id'],
                (int)$export['id'],
                (string)($export['vf_flid'] ?? ''),
                $data['status'],
                $data['old_hash'],
                $data['current_hash'],
                $data['difference_count'],
                $data['highest_risk'],
                $differencesJson,
                $currentPayloadJson,
                $personResolutionJson,
                $specialCaseJson,
                $data['error_message'] !== null
                    ? $this->limit((string)$data['error_message'])
                    : null,
                $data['actor_id'] > 0 ? $data['actor_id'] : null,
            ]);
            $checkId = (int)$this->pdo->lastInsertId();

            $update = $this->pdo->prepare(
                "UPDATE accounting_entries
                 SET vf_sync_status = ?,
                     vf_sync_checked_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([
                $data['status'],
                $data['accounting_entry_id'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $result = [
            'check_id' => $checkId,
            'accounting_entry_id' => (int)$data['accounting_entry_id'],
            'operation_id' => $data['operation_id'] !== null
                ? (int)$data['operation_id']
                : null,
            'vf_flight_export_id' => (int)$export['id'],
            'vf_flid' => (string)($export['vf_flid'] ?? ''),
            'check_status' => (string)$data['status'],
            'old_payload_hash' => (string)$data['old_hash'],
            'current_payload_hash' => $data['current_hash'],
            'difference_count' => (int)$data['difference_count'],
            'highest_risk' => $data['highest_risk'],
            'differences' => $data['differences'],
            'current_payload' => $data['current_payload'],
            'person_resolution' => $data['person_resolution'],
            'special_case' => $data['special_case'],
            'error_message' => $data['error_message'],
        ];

        if (isset($data['comparison'])) {
            $result['comparison'] = $data['comparison'];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function persistTerminalCheck(
        int $entryId,
        array $export,
        string $status,
        int $actorId,
        string $message
    ): array {
        $payloadJson = trim((string)($export['payload_json'] ?? ''));
        $payload = $payloadJson !== ''
            ? $this->comparator->decodePayloadJson($payloadJson)
            : [];

        return $this->persistCheck([
            'accounting_entry_id' => $entryId,
            'operation_id' => isset($export['operation_id'])
                ? (int)$export['operation_id']
                : null,
            'export' => $export,
            'status' => $status,
            'old_hash' => $payload !== []
                ? $this->comparator->hash($payload)
                : str_repeat('0', 64),
            'current_hash' => null,
            'differences' => null,
            'current_payload' => null,
            'person_resolution' => null,
            'special_case' => null,
            'highest_risk' => null,
            'difference_count' => 0,
            'error_message' => $message,
            'actor_id' => $actorId,
        ]);
    }

    /** @param list<int> $values @return list<int> */
    private function normalizeIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (
                filter_var($value, FILTER_VALIDATE_INT) === false
                || (int)$value <= 0
            ) {
                throw new RuntimeException('Ungueltige Buchungs-ID.');
            }
            $ids[(int)$value] = (int)$value;
        }

        return array_values($ids);
    }

    private function resolutionIssue(array $resolution, string $fallback): string
    {
        $issue = trim((string)($resolution['issue'] ?? ''));

        return $issue !== '' ? $issue : $fallback;
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

    private function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Ungueltiges Datum.');
        }
    }

    private function encodeNullableJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException(
                'D1-Pruefdaten konnten nicht serialisiert werden: '
                . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private function decodeNullableJson(mixed $value): mixed
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        try {
            return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Gespeicherte D1-Pruefdaten sind ungueltig: '
                . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private function limit(string $value): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, 1000, 'UTF-8')
            : substr($value, 0, 1000);
    }
}
