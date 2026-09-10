<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use DateTimeImmutable;
use RuntimeException;

final class FlightPayloadBuilder
{
    public const GVVC_FLIGHT_TYPE_ID = 21;
    public const GVVC_CHARGE_UID = '349910';

    /**
     * Erstellt den REST-Payload für Vereinsflieger flight/add.
     *
     * Zeitbehandlung:
     * Die LSZJ-Flightlist und die Vereinsflieger-Oberfläche verwenden
     * betriebliche Lokalzeit. Diese Klasse verändert die Uhrzeit deshalb
     * nicht, sondern formatiert den gespeicherten Wert nur gemäss der
     * Schnittstellenbeschreibung:
     *
     * - departuretime / arrivaltime: YYYY-mm-dd HH:ii
     * - offblock / onblock: HH:ii
     *
     * Optionale leere Felder werden vor der Rückgabe entfernt.
     * Die Strings "0" bleiben erhalten.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed>|null $towEntry
     * @return array<string,string>
     */
    public static function build(
        array $entry,
        ?array $towEntry = null
    ): array {
        $entryType = trim((string)($entry['entry_type'] ?? ''));

        if (!in_array(
            $entryType,
            ['glider_flight', 'tow_charge', 'towplane_own'],
            true
        )) {
            throw new RuntimeException(
                'Nicht unterstützter Eintragstyp: ' . $entryType
            );
        }

        $departure = self::requiredDateTime(
            $entry['departure_time'] ?? null,
            'Startzeit'
        );

        $arrival = self::resolveArrivalDateTime(
            $entry,
            $entryType
        );

        $payload = [
            'callsign' => self::resolveCallsign($entry, $entryType),
            'pilotname' => self::resolvePilotName($entry, $entryType),
            'uidpilot' => self::optionalIntegerString(
                $entry['pilot_vf_user_no']
                    ?? $entry['uid_pilot']
                    ?? null
            ),
            'attendantname' => self::text(
                $entry['attendant_name'] ?? null
            ),
            'uidattendant' => self::optionalIntegerString(
                $entry['attendant_vf_user_no']
                    ?? $entry['uid_attendant']
                    ?? null
            ),
            'attendantname2' => self::text(
                $entry['attendant_name_2'] ?? null
            ),
            'uidattendant2' => self::optionalIntegerString(
                $entry['attendant_2_vf_user_no']
                    ?? $entry['uid_attendant_2']
                    ?? null
            ),
            'attendantname3' => self::text(
                $entry['attendant_name_3'] ?? null
            ),
            'uidattendant3' => self::optionalIntegerString(
                $entry['attendant_3_vf_user_no']
                    ?? $entry['uid_attendant_3']
                    ?? null
            ),
            'starttype' => self::optionalIntegerString(
                $entry['start_type'] ?? null
            ),
            'departuretime' => self::formatDateTime($departure),
            'departurelocation' => self::text(
                $entry['departure_location'] ?? null
            ),
            'arrivaltime' => self::formatDateTime($arrival),
            'arrivallocation' => self::firstNonEmpty(
                $entry['arrival_location'] ?? null,
                $entry['tow_arrival_location'] ?? null,
                $entry['departure_location'] ?? null
            ),
            'flighttime' => self::resolveFlightMinutes(
                $entry,
                $entryType
            ),
            'landingcount' => self::positiveIntegerString(
                $entry['landing_count'] ?? 1,
                'Anzahl Landungen'
            ),
            'ftid' => self::optionalIntegerString(
                $entry['vf_flight_type_id'] ?? null
            ),
            'km' => self::optionalNonNegativeNumberString(
                $entry['km'] ?? null,
                'Strecke'
            ),
            'chargemode' => self::optionalIntegerString(
                $entry['charge_mode'] ?? null
            ),
            'uidcharge' => self::optionalIntegerString(
                $entry['uid_charge'] ?? null
            ),
            'comment' => self::text(
                $entry['comment'] ?? null
            ),
            'towcallsign' => self::firstNonEmpty(
                $entry['tow_callsign'] ?? null,
                $towEntry['tow_callsign'] ?? null,
                $towEntry['callsign'] ?? null
            ),
            'towpilotname' => self::firstNonEmpty(
                $entry['tow_pilot_name'] ?? null,
                $towEntry['tow_pilot_name'] ?? null,
                $towEntry['pilot_name'] ?? null
            ),
            'towuidpilot' => self::optionalIntegerString(
                $entry['tow_pilot_vf_user_no']
                    ?? $entry['tow_uid_pilot']
                    ?? $towEntry['tow_pilot_vf_user_no']
                    ?? $towEntry['pilot_vf_user_no']
                    ?? null
            ),
            'towtime' => self::optionalPositiveIntegerString(
                $entry['tow_minutes']
                    ?? $towEntry['tow_minutes']
                    ?? $towEntry['flight_minutes']
                    ?? null,
                'Schleppzeit'
            ),
            'towheight' => self::optionalNonNegativeNumberString(
                $entry['tow_height_m']
                    ?? $towEntry['tow_height_m']
                    ?? null,
                'Schlepphöhe'
            ),
            'offblock' => self::formatTime($departure),
            'onblock' => self::formatTime($arrival),
            'blocktime' => self::optionalPositiveIntegerString(
                $entry['block_minutes'] ?? null,
                'Blockzeit'
            ),
            'motorstart' => '',
            'motorend' => '',
            'wid' => self::optionalIntegerString(
                $entry['winch_id']
                    ?? $entry['wid']
                    ?? null
            ),
            'uidwinch' => self::optionalIntegerString(
                $entry['winch_driver_vf_user_no']
                    ?? $entry['uid_winch']
                    ?? null
            ),
            'uidfi' => self::optionalIntegerString(
                $entry['flight_instructor_vf_user_no']
                    ?? $entry['uid_fi']
                    ?? null
            ),
        ];

        [$motorStart, $motorEnd] = self::motorTimes(
            $entry['motor_minutes'] ?? null
        );

        $payload['motorstart'] = $motorStart;
        $payload['motorend'] = $motorEnd;

        if (
            (int)($entry['vf_flight_type_id'] ?? 0)
            === self::GVVC_FLIGHT_TYPE_ID
        ) {
            $payload['chargemode'] = '7';
            $payload['uidcharge'] = self::GVVC_CHARGE_UID;
        }

        self::assertRequiredPayloadFields($payload);

        return self::removeEmptyOptionalFields($payload);
    }

