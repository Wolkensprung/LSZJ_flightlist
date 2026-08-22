<?php
declare(strict_types=1);

namespace LSZJ\MasterData;

use PDO;
use RuntimeException;
use Throwable;

final class VereinsfliegerCsvImporter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function importMembers(string $path, string $sourceFilename = 'Mitglieder.csv'): array
    {
        $required = ['MitgliedsNr','Name','Mailadresse','Mobil (privat)','Mitgliedsstatus','Kostenstufe','Benutzernummer'];
        $rows = CsvReader::read($path, $required);
        $stmt = $this->pdo->prepare(
            "INSERT INTO pilots_master
                (vf_user_no, vf_member_no, display_name, search_name, email, mobile,
                 membership_status, cost_level, priority_group, is_primary, is_active,
                 source_hash, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE
                vf_member_no = VALUES(vf_member_no),
                display_name = VALUES(display_name),
                search_name = VALUES(search_name),
                email = VALUES(email),
                mobile = VALUES(mobile),
                membership_status = VALUES(membership_status),
                cost_level = VALUES(cost_level),
                priority_group = VALUES(priority_group),
                is_primary = VALUES(is_primary),
                is_active = 1,
                source_hash = VALUES(source_hash),
                imported_at = NOW()"
        );

        $warnings = [];
        $seen = [];
        $imported = 0;
        $skipped = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $line => $row) {
                $userNo = TextNormalizer::clean($row['Benutzernummer'] ?? '');
                $memberNo = TextNormalizer::clean($row['MitgliedsNr'] ?? '');
                $name = TextNormalizer::clean($row['Name'] ?? '');
                $emailRaw = TextNormalizer::clean($row['Mailadresse'] ?? '');
                $email = TextNormalizer::email($emailRaw);
                $mobile = TextNormalizer::nullable($row['Mobil (privat)'] ?? '');
                $status = TextNormalizer::clean($row['Mitgliedsstatus'] ?? '');
                $costLevel = TextNormalizer::clean($row['Kostenstufe'] ?? '');

                // Exakt dieser technische Vereinsdatensatz ist keine abrechenbare Person.
                // Alle anderen Datensaetze bleiben erhalten, auch Organisationen und Externe.
                if (
                    $memberNo === '0'
                    && $userNo === '306375'
                    && $name === 'Segelfluggruppe Biel, Segelfluggruppe Biel'
                ) {
                    $warnings[] = "Zeile {$line}: technischer Vereinsdatensatz ausgeschlossen";
                    $skipped++;
                    continue;
                }

                if ($userNo === '' || $name === '') {
                    $warnings[] = "Zeile {$line}: Benutzernummer oder Name fehlt";
                    $skipped++;
                    continue;
                }
                if ($emailRaw !== '' && $email === null) {
                    $warnings[] = "Zeile {$line}: ungueltige Mailadresse bei {$name}";
                }

                $priority = $this->priorityGroup($costLevel);
                $isPrimary = $priority !== 'other' && TextNormalizer::searchKey($status) !== 'ausgeschieden';
                $hash = hash('sha256', implode('|', [$userNo,$memberNo,$name,$emailRaw,(string)$mobile,$status,$costLevel]));

                $stmt->execute([
                    $userNo,
                    $memberNo !== '' ? $memberNo : null,
                    $name,
                    TextNormalizer::searchKey($name),
                    $email,
                    $mobile,
                    $status !== '' ? $status : null,
                    $costLevel !== '' ? $costLevel : null,
                    $priority,
                    $isPrimary ? 1 : 0,
                    $hash,
                ]);
                $seen[] = $userNo;
                $imported++;
            }

            $this->deactivateMissing('pilots_master', 'vf_user_no', $seen);
            $this->logRun('members', $sourceFilename, $path, count($rows), $imported, $skipped, $warnings);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['type'=>'members','rows_read'=>count($rows),'rows_imported'=>$imported,'rows_skipped'=>$skipped,'warnings'=>$warnings];
    }

    public function importAircraft(string $path, string $sourceFilename = 'Luftfahrzeuge.csv'): array
    {
        $required = ['Lfz.','Wkz','Luftfahrzeugart','Musterbezeichnung','Eigentümer/Halter','Vereins-LFZ'];
        $rows = CsvReader::read($path, $required);
        $warnings = [];
        $skipped = 0;
        $items = [];

        foreach ($rows as $line => $row) {
            $callsign = CallsignNormalizer::normalize($row['Lfz.'] ?? '');
            if ($callsign === '') {
                $warnings[] = "Zeile {$line}: Lfz. fehlt";
                $skipped++;
                continue;
            }
            $candidate = [
                'callsign'=>$callsign,
                'competition_code'=>TextNormalizer::clean($row['Wkz'] ?? ''),
                'aircraft_type'=>TextNormalizer::clean($row['Luftfahrzeugart'] ?? ''),
                'model_designation'=>TextNormalizer::clean($row['Musterbezeichnung'] ?? ''),
                'owner_name'=>TextNormalizer::clean($row['Eigentümer/Halter'] ?? ''),
                'is_club_aircraft'=>$this->isYes($row['Vereins-LFZ'] ?? '') ? 1 : 0,
            ];
            if (!isset($items[$callsign]) || $this->aircraftScore($candidate) > $this->aircraftScore($items[$callsign])) {
                $items[$callsign] = $candidate;
            } else {
                $items[$callsign]['is_club_aircraft'] = max($items[$callsign]['is_club_aircraft'], $candidate['is_club_aircraft']);
            }
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO aircraft_master
                (callsign, search_key, competition_code, aircraft_type, model_designation,
                 owner_name, is_club_aircraft, is_active, source_hash, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE
                search_key = VALUES(search_key),
                competition_code = VALUES(competition_code),
                aircraft_type = VALUES(aircraft_type),
                model_designation = VALUES(model_designation),
                owner_name = VALUES(owner_name),
                is_club_aircraft = VALUES(is_club_aircraft),
                is_active = 1,
                source_hash = VALUES(source_hash),
                imported_at = NOW()"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $hash = hash('sha256', implode('|', $item));
                $search = CallsignNormalizer::searchKey(implode(' ', [
                    $item['callsign'], $item['competition_code'], $item['model_designation']
                ]));
                $stmt->execute([
                    $item['callsign'],
                    $search,
                    TextNormalizer::nullable($item['competition_code']),
                    TextNormalizer::nullable($item['aircraft_type']),
                    TextNormalizer::nullable($item['model_designation']),
                    TextNormalizer::nullable($item['owner_name']),
                    $item['is_club_aircraft'],
                    $hash,
                ]);
            }
            $this->deactivateMissing('aircraft_master', 'callsign', array_keys($items));
            $this->logRun('aircraft', $sourceFilename, $path, count($rows), count($items), $skipped, $warnings);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['type'=>'aircraft','rows_read'=>count($rows),'rows_imported'=>count($items),'rows_skipped'=>$skipped,'warnings'=>$warnings];
    }

    private function priorityGroup(string $costLevel): string
    {
        return match (TextNormalizer::searchKey($costLevel)) {
            'fliegendesmitglied' => 'flying_member',
            'flugschueler' => 'student',
            'gvvcmitglied' => 'gvvc',
            default => 'other',
        };
    }

    private function isYes(mixed $value): bool
    {
        return in_array(TextNormalizer::searchKey($value), ['ja','yes','true','1'], true);
    }

    private function aircraftScore(array $item): int
    {
        return ($item['is_club_aircraft'] ? 16 : 0)
            + (TextNormalizer::searchKey($item['owner_name']) === 'segelfluggruppebiel' ? 8 : 0)
            + ($item['competition_code'] !== '' ? 4 : 0)
            + ($item['model_designation'] !== '' ? 2 : 0)
            + ($item['aircraft_type'] !== '' ? 1 : 0);
    }

    private function deactivateMissing(string $table, string $keyColumn, array $keys): void
    {
        if ($keys === []) {
            throw new RuntimeException('Keine gueltigen Datensaetze gefunden; vorhandene Stammdaten wurden nicht deaktiviert.');
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->pdo->prepare("UPDATE {$table} SET is_active = 0 WHERE {$keyColumn} NOT IN ({$placeholders})")
            ->execute(array_values($keys));
    }

    private function logRun(string $type, string $filename, string $path, int $read, int $imported, int $skipped, array $warnings): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO master_data_import_runs
                (import_type, source_filename, source_sha256, rows_read, rows_imported, rows_skipped, warnings_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $type,
            basename($filename),
            hash_file('sha256', $path),
            $read,
            $imported,
            $skipped,
            $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}

