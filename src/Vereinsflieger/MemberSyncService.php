<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use PDO;
use RuntimeException;

final class MemberSyncService
{
    private const COMPARE_FIELDS = [
        'vf_member_no',
        'display_name',
        'email',
        'mobile',
        'membership_status',
        'cost_level',
        'sectors_json',
        'can_fly_glider',
        'can_fly_motor',
    ];

    public function __construct(
        private PDO $pdo,
        private RestClient $client
    ) {
    }

    public function preview(): array
    {
        $plan = $this->buildPlan();

        return [
            'ok' => true,
            'rows_received' => $plan['rows_received'],
            'new_count' => count($plan['new']),
            'changed_count' => count($plan['changed']),
            'unchanged_count' => count($plan['unchanged']),
            'uid_change_count' => count($plan['uid_changes']),
            'local_only_count' => count($plan['local_only']),
            'identity_conflict_count' => count($plan['conflicts']),
            'would_deactivate' => 0,
            'deactivation_disabled' => true,
            'import_allowed' => $plan['conflicts'] === [],
            'warnings' => $plan['warnings'],
            'new_sample' => array_slice($plan['new'], 0, 20),
            'changed_sample' => array_slice($plan['changed'], 0, 20),
            'uid_change_sample' => array_slice($plan['uid_changes'], 0, 20),
            'local_only_sample' => array_slice($plan['local_only'], 0, 20),
            'identity_conflicts' => $plan['conflicts'],
        ];
    }

    public function execute(bool $confirmed): array
    {
        if (!$confirmed) {
            throw new RuntimeException(
                'Der sichere Import muss ausdrücklich bestätigt werden.'
            );
        }

        $plan = $this->buildPlan();

        if ($plan['conflicts'] !== []) {
            throw new RuntimeException(
                'Der Import ist wegen Identitätskonflikten gesperrt. '
                . 'Bitte zuerst die Vorschau prüfen.'
            );
        }

        $inserted = 0;
        $updated = 0;
        $uidChanged = 0;
        $unchanged = count($plan['unchanged']);

        $this->pdo->beginTransaction();

        try {
            $insertStatement = $this->pdo->prepare(
                'INSERT INTO pilots_master '
                . '(vf_user_no, vf_member_no, display_name, search_name, '
                . 'email, mobile, membership_status, cost_level, sectors_json, '
                . 'can_fly_glider, can_fly_motor, priority_group, is_primary, is_selectable, is_active, '
                . 'source_hash, imported_at, vf_person_synced_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1, ?, NOW(), NOW())'
            );

            $updateStatement = $this->pdo->prepare(
                'UPDATE pilots_master SET '
                . 'vf_user_no = ?, vf_member_no = ?, display_name = ?, '
                . 'search_name = ?, email = ?, mobile = ?, '
                . 'membership_status = ?, cost_level = ?, sectors_json = ?, '
                . 'can_fly_glider = ?, can_fly_motor = ?, priority_group = ?, '
                . 'is_selectable = 1, is_active = 1, source_hash = ?, '
                . 'imported_at = NOW(), vf_person_synced_at = NOW() '
                . 'WHERE id = ?'
            );

            /*
             * UID-Wechsel zuerst verarbeiten. Dadurch wird die bestehende
             * Person anhand der eindeutigen Mitgliedsnummer aktualisiert,
             * statt einen kollidierenden zweiten Datensatz anzulegen.
             */
            foreach ($plan['uid_changes'] as $item) {
                $this->writeUpdate(
                    $updateStatement,
                    $item['local_id'],
                    $item['new_vf_user_no'],
                    $item['final']
                );
                $uidChanged++;
            }

            foreach ($plan['changed'] as $item) {
                $this->writeUpdate(
                    $updateStatement,
                    $item['local_id'],
                    $item['vf_user_no'],
                    $item['final']
                );
                $updated++;
            }

            foreach ($plan['new'] as $item) {
                $final = $item['final'];
                $insertStatement->execute([
                    $item['vf_user_no'],
                    $this->nullIfEmpty($final['vf_member_no']),
                    $final['display_name'],
                    $this->searchName($final['display_name']),
                    $this->nullIfEmpty($final['email']),
                    $this->nullIfEmpty($final['mobile']),
                    $this->nullIfEmpty($final['membership_status']),
                    $this->nullIfEmpty($final['cost_level']),
                    $final['sectors_json'],
                    (int)$final['can_fly_glider'],
                    (int)$final['can_fly_motor'],
                    $this->priorityGroup($final['cost_level']),
                    $this->sourceHash($final),
                ]);
                $inserted++;
            }

            /*
             * Sicherheitsregel: Datensätze, die VF nicht liefert,
             * werden weder deaktiviert noch gelöscht.
             */
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'uid_changed' => $uidChanged,
            'unchanged' => $unchanged,
            'deactivated' => 0,
            'warnings' => $plan['warnings'],
        ];
    }

