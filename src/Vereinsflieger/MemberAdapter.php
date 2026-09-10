<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

final class MemberAdapter
{
    /**
     * Überführt die von Vereinsflieger gelieferten Personendatensätze
     * in das interne LSZJ-Format.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{
     *     rows: list<array<string,string>>,
     *     warnings: list<string>
     * }
     */
    public static function adapt(array $rows): array
    {
        $result = [];
        $warnings = [];

        foreach ($rows as $index => $raw) {
            $row = [];

            foreach ($raw as $key => $value) {
                $row[strtolower((string)$key)] = $value;
            }

            $uid = self::value(
                $row,
                ['uid', 'userno', 'user_no', 'benutzernummer']
            );

            $firstName = self::value(
                $row,
                ['firstname', 'first_name', 'vorname']
            );

            $lastName = self::value(
                $row,
                ['lastname', 'last_name', 'nachname']
            );

            $displayName = self::value(
                $row,
                ['name', 'displayname', 'display_name']
            );

            if ($displayName === '') {
                $displayName = trim(
                    $lastName
                    . ($lastName !== '' && $firstName !== '' ? ', ' : '')
                    . $firstName
                );
            }

            if ($uid === '' || $displayName === '') {
                $warnings[] = sprintf(
                    'VF-Datensatz %d: Benutzernummer oder Name fehlt',
                    $index + 1
                );

                continue;
            }

            $result[] = [
                'vf_user_no' => $uid,
                'vf_member_no' => self::value(
                    $row,
                    ['memberid', 'memberno', 'member_no', 'mitgliedsnr']
                ),
                'display_name' => $displayName,
                'email' => self::value(
                    $row,
                    ['email', 'mail', 'mailaddress', 'mailadresse']
                ),
                'mobile' => self::value(
                    $row,
                    ['mobile', 'mobilenumber', 'mobilephone', 'mobil']
                ),
                'membership_status' => self::value(
                    $row,
                    [
                        'memberstatus',
                        'membershipstatus',
                        'member_status',
                        'mitgliedsstatus',
                    ]
                ),
                'cost_level' => self::value(
                    $row,
                    ['costlevel', 'cost_level', 'kostenstufe']
                ),
            ];
        }

        return [
            'rows' => $result,
            'warnings' => $warnings,
        ];
    }

    /**
     * Liest den ersten vorhandenen skalaren Wert aus einer Liste
     * möglicher Feldnamen und normalisiert HTML-Entities sowie Abstände.
     *
     * Beispiele:
     * - d&amp;#39;Epagnier wird zu d&#39;Epagnier
     * - geschützte Leerzeichen werden zu normalen Leerzeichen
     * - mehrfache Leerzeichen, Tabs und Zeilenumbrüche werden vereinheitlicht
     *
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $rawValue = $row[$key];

            if ($rawValue === null || is_array($rawValue)) {
                continue;
            }

            $value = html_entity_decode(
                (string)$rawValue,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            // HTML-geschützte Leerzeichen normalisieren.
            $value = str_replace("\u{00A0}", ' ', $value);

            // Mehrfache Leerzeichen, Tabs und Zeilenumbrüche vereinheitlichen.
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

            return trim($value);
        }

        return '';
    }
}
