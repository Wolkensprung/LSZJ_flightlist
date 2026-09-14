<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Fuehrt lokale Admin-Korrekturen an bereits erfolgreich nach
 * Vereinsflieger exportierten Buchungseintraegen aus.
 *
 * Sicherheitsregeln:
 * - ausschliesslich ADMIN
 * - ausdrueckliche Warnbestaetigung
 * - Korrekturgrund mit 5 bis 1000 Zeichen
 * - SELECT ... FOR UPDATE und row_version-Pruefung
 * - Exporthistorie, vf_flid und Exportzeitpunkte bleiben unveraendert
 * - lokale Korrektur, Audit und D1-Pruefsnapshot in einer Transaktion
 * - keine Schreiboperation an Vereinsflieger
 */
final class ExportedFlightCorrectionService
{
    private const STATUS_IN_SYNC = 'in_sync';
    private const STATUS_PERSON_BLOCKED = 'blocked_person_resolution';
    private const STATUS_PAYLOAD_BLOCKED = 'blocked_payload_generation';

    /**
     * Ausschliesslich diese fachlichen Felder duerfen durch den D1-Endpunkt
     * veraendert werden. Technische Export- und Auditfelder sind absichtlich
     * nicht enthalten.
     *
     * @var array<string,string>
     */
    private const EDITABLE_FIELDS = [
        'callsign' => 'text',
        'pilot_name' => 'nullable_text',
        'attendant_name' => 'nullable_text',
        'tow_pilot_name' => 'nullable_text',
        'departure_time' => 'required_datetime',
        'departure_location' => 'nullable_text',
        'arrival_time' => 'nullable_datetime',
        'arrival_location' => 'nullable_text',
        'flight_minutes' => 'nullable_non_negative_int',
        'landing_count' => 'positive_int',
        'start_type' => 'nullable_non_negative_int',
        'comment' => 'nullable_text',
        'tow_height_m' => 'nullable_non_negative_int',
        'tow_callsign' => 'nullable_text',
        'tow_minutes' => 'nullable_non_negative_int',
        'motor_minutes' => 'nullable_non_negative_int',
        'block_minutes' => 'nullable_non_negative_int',
        'vf_flight_type_id' => 'nullable_non_negative_int',
        'charge_mode' => 'nullable_non_negative_int',
        'uid_charge' => 'nullable_non_negative_int',
        'invoiced' => 'boolean_int',
        'km' => 'nullable_non_negative_number',
        'winch_id' => 'nullable_non_negative_int',
        'wid' => 'nullable_non_negative_int',
        'winch_driver_vf_user_no' => 'nullable_non_negative_int',
        'uid_winch' => 'nullable_non_negative_int',
        'flight_instructor_vf_user_no' => 'nullable_non_negative_int',
        'uid_fi' => 'nullable_non_negative_int',
    ];

    /** @var list<string>|null */
    private ?array $existingAccountingColumns = null;

    public function __construct(
        private PDO $pdo,
        private FlightPersonResolver $personResolver,
        private FlightPayloadComparator $comparator,
        private ?FlightSpecialCaseGuard $specialCaseGuard = null
    ) {
    }