    private function buildPlan(): array
    {
        [$incoming, $warnings] = $this->incomingByUid();
        [$byUid, $byMemberNo, $localConflicts] = $this->currentIndexes();

        $new = [];
        $changed = [];
        $unchanged = [];
        $uidChanges = [];
        $conflicts = $localConflicts;
        $matchedLocalIds = [];
        $seenIncomingMemberNos = [];

        foreach ($incoming as $uid => $person) {
            $memberNo = $this->normalized($person['vf_member_no'] ?? '');

            if ($memberNo !== '') {
                if (
                    isset($seenIncomingMemberNos[$memberNo])
                    && $seenIncomingMemberNos[$memberNo] !== $uid
                ) {
                    $conflicts[] = [
                        'type' => 'duplicate_incoming_member_no',
                        'vf_member_no' => $memberNo,
                        'first_vf_user_no' => $seenIncomingMemberNos[$memberNo],
                        'second_vf_user_no' => $uid,
                        'vf_name' => $person['display_name'],
                    ];
                    continue;
                }

                $seenIncomingMemberNos[$memberNo] = $uid;
            }

            if (isset($byUid[$uid])) {
                $local = $byUid[$uid];
                $matchedLocalIds[(int)$local['id']] = true;

                if (
                    $memberNo !== ''
                    && isset($byMemberNo[$memberNo])
                    && (int)$byMemberNo[$memberNo]['id'] !== (int)$local['id']
                ) {
                    $conflicts[] = $this->conflict(
                        'uid_and_member_no_point_to_different_people',
                        $person,
                        $local,
                        $byMemberNo[$memberNo]
                    );
                    continue;
                }

                $final = $this->finalValues($local, $person);
                $differences = $this->differences($local, $final);

                if ($differences === []) {
                    $unchanged[] = $this->summary($person);
                } else {
                    $changed[] = [
                        'local_id' => (int)$local['id'],
                        'vf_user_no' => (string)$uid,
                        'display_name' => $person['display_name'],
                        'differences' => $differences,
                        'final' => $final,
                    ];
                }

                continue;
            }

            if ($memberNo !== '' && isset($byMemberNo[$memberNo])) {
                $local = $byMemberNo[$memberNo];
                $matchedLocalIds[(int)$local['id']] = true;

                if (isset($byUid[$uid])) {
                    $conflicts[] = $this->conflict(
                        'new_uid_already_in_use',
                        $person,
                        $local,
                        $byUid[$uid]
                    );
                    continue;
                }

                $final = $this->finalValues($local, $person);
                $uidChanges[] = [
                    'local_id' => (int)$local['id'],
                    'vf_member_no' => $memberNo,
                    'old_vf_user_no' => (string)$local['vf_user_no'],
                    'new_vf_user_no' => (string)$uid,
                    'local_name' => (string)$local['display_name'],
                    'vf_name' => (string)$person['display_name'],
                    'differences' => $this->differences($local, $final),
                    'final' => $final,
                ];
                continue;
            }

            $new[] = [
                'vf_user_no' => (string)$uid,
                'vf_member_no' => $memberNo,
                'display_name' => $person['display_name'],
                'membership_status' => $person['membership_status'],
                'final' => $this->finalValues([], $person),
            ];
        }

        $localOnly = [];
        foreach ($byUid as $local) {
            if (!isset($matchedLocalIds[(int)$local['id']])) {
                $localOnly[] = $this->summary($local);
            }
        }

        return [
            'rows_received' => count($incoming),
            'new' => $new,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'uid_changes' => $uidChanges,
            'local_only' => $localOnly,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    private function incomingByUid(): array
    {
        $adapted = MemberAdapter::adapt($this->client->listUsers());

        if ($adapted['rows'] === []) {
            throw new RuntimeException(
                'VF lieferte keine importierbaren Personen. '
                . 'Es wurden keine Daten geändert.'
            );
        }

        $rows = [];
        $warnings = $adapted['warnings'];

        foreach ($adapted['rows'] as $person) {
            $uid = $this->normalized($person['vf_user_no']);

            if (isset($rows[$uid])) {
                $warnings[] = "Doppelte VF-UID in der API-Antwort: {$uid}";
                continue;
            }

            $rows[$uid] = $person;
        }

        return [$rows, $warnings];
    }

    private function currentIndexes(): array
    {
        $sql = 'SELECT id, vf_user_no, vf_member_no, display_name, '
            . 'email, mobile, membership_status, cost_level, sectors_json, can_fly_glider, can_fly_motor, is_active '
            . 'FROM pilots_master';

        $byUid = [];
        $byMemberNo = [];
        $conflicts = [];

        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = $this->normalized($row['vf_user_no']);
            $memberNo = $this->normalized($row['vf_member_no'] ?? '');
            $byUid[$uid] = $row;

            if ($memberNo === '') {
                continue;
            }

            if (isset($byMemberNo[$memberNo])) {
                $conflicts[] = [
                    'type' => 'duplicate_local_member_no',
                    'vf_member_no' => $memberNo,
                    'first_vf_user_no' => (string)$byMemberNo[$memberNo]['vf_user_no'],
                    'second_vf_user_no' => $uid,
                ];
                continue;
            }

            $byMemberNo[$memberNo] = $row;
        }

        return [$byUid, $byMemberNo, $conflicts];
    }

    private function conflict(
        string $type,
        array $incoming,
        array $localByUid,
        array $localByMemberNo
    ): array {
        return [
            'type' => $type,
            'vf_user_no' => (string)$incoming['vf_user_no'],
            'vf_member_no' => (string)$incoming['vf_member_no'],
            'vf_name' => (string)$incoming['display_name'],
            'local_uid_name' => (string)$localByUid['display_name'],
            'local_uid_vf_user_no' => (string)$localByUid['vf_user_no'],
            'local_member_name' => (string)$localByMemberNo['display_name'],
            'local_member_vf_user_no' => (string)$localByMemberNo['vf_user_no'],
        ];
    }

    private function differences(array $current, array $final): array
    {
        $differences = [];

        foreach (self::COMPARE_FIELDS as $field) {
            $old = $this->normalized($current[$field] ?? '');
            $new = $this->normalized($final[$field] ?? '');

            if ($old !== $new) {
                $differences[] = [
                    'field' => $field,
                    'local' => $old,
                    'vf' => $new,
                ];
            }
        }

        if ((int)($current['is_active'] ?? 1) !== 1) {
            $differences[] = [
                'field' => 'is_active',
                'local' => '0',
                'vf' => '1',
            ];
        }

        return $differences;
    }

    private function finalValues(array $current, array $incoming): array
    {
        $final = [];

        $capabilities = PilotSectorPolicy::capabilities($incoming['sectors'] ?? [], true);
        $incoming['sectors_json'] = $capabilities['sectors_json'];
        $incoming['can_fly_glider'] = (string)$capabilities['can_fly_glider'];
        $incoming['can_fly_motor'] = (string)$capabilities['can_fly_motor'];
        foreach (self::COMPARE_FIELDS as $field) {
            $old = $this->normalized($current[$field] ?? '');
            $new = $this->normalized($incoming[$field] ?? '');

            /*
             * Leere optionale VF-Werte überschreiben keine bestehenden
             * lokalen Werte. Der Anzeigename muss hingegen vorhanden sein.
             */
            $final[$field] = (
                $field !== 'display_name'
                && $new === ''
                && $old !== ''
            ) ? $old : $new;
        }

        return $final;
    }

    private function writeUpdate(
        \PDOStatement $statement,
        int $localId,
        string|int $vfUserNo,
        array $final
    ): void {
        $statement->execute([
            $vfUserNo,
            $this->nullIfEmpty($final['vf_member_no']),
            $final['display_name'],
            $this->searchName($final['display_name']),
            $this->nullIfEmpty($final['email']),
            $this->nullIfEmpty($final['mobile']),
            $this->nullIfEmpty($final['membership_status']),
            $this->nullIfEmpty($final['cost_level']),
            $final['sectors_json'],
            (int)$final['can_fly_glider'],
            (int)$final['can_fly_motor'],
            $this->priorityGroup($final['cost_level']),
            $this->sourceHash($final),
            $localId,
        ]);
    }

    private function summary(array $person): array
    {
        return [
            'vf_user_no' => (string)($person['vf_user_no'] ?? ''),
            'vf_member_no' => (string)($person['vf_member_no'] ?? ''),
            'display_name' => (string)($person['display_name'] ?? ''),
            'membership_status' => (string)($person['membership_status'] ?? ''),
        ];
    }

    private function normalized(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string)$value))
            ?? trim((string)$value);
    }

    private function searchName(string $displayName): string
    {
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($displayName, 'UTF-8')
            : strtolower($displayName);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    }

    private function priorityGroup(string $costLevel): string
    {
        return match ($this->normalized($costLevel)) {
            'Fliegendes Mitglied' => 'flying_member',
            'Flugschüler' => 'student',
            'GVVC Mitglied' => 'gvvc',
            default => 'other',
        };
    }

    private function sourceHash(array $person): string
    {
        return hash(
            'sha256',
            json_encode(
                $person,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
