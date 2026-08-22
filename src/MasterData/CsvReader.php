<?php
declare(strict_types=1);

namespace LSZJ\MasterData;

use RuntimeException;

final class CsvReader
{
    /** @return array<int,array<string,string>> */
    public static function read(string $path, array $requiredHeaders): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("CSV-Datei nicht lesbar: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("CSV-Datei konnte nicht geoeffnet werden: {$path}");
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('CSV-Datei ist leer.');
            }

            // UTF-8-BOM vor dem Parsen sicher entfernen.
            $firstLine = self::stripBom($firstLine);
            $delimiter = self::detectDelimiter($firstLine);
            $header = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '\\');
            $header = array_map([self::class, 'normalizeHeader'], $header);

            foreach ($requiredHeaders as $required) {
                if (!in_array($required, $header, true)) {
                    throw new RuntimeException(
                        'Pflichtspalte fehlt: ' . $required .
                        '. Gefundene Spalten: ' . implode(', ', $header)
                    );
                }
            }

            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $name) {
                    if ($name !== '') {
                        $row[$name] = trim((string)($values[$index] ?? ''));
                    }
                }

                if (array_filter($row, static fn(string $value): bool => $value !== '')) {
                    $rows[$line] = $row;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function stripBom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }
        return $value;
    }

    private static function normalizeHeader(mixed $value): string
    {
        $value = self::stripBom(trim((string)$value));
        // Entfernt auch ein eventuell bereits als Unicode-Zeichen dekodiertes BOM/ZWNBSP.
        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }

    private static function detectDelimiter(string $firstLine): string
    {
        $counts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        if (($counts[$delimiter] ?? 0) === 0) {
            throw new RuntimeException('CSV-Trennzeichen konnte nicht erkannt werden.');
        }
        return $delimiter;
    }
}