    /**
     * Speichert eine lokale Admin-Korrektur und den zugehoerigen D1-Check.
     *
     * $actor muss aus der serverseitig authentifizierten Sitzung stammen.
     * Erwartet werden mindestens id und role beziehungsweise roles.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function correct(
        int $accountingEntryId,
        array $actor,
        array $changes,
        int $expectedRowVersion,
        string $changeReason,
        bool $confirmed
    ): array {
        $actorId = $this->requireAdmin($actor);

        if ($accountingEntryId <= 0) {
            throw new RuntimeException('Ungueltige Buchungs-ID.');
        }

        if ($expectedRowVersion < 0) {
            throw new RuntimeException('Ungueltige Zeilenversion.');
        }

        if (!$confirmed) {
            throw new RuntimeException(
                'Die Synchronisationswarnung muss ausdruecklich '
                . 'bestaetigt werden.'
            );
        }

        $changeReason = $this->validateReason($changeReason);
        $normalizedChanges = $this->normalizeChanges($changes);

        if ($normalizedChanges === []) {
            throw new RuntimeException('Es wurden keine Aenderungen angegeben.');
        }

        if ($this->pdo->inTransaction()) {
            throw new RuntimeException(
                'D1-Korrektur kann nicht in einer bestehenden Transaktion '
                . 'gestartet werden.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $oldEntry = $this->lockAccountingEntry($accountingEntryId);

            if ($oldEntry === null) {
                throw new RuntimeException('Buchungseintrag nicht gefunden.');
            }

            $this->assertExported($oldEntry);

            if ((int)$oldEntry['row_version'] !== $expectedRowVersion) {
                throw new RuntimeException(
                    'Der Flug wurde zwischenzeitlich veraendert. '
                    . 'Bitte neu laden und die Korrektur erneut pruefen.'
                );
            }

            $operationId = (int)($oldEntry['operation_id'] ?? 0);
            $export = $this->lockLatestSuccessfulExport(
                $accountingEntryId
            );

            if ($export === null) {
                if ($this->hasReconciliationCase($accountingEntryId)) {
                    throw new RuntimeException(
                        'Fuer diesen Flug besteht ein Abstimmungsfall. '
                        . 'Die lokale Korrektur ist bis zur Klaerung gesperrt.'
                    );
                }

                throw new RuntimeException(
                    'Der lokale Exportstatus ist inkonsistent: '
                    . 'Es fehlt ein erfolgreicher VF-Export.'
                );
            }

            if ($operationId <= 0 || !$this->operationExists($operationId)) {
                throw new RuntimeException(
                    'Die zugehoerige lokale Operation fehlt.'
                );
            }

            $historicalPayload = $this->comparator->decodePayloadJson(
                (string)$export['payload_json']
            );
            $oldLocalData = $this->localSnapshot($oldEntry);
            $newRowVersion = $expectedRowVersion + 1;

            $this->updateEntry(
                $accountingEntryId,
                $normalizedChanges,
                $actorId,
                $changeReason,
                $newRowVersion
            );

            $newEntry = $this->loadAccountingEntry($accountingEntryId);

            if ($newEntry === null) {
                throw new RuntimeException(
                    'Der korrigierte Buchungseintrag konnte nicht geladen werden.'
                );
            }

            $newLocalData = $this->localSnapshot($newEntry);
            $analysis = $this->analyseCurrentState(
                $newEntry,
                $historicalPayload
            );

            $this->updateSyncStatus(
                $accountingEntryId,
                $analysis['status']
            );

            $checkId = $this->insertChangeCheck(
                $newEntry,
                $export,
                $historicalPayload,
                $analysis,
                $actorId
            );

            $auditId = $this->insertAudit(
                $newEntry,
                $export,
                $actorId,
                $changeReason,
                $expectedRowVersion,
                $newRowVersion,
                $oldLocalData,
                $newLocalData,
                $historicalPayload,
                $analysis
            );

            $this->pdo->commit();

            return [
                'ok' => true,
                'accounting_entry_id' => $accountingEntryId,
                'operation_id' => $operationId,
                'vf_flight_export_id' => (int)$export['id'],
                'vf_flid' => (string)$export['vf_flid'],
                'row_version' => $newRowVersion,
                'audit_id' => $auditId,
                'check_id' => $checkId,
                'sync_status' => $analysis['status'],
                'difference_count' => $analysis['difference_count'],
                'highest_risk' => $analysis['highest_risk'],
                'differences' => $analysis['differences'],
                'person_resolution' => $analysis['person_resolution'],
                'special_case' => $analysis['special_case'],
                'error_message' => $analysis['error_message'],
                'vf_was_modified' => false,
                'message' => $analysis['status'] === self::STATUS_IN_SYNC
                    ? 'Korrektur gespeichert. Der aktuelle Payload '
                        . 'entspricht wieder dem historischen Export.'
                    : 'Korrektur gespeichert. Vereinsflieger wurde nicht '
                        . 'geaendert; Synchronisationsbedarf besteht.',
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Liefert die fuer die Admin-Korrektur erforderlichen Ausgangsdaten.
     * Diese Methode ist lesend und aendert keine Daten.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function loadForCorrection(
        int $accountingEntryId,
        array $actor
    ): array {
        $this->requireAdmin($actor);

        if ($accountingEntryId <= 0) {
            throw new RuntimeException('Ungueltige Buchungs-ID.');
        }

        $entry = $this->loadAccountingEntry($accountingEntryId);

        if ($entry === null) {
            throw new RuntimeException('Buchungseintrag nicht gefunden.');
        }

        $this->assertExported($entry);
        $export = $this->loadLatestSuccessfulExport($accountingEntryId);

        if ($export === null) {
            throw new RuntimeException(
                'Der lokale Exportstatus ist inkonsistent: '
                . 'Es fehlt ein erfolgreicher VF-Export.'
            );
        }

        return [
            'accounting_entry_id' => $accountingEntryId,
            'operation_id' => (int)$entry['operation_id'],
            'row_version' => (int)$entry['row_version'],
            'vf_flight_export_id' => (int)$export['id'],
            'vf_flid' => (string)$export['vf_flid'],
            'vf_exported_at' => $entry['vf_exported_at'],
            'succeeded_at' => $export['succeeded_at'],
            'sync_status' => $entry['vf_sync_status'],
            'last_change_reason' => $entry['vf_local_change_reason'],
            'last_local_change_at' => $entry['vf_local_changed_at'],
            'editable_fields' => array_values(array_intersect(
                array_keys(self::EDITABLE_FIELDS),
                $this->accountingColumns()
            )),
            'entry' => $this->localSnapshot($entry),
            'warning' => 'Eine lokale Aenderung kann LSZJ und '
                . 'Vereinsflieger auseinanderbringen. D1 aendert '
                . 'Vereinsflieger nicht.',
        ];
    }

    /** @param array<string,mixed> $actor */
    private function requireAdmin(array $actor): int
    {
        $actorId = filter_var(
            $actor['id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($actorId === false || (int)$actorId <= 0) {
            throw new RuntimeException('Authentifizierter Benutzer fehlt.');
        }

        $roles = [];

        if (isset($actor['role'])) {
            $roles[] = strtoupper(trim((string)$actor['role']));
        }

        if (isset($actor['roles']) && is_array($actor['roles'])) {
            foreach ($actor['roles'] as $role) {
                $roles[] = strtoupper(trim((string)$role));
            }
        }

        if (!in_array('ADMIN', array_unique($roles), true)) {
            throw new RuntimeException(
                'Dieser Flug wurde bereits nach Vereinsflieger exportiert. '
                . 'Nachtraegliche Aenderungen duerfen nur durch einen '
                . 'Administrator vorgenommen werden.'
            );
        }

        return (int)$actorId;
    }

    private function validateReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason);
        $length = function_exists('mb_strlen')
            ? mb_strlen($reason, 'UTF-8')
            : strlen($reason);

