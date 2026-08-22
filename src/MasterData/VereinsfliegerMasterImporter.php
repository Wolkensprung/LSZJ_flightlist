<?php
declare(strict_types=1);

namespace LSZJ\MasterData;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

final class VereinsfliegerMasterImporter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function importMembers(string $filePath, string $sourceFilename = 'Mitglieder.xlsx'): array
    {
        $required = ['MitgliedsNr', 'Name', 'Mitgliedsstatus', 'Kostenstufe'];
        [$headers, $rows] = $this->readSheet($filePath, $required);

        $stmt = $this->pdo->prepare(
            "INSERT INTO pilots_master
                (vf_member_no, display_name, search_name, membership_status, cost_level,
                 priority_group, is_primary, is_selectable, is_active, source_hash, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                search_name = VALUES(search_name),
                membership_status = VALUES(membership_status),
                cost_level = VALUES(cost_level),
                priority_group = VALUES(priority_group),
                is_primary = VALUES(is_primary),
                is_selectable = VALUES(is_selectable),
                is_active = 1,
                source_hash = VALUES(source_hash),
                imported_at = NOW()"
        );

        $warnings = [];
        $imported = 0;
        $skipped = 0;
        $seenIds = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $rowNumber => $row) {
                $memberNo = TextNormalizer::clean($row['MitgliedsNr'] ?? '');
                $name = TextNormalizer::clean($row['Name'] ?? '');
                $status = TextNormalizer::clean($row['Mitgliedsstatus'] ?? '');
                $costLevel = TextNormalizer::clean($row['Kostenstufe'] ?? '');

                if ($memberNo === '' || $name === '') {
                    $skipped++;
                    $warnings[] = "Zeile {$rowNumber}: MitgliedsNr oder Name fehlt";
                    continue;
                }

                $priority = $this->pilotPriorityGroup($costLevel);
                $isPerson = str_contains($name, ',');
                $isPrimary = $isPerson && $priority !== 'other';
                $hash = hash('sha256', implode('|', [$memberNo, $name, $status, $costLevel]));

                $stmt->execute([
                    $memberNo,
                    $name,
                    TextNormalizer::searchKey($name),
                    $status !== '' ? $status : null,
                    $costLevel !== '' ? $costLevel : null,
                    $priority,
                    $isPrimary ? 1 : 0,
                    $isPerson ? 1 : 0,
                    $hash,
                ]);
                $seenIds[] = $memberNo;
                $imported++;
            }

            // Nicht mehr in der aktuellen Datei vorhandene Datensaetze deaktivieren.
            $this->deactivateMissing('pilots_master', 'vf_member_no', $seenIds);
            $this->logRun('members', $sourceFilename, $filePath, count($rows), $imported, $skipped, $warnings);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['type'=>'members', 'rows_read'=>count($rows), 'rows_imported'=>$imported, 'rows_skipped'=>$skipped, 'warnings'=>$warnings];
    }

    public function importAircraft(string $filePath, string $sourceFilename = 'Luftfahrzeuge.xlsx'): array
    {
        $required = ['Lfz.', 'Wkz', 'Luftfahrzeugart', 'Musterbezeichnung', 'Eigentümer/Halter', 'Vereins-LFZ'];
        [, $rows] = $this->readSheet($filePath, $required);

        // Doppelte Callsigns (z.B. unterschiedliche Halterzeilen) vor dem Upsert konsolidieren.
        $aircraft = [];
        $warnings = [];
        $skipped = 0;
        foreach ($rows as $rowNumber => $row) {
            $callsign = CallsignNormalizer::normalize($row['Lfz.'] ?? '');
            if ($callsign === '') {
                $skipped++;
                $warnings[] = "Zeile {$rowNumber}: Lfz. fehlt";
                continue;
            }

            $candidate = [
                'callsign' => $callsign,
                'competition_code' => TextNormalizer::clean($row['Wkz'] ?? ''),
                'aircraft_type' => TextNormalizer::clean($row['Luftfahrzeugart'] ?? ''),
                'model_designation' => TextNormalizer::clean($row['Musterbezeichnung'] ?? ''),
                'owner_name' => TextNormalizer::clean($row['Eigentümer/Halter'] ?? ''),
                'is_club_aircraft' => $this->isYes($row['Vereins-LFZ'] ?? '') ? 1 : 0,
            ];

            if (!isset($aircraft[$callsign]) || $this->aircraftScore($candidate) > $this->aircraftScore($aircraft[$callsign])) {
                $aircraft[$callsign] = $candidate;
            } else {
                $aircraft[$callsign]['is_club_aircraft'] = max(
                    $aircraft[$callsign]['is_club_aircraft'],
                    $candidate['is_club_aircraft']
                );
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
            foreach ($aircraft as $item) {
                $hash = hash('sha256', implode('|', $item));
                $stmt->execute([
                    $item['callsign'],
                    CallsignNormalizer::searchKey($item['callsign'] . ' ' . $item['competition_code']),
                    $item['competition_code'] !== '' ? $item['competition_code'] : null,
                    $item['aircraft_type'] !== '' ? $item['aircraft_type'] : null,
                    $item['model_designation'] !== '' ? $item['model_designation'] : null,
                    $item['owner_name'] !== '' ? $item['owner_name'] : null,
                    $item['is_club_aircraft'],
                    $hash,
                ]);
            }

            $this->deactivateMissing('aircraft_master', 'callsign', array_keys($aircraft));
            $this->logRun('aircraft', $sourceFilename, $filePath, count($rows), count($aircraft), $skipped, $warnings);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['type'=>'aircraft', 'rows_read'=>count($rows), 'rows_imported'=>count($aircraft), 'rows_skipped'=>$skipped, 'warnings'=>$warnings];
    }

    private function readSheet(string $filePath, array $requiredHeaders): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("Datei nicht gefunden: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        if (!$matrix) {
            throw new RuntimeException('Die Excel-Datei ist leer.');
        }

        $headerRow = array_shift($matrix);
        $headers = array_map(static fn($v) => TextNormalizer::clean((string)$v), $headerRow);
        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $headers, true)) {
                throw new RuntimeException("Pflichtspalte fehlt: {$required}");
            }
        }

        $rows = [];
        foreach ($matrix as $index => $values) {
            $assoc = [];
            foreach ($headers as $column => $header) {
                if ($header !== '') {
                    $assoc[$header] = $values[$column] ?? null;
                }
            }
            if (array_filter($assoc, static fn($v) => TextNormalizer::clean((string)$v) !== '')) {
                $rows[$index + 2] = $assoc;
            }
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [$headers, $rows];
    }

    private function pilotPriorityGroup(string $costLevel): string
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
        return in_array(TextNormalizer::searchKey((string)$value), ['ja', 'yes', 'true', '1'], true);
    }

    private function aircraftScore(array $item): int
    {
        $score = 0;
        if ($item['is_club_aircraft']) $score += 8;
        if (TextNormalizer::searchKey($item['owner_name']) === 'segelfluggruppebiel') $score += 8;
        if ($item['competition_code'] !== '') $score += 4;
        if ($item['model_designation'] !== '') $score += 2;
        if ($item['aircraft_type'] !== '') $score += 1;
        return $score;
    }

    private function deactivateMissing(string $table, string $keyColumn, array $keys): void
    {
        if (!$keys) {
            throw new RuntimeException('Importdatei enthaelt keine gueltigen Datensaetze; bestehende Stammdaten wurden nicht deaktiviert.');
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "UPDATE {$table} SET is_active = 0 WHERE {$keyColumn} NOT IN ({$placeholders})";
        $this->pdo->prepare($sql)->execute(array_values($keys));
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