    /**
     * Erzeugt einen stabilen Hash für Dublettenschutz und Vergleiche.
     *
     * @param array<string,string> $payload
     */
    public static function hash(array $payload): string
    {
        ksort($payload);

        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function resolveArrivalDateTime(
        array $entry,
        string $entryType
    ): DateTimeImmutable {
        $arrivalValue = self::text(
            $entry['arrival_time'] ?? null
        );

        if ($arrivalValue !== '') {
            return self::requiredDateTime(
                $arrivalValue,
                'Landezeit'
            );
        }

        if ($entryType === 'glider_flight') {
            throw new RuntimeException(
                'Landezeit fehlt.'
            );
        }

        $minutes = $entry['tow_minutes']
            ?? $entry['flight_minutes']
            ?? null;

        if (!is_numeric($minutes) || (int)$minutes <= 0) {
            throw new RuntimeException(
                'Landezeit fehlt und Flugzeit ist ungültig.'
            );
        }

        return self::requiredDateTime(
            $entry['departure_time'] ?? null,
            'Startzeit'
        )->modify('+' . (int)$minutes . ' minutes');
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function resolveCallsign(
        array $entry,
        string $entryType
    ): string {
        if ($entryType === 'glider_flight') {
            return self::text($entry['callsign'] ?? null);
        }

        return self::firstNonEmpty(
            $entry['tow_callsign'] ?? null,
            $entry['callsign'] ?? null
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function resolvePilotName(
        array $entry,
        string $entryType
    ): string {
        if ($entryType === 'glider_flight') {
            return self::text($entry['pilot_name'] ?? null);
        }

        return self::firstNonEmpty(
            $entry['tow_pilot_name'] ?? null,
            $entry['pilot_name'] ?? null
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function resolveFlightMinutes(
        array $entry,
        string $entryType
    ): string {
        $value = $entryType === 'glider_flight'
            ? ($entry['flight_minutes'] ?? null)
            : (
                $entry['tow_minutes']
                ?? $entry['flight_minutes']
                ?? null
            );

        return self::optionalPositiveIntegerString(
            $value,
            'Flugzeit'
        );
    }

    private static function requiredDateTime(
        mixed $value,
        string $label
    ): DateTimeImmutable {
        $value = self::text($value);

        if ($value === '') {
            throw new RuntimeException(
                $label . ' fehlt.'
            );
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value
        );

        if (
            $date === false
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new RuntimeException(
                $label . ' ist ungültig: ' . $value
            );
        }

        return $date;
    }

    private static function formatDateTime(
        DateTimeImmutable $date
    ): string {
        return $date->format('Y-m-d H:i');
    }

    private static function formatTime(
        DateTimeImmutable $date
    ): string {
        return $date->format('H:i');
    }

    /**
     * Gibt Motorzeiten nur bei einer positiven Motorlaufzeit aus.
     * Bei 0 Minuten sind beide Felder optional und werden weggelassen.
     *
     * @return array{0:string,1:string}
     */
    private static function motorTimes(mixed $minutes): array
    {
        if (
            $minutes === null
            || trim((string)$minutes) === ''
            || (is_numeric($minutes) && (float)$minutes === 0.0)
        ) {
            return ['', ''];
        }

        if (!is_numeric($minutes) || (float)$minutes < 0) {
            throw new RuntimeException(
                'Motorminuten sind ungültig.'
            );
        }

        $minutes = (int)round((float)$minutes);

        if ($minutes <= 0) {
            return ['', ''];
        }

        return [
            '00:00',
            sprintf(
                '%02d:%02d',
                intdiv($minutes, 60),
                $minutes % 60
            ),
        ];
    }

    private static function optionalIntegerString(
        mixed $value
    ): string {
        if ($value === null || trim((string)$value) === '') {
            return '';
        }

        if (filter_var(
            $value,
            FILTER_VALIDATE_INT
        ) === false) {
            throw new RuntimeException(
                'Ganzzahliger Wert ist ungültig: ' . (string)$value
            );
        }

        return (string)(int)$value;
    }

    private static function positiveIntegerString(
        mixed $value,
        string $label
    ): string {
        $result = self::optionalIntegerString($value);

        if ($result === '' || (int)$result <= 0) {
            throw new RuntimeException(
                $label . ' muss grösser als 0 sein.'
            );
        }

        return $result;
    }

    private static function optionalPositiveIntegerString(
        mixed $value,
        string $label
    ): string {
        if ($value === null || trim((string)$value) === '') {
            return '';
        }

        return self::positiveIntegerString($value, $label);
    }

    private static function optionalNonNegativeNumberString(
        mixed $value,
        string $label
    ): string {
        if ($value === null || trim((string)$value) === '') {
            return '';
        }

        if (!is_numeric($value) || (float)$value < 0) {
            throw new RuntimeException(
                $label . ' ist ungültig.'
            );
        }

        return (string)$value;
    }

    /**
     * @param array<string,string> $payload
     */
    private static function assertRequiredPayloadFields(
        array $payload
    ): void {
        if (trim($payload['callsign'] ?? '') === '') {
            throw new RuntimeException(
                'LFZ-Kennzeichen fehlt.'
            );
        }

        if (
            trim($payload['pilotname'] ?? '') === ''
            && trim($payload['uidpilot'] ?? '') === ''
        ) {
            throw new RuntimeException(
                'Pilotname oder Pilot-UID fehlt.'
            );
        }

        if (trim($payload['departuretime'] ?? '') === '') {
            throw new RuntimeException(
                'Startzeit fehlt.'
            );
        }

        if (trim($payload['arrivaltime'] ?? '') === '') {
            throw new RuntimeException(
                'Landezeit fehlt.'
            );
        }
    }

    /**
     * @param array<string,string> $payload
     * @return array<string,string>
     */
    private static function removeEmptyOptionalFields(
        array $payload
    ): array {
        return array_filter(
            $payload,
            static fn(string $value): bool => $value !== ''
        );
    }

    private static function firstNonEmpty(
        mixed ...$values
    ): string {
        foreach ($values as $value) {
            $value = self::text($value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function text(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        $text = html_entity_decode(
            (string)$value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