        if ($length < 5 || $length > 1000) {
            throw new RuntimeException(
                'Der Korrekturgrund muss zwischen 5 und 1000 Zeichen lang sein.'
            );
        }

        if (str_contains($reason, "\0")) {
            throw new RuntimeException(
                'Der Korrekturgrund enthaelt ein ungueltiges Zeichen.'
            );
        }

        return $reason;
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function normalizeChanges(array $changes): array
    {
        $columns = array_flip($this->accountingColumns());
        $normalized = [];

        foreach ($changes as $field => $value) {
            $field = (string)$field;

            if (!array_key_exists($field, self::EDITABLE_FIELDS)) {
                throw new RuntimeException(
                    'Feld darf in D1 nicht geaendert werden: ' . $field
                );
            }

            if (!isset($columns[$field])) {
                throw new RuntimeException(
                    'Feld existiert in dieser Installation nicht: ' . $field
                );
            }

            $normalized[$field] = $this->normalizeField(
                self::EDITABLE_FIELDS[$field],
                $value,
                $field
            );
        }

        return $normalized;
    }

    private function normalizeField(
        string $type,
        mixed $value,
        string $field
    ): mixed {
        if ($type === 'text' || $type === 'nullable_text') {
            if (is_array($value) || is_object($value)) {
                throw new RuntimeException('Ungueltiger Textwert: ' . $field);
            }

            $text = trim((string)$value);

            if ($type === 'text' && $text === '') {
                throw new RuntimeException('Pflichtfeld fehlt: ' . $field);
            }

            return $text === '' ? null : $text;
        }

        if ($type === 'required_datetime' || $type === 'nullable_datetime') {
            $text = trim((string)$value);

            if ($text === '' && $type === 'nullable_datetime') {
                return null;
            }

            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $text
            );

            if ($date === false) {
                $date = \DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $text
                );
            }

            if ($date === false) {
                throw new RuntimeException(
                    'Ungueltiger Datums-/Zeitwert: ' . $field
                );
            }

            return $date->format('Y-m-d H:i:s');
        }

        if ($type === 'boolean_int') {
            if ($value === true || $value === 1 || $value === '1') {
                return 1;
            }
            if ($value === false || $value === 0 || $value === '0') {
                return 0;
            }
            throw new RuntimeException('Ungueltiger Wahrheitswert: ' . $field);
        }

        if ($type === 'positive_int') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false
                || (int)$value <= 0) {
                throw new RuntimeException(
                    'Wert muss groesser als 0 sein: ' . $field
                );
            }
            return (int)$value;
        }

        if ($type === 'nullable_non_negative_int') {
            if ($value === null || trim((string)$value) === '') {
                return null;
            }
            if (filter_var($value, FILTER_VALIDATE_INT) === false
                || (int)$value < 0) {
                throw new RuntimeException(
                    'Wert darf nicht negativ sein: ' . $field
                );
            }
            return (int)$value;
        }

        if ($type === 'nullable_non_negative_number') {
            if ($value === null || trim((string)$value) === '') {
                return null;
            }
            if (!is_numeric($value) || (float)$value < 0) {
                throw new RuntimeException(
                    'Zahlenwert darf nicht negativ sein: ' . $field
                );
            }
            return (string)$value;
        }

        throw new RuntimeException('Unbekannter Feldtyp: ' . $type);
    }

    /** @return list<string> */
    private function accountingColumns(): array
    {
        if ($this->existingAccountingColumns !== null) {
            return $this->existingAccountingColumns;
        }

        $statement = $this->pdo->query('SHOW COLUMNS FROM accounting_entries');
        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = (string)$row['Field'];
        }

        $this->existingAccountingColumns = $columns;

        return $columns;
    }

    /** @return array<string,mixed>|null */
    private function lockAccountingEntry(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM accounting_entries WHERE id = ? FOR UPDATE'
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

    /** @return array<string,mixed>|null */
    private function lockLatestSuccessfulExport(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM vf_flight_exports
             WHERE accounting_entry_id = ?
               AND status = 'success'
               AND vf_flid IS NOT NULL
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute([$entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function loadLatestSuccessfulExport(int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM vf_flight_exports
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

    private function hasReconciliationCase(int $entryId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM vf_flight_exports
             WHERE accounting_entry_id = ?
               AND status = 'reconciliation_required'
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute([$entryId]);

        return (bool)$statement->fetchColumn();
    }

    private function operationExists(int $operationId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM operations WHERE id = ? LIMIT 1'
        );
        $statement->execute([$operationId]);

        return (bool)$statement->fetchColumn();
    }

    private function assertExported(array $entry): void
    {
        $isExported = (string)($entry['approval_status'] ?? '') === 'exported'
            || $entry['vf_exported_at'] !== null;

        if (!$isExported) {
            throw new RuntimeException(
                'Dieser Flug wurde noch nicht nach Vereinsflieger exportiert.'
            );
        }
    }

    /** @param array<string,mixed> $changes */
    private function updateEntry(
        int $entryId,
        array $changes,
        int $actorId,
        string $reason,
        int $newRowVersion
    ): void {
        $assignments = [];
        $parameters = [];

        foreach ($changes as $field => $value) {
            $assignments[] = '`' . $field . '` = ?';
            $parameters[] = $value;
        }

        $assignments[] = "vf_sync_status = 'local_change_pending'";
        $assignments[] = 'vf_sync_checked_at = NULL';
        $assignments[] = 'vf_local_changed_at = NOW()';
        $assignments[] = 'vf_local_changed_by = ?';
        $parameters[] = $actorId;
        $assignments[] = 'vf_local_change_reason = ?';
        $parameters[] = $reason;
        $assignments[] = 'row_version = ?';
        $parameters[] = $newRowVersion;
        $parameters[] = $entryId;

        $statement = $this->pdo->prepare(
            'UPDATE accounting_entries SET '
            . implode(', ', $assignments)
            . ' WHERE id = ?'
        );
        $statement->execute($parameters);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'Die lokale Korrektur konnte nicht gespeichert werden.'
            );
        }
    }

    private function updateSyncStatus(int $entryId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE accounting_entries
             SET vf_sync_status = ?, vf_sync_checked_at = NOW()
             WHERE id = ?'
        );
        $statement->execute([$status, $entryId]);
    }

    /**
     * @return array{
     *   status:string,
     *   current_payload:?array<string,mixed>,
     *   current_hash:?string,
     *   difference_count:int,
     *   highest_risk:?string,
     *   differences:?array<mixed>,
     *   person_resolution:?array<string,mixed>,
     *   special_case:?array<string,mixed>,
     *   error_message:?string
     * }
     */
    private function analyseCurrentState(
        array $entry,
        array $historicalPayload
    ): array {
        $towEntry = $this->loadTowEntry($entry);
        $resolution = $this->resolvePeople($entry, $towEntry);

        if ($resolution['issues'] !== []) {
            return [
                'status' => self::STATUS_PERSON_BLOCKED,
                'current_payload' => null,
                'current_hash' => null,
                'difference_count' => 0,
                'highest_risk' => null,
                'differences' => null,
                'person_resolution' => $resolution['details'],
                'special_case' => null,
                'error_message' => $this->limit(
                    implode(' ', $resolution['issues'])
                ),
            ];
        }

        try {
            $currentPayload = FlightPayloadBuilder::build(
                $resolution['entry'],
                $resolution['tow_entry']
            );
            $comparison = $this->comparator->compare(
                $historicalPayload,
                $currentPayload
            );
        } catch (Throwable $error) {
            return [
                'status' => self::STATUS_PAYLOAD_BLOCKED,
                'current_payload' => null,
                'current_hash' => null,
                'difference_count' => 0,
                'highest_risk' => null,
                'differences' => null,
                'person_resolution' => $resolution['details'],
                'special_case' => null,
                'error_message' => $this->limit($error->getMessage()),
            ];
        }

        $specialCase = $this->specialCaseGuard !== null
            ? $this->specialCaseGuard->inspect([
                'entry_type' => (string)($entry['entry_type'] ?? ''),
                'payload' => $currentPayload,
                'person_resolution' => $resolution['details'],
            ])
            : null;

        return [
            'status' => (string)$comparison['status'],
            'current_payload' => $comparison['current_payload'],
            'current_hash' => (string)$comparison['current_hash'],
            'difference_count' => (int)$comparison['difference_count'],
            'highest_risk' => $comparison['highest_risk'],
            'differences' => $comparison['differences'],
            'person_resolution' => $resolution['details'],
            'special_case' => $specialCase,
            'error_message' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadTowEntry(array $entry): ?array
    {
        if ((string)($entry['entry_type'] ?? '') !== 'glider_flight') {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT * FROM accounting_entries
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
        } elseif ($entryType === 'glider_flight') {
            $resolvedEntry['pilot_name'] = $pilot['display_name'];
            $resolvedEntry['pilot_vf_user_no'] = $pilot['vf_user_no'];
        } else {
            $resolvedEntry['tow_pilot_name'] = $pilot['display_name'];
            $resolvedEntry['tow_pilot_vf_user_no'] = $pilot['vf_user_no'];
            $resolvedEntry['pilot_name'] = $pilot['display_name'];
            $resolvedEntry['pilot_vf_user_no'] = $pilot['vf_user_no'];
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
                $resolvedEntry['tow_pilot_name'] = $towPilot['display_name'];
                $resolvedEntry['tow_pilot_vf_user_no'] =
                    $towPilot['vf_user_no'];

                if ($resolvedTow !== null) {
                    $resolvedTow['tow_pilot_name'] =
                        $towPilot['display_name'];
                    $resolvedTow['pilot_name'] = $towPilot['display_name'];
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

    /** @return array<string,mixed> */
    private function localSnapshot(array $entry): array
    {
        $snapshot = $entry;
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function insertChangeCheck(
        array $entry,
        array $export,
        array $historicalPayload,
        array $analysis,
        int $actorId
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO vf_flight_export_change_checks (
                accounting_entry_id, operation_id, vf_flight_export_id,
                vf_flid, check_status, old_payload_hash,
                current_payload_hash, difference_count, highest_risk,
                differences_json, current_payload_json,
                person_resolution_json, special_case_json,
                error_message, checked_by, checked_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $statement->execute([
            (int)$entry['id'],
            (int)$entry['operation_id'],
            (int)$export['id'],
            (string)$export['vf_flid'],
            $analysis['status'],
            $this->comparator->hash($historicalPayload),
            $analysis['current_hash'],
            $analysis['difference_count'],
            $analysis['highest_risk'],
            $this->encodeNullableJson($analysis['differences']),
            $this->encodeNullableJson($analysis['current_payload']),
            $this->encodeNullableJson($analysis['person_resolution']),
            $this->encodeNullableJson($analysis['special_case']),
            $analysis['error_message'],
            $actorId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertAudit(
        array $newEntry,
        array $export,
        int $actorId,
        string $reason,
        int $oldRowVersion,
        int $newRowVersion,
        array $oldLocalData,
        array $newLocalData,
        array $historicalPayload,
        array $analysis
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO vf_flight_local_changes (
                accounting_entry_id, operation_id, vf_flight_export_id,
                vf_flid, changed_by, change_reason, old_row_version,
                new_row_version, old_local_data_json, new_local_data_json,
                old_payload_json, new_payload_json, differences_json,
                sync_status, changed_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $statement->execute([
            (int)$newEntry['id'],
            (int)$newEntry['operation_id'],
            (int)$export['id'],
            (string)$export['vf_flid'],
            $actorId,
            $reason,
            $oldRowVersion,
            $newRowVersion,
            $this->encodeJson($oldLocalData),
            $this->encodeJson($newLocalData),
            $this->encodeJson($historicalPayload),
            $this->encodeNullableJson($analysis['current_payload']),
            $this->encodeNullableJson($analysis['differences']),
            $analysis['status'],
        ]);

        return (int)$this->pdo->lastInsertId();
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

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Auditdaten konnten nicht serialisiert werden: '
                . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private function encodeNullableJson(mixed $value): ?string
    {
        return $value === null ? null : $this->encodeJson($value);
    }

    private function limit(string $value): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, 1000, 'UTF-8')
            : substr($value, 0, 1000);
    }
}
