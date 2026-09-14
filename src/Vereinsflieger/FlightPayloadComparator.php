<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use JsonException;
use RuntimeException;

/**
 * Kanonischer Vergleicher fuer Vereinsflieger-REST-Payloads.
 *
 * Eigenschaften:
 * - veraendert die Eingaben nicht
 * - entfernt keine "0"-Werte
 * - behandelt Integer, Float, Boolean und String als Stringwerte
 * - sortiert Schluessel rekursiv
 * - erzeugt einen stabilen SHA-256-Hash
 * - liefert feldweise Unterschiede inklusive Gruppe und Risiko
 * - erkennt Namensaenderungen bei unveraenderter Personen-UID
 *
 * Die Klasse fuehrt keine Datenbank- und keine VF-Schreiboperation aus.
 */
final class FlightPayloadComparator
{
    public const CHANGE_ADDED = 'added';
    public const CHANGE_REMOVED = 'removed';
    public const CHANGE_CHANGED = 'changed';

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    private const RISK_WEIGHT = [
        self::RISK_LOW => 1,
        self::RISK_MEDIUM => 2,
        self::RISK_HIGH => 3,
    ];

    /** @var array<string,string> */
    private const FIELD_GROUPS = [
        'pilotname' => 'identity',
        'uidpilot' => 'identity',
        'attendantname' => 'identity',
        'uidattendant' => 'identity',
        'attendantname2' => 'identity',
        'uidattendant2' => 'identity',
        'attendantname3' => 'identity',
        'uidattendant3' => 'identity',
        'towpilotname' => 'identity',
        'towuidpilot' => 'identity',
        'uidfi' => 'identity',

        'callsign' => 'aircraft',
        'planewkz' => 'aircraft',
        'planedesignation' => 'aircraft',

        'departuretime' => 'time_location',
        'arrivaltime' => 'time_location',
        'departurelocation' => 'time_location',
        'arrivallocation' => 'time_location',
        'offblock' => 'time_location',
        'onblock' => 'time_location',
        'blocktime' => 'time_location',
        'flighttime' => 'time_location',
        'landingcount' => 'time_location',

        'ftid' => 'flight_classification',
        'starttype' => 'flight_classification',
        'wid' => 'flight_classification',
        'uidwinch' => 'flight_classification',

        'towcallsign' => 'tow',
        'towtime' => 'tow',
        'towheight' => 'tow',
        'towarrivallocation' => 'tow',

        'chargemode' => 'billing',
        'uidcharge' => 'billing',
        'invoiced' => 'billing',
        'km' => 'billing',
        'guestcost' => 'billing',

        'comment' => 'additional',
        'motorstart' => 'additional',
        'motorend' => 'additional',
        'checkin' => 'additional',
        'checkout' => 'additional',
        'runwaydeparture' => 'additional',
        'runwayarrival' => 'additional',
    ];

    /** @var array<string,string> */
    private const FIELD_RISKS = [
        'pilotname' => self::RISK_HIGH,
        'uidpilot' => self::RISK_HIGH,
        'attendantname' => self::RISK_HIGH,
        'uidattendant' => self::RISK_HIGH,
        'attendantname2' => self::RISK_HIGH,
        'uidattendant2' => self::RISK_HIGH,
        'attendantname3' => self::RISK_HIGH,
        'uidattendant3' => self::RISK_HIGH,
        'towpilotname' => self::RISK_HIGH,
        'towuidpilot' => self::RISK_HIGH,
        'uidfi' => self::RISK_HIGH,

        'callsign' => self::RISK_HIGH,
        'planewkz' => self::RISK_HIGH,
        'planedesignation' => self::RISK_HIGH,

        'departuretime' => self::RISK_HIGH,
        'arrivaltime' => self::RISK_HIGH,
        'departurelocation' => self::RISK_MEDIUM,
        'arrivallocation' => self::RISK_MEDIUM,
        'offblock' => self::RISK_MEDIUM,
        'onblock' => self::RISK_MEDIUM,
        'blocktime' => self::RISK_MEDIUM,
        'flighttime' => self::RISK_MEDIUM,
        'landingcount' => self::RISK_MEDIUM,

        'ftid' => self::RISK_HIGH,
        'starttype' => self::RISK_HIGH,
        'wid' => self::RISK_HIGH,
        'uidwinch' => self::RISK_HIGH,

        'towcallsign' => self::RISK_HIGH,
        'towtime' => self::RISK_MEDIUM,
        'towheight' => self::RISK_MEDIUM,
        'towarrivallocation' => self::RISK_MEDIUM,

        'chargemode' => self::RISK_HIGH,
        'uidcharge' => self::RISK_HIGH,
        'invoiced' => self::RISK_HIGH,
        'km' => self::RISK_MEDIUM,
        'guestcost' => self::RISK_HIGH,

        'comment' => self::RISK_LOW,
        'motorstart' => self::RISK_MEDIUM,
        'motorend' => self::RISK_MEDIUM,
        'checkin' => self::RISK_MEDIUM,
        'checkout' => self::RISK_MEDIUM,
        'runwaydeparture' => self::RISK_LOW,
        'runwayarrival' => self::RISK_LOW,
    ];

    /**
     * @param array<string,mixed> $historicalPayload
     * @param array<string,mixed> $currentPayload
     * @return array{
     *   equal:bool,
     *   status:string,
     *   old_hash:string,
     *   current_hash:string,
     *   difference_count:int,
     *   highest_risk:?string,
     *   risk_counts:array{low:int,medium:int,high:int},
     *   group_counts:array<string,int>,
     *   differences:list<array<string,mixed>>,
     *   identity_notes:list<array<string,string>>,
     *   old_payload:array<string,mixed>,
     *   current_payload:array<string,mixed>
     * }
     */
    public function compare(
        array $historicalPayload,
        array $currentPayload
    ): array {
        $old = $this->canonicalize($historicalPayload);
        $current = $this->canonicalize($currentPayload);
        $oldHash = $this->hashCanonical($old);
        $currentHash = $this->hashCanonical($current);

        if (hash_equals($oldHash, $currentHash)) {
            return [
                'equal' => true,
                'status' => 'in_sync',
                'old_hash' => $oldHash,
                'current_hash' => $currentHash,
                'difference_count' => 0,
                'highest_risk' => null,
                'risk_counts' => ['low' => 0, 'medium' => 0, 'high' => 0],
                'group_counts' => [],
                'differences' => [],
                'identity_notes' => [],
                'old_payload' => $old,
                'current_payload' => $current,
            ];
        }

        $differences = [];
        $this->diffRecursive($old, $current, '', $differences);
        $identityNotes = $this->identityNotes($old, $current);
        $riskCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
        $groupCounts = [];
        $highestRisk = null;

        foreach ($differences as $difference) {
            $risk = (string)$difference['risk'];
            $group = (string)$difference['group'];
            $riskCounts[$risk]++;
            $groupCounts[$group] = ($groupCounts[$group] ?? 0) + 1;

            if (
                $highestRisk === null
                || self::RISK_WEIGHT[$risk] > self::RISK_WEIGHT[$highestRisk]
            ) {
                $highestRisk = $risk;
            }
        }

        ksort($groupCounts, SORT_STRING);

        return [
            'equal' => false,
            'status' => 'local_change_pending',
            'old_hash' => $oldHash,
            'current_hash' => $currentHash,
            'difference_count' => count($differences),
            'highest_risk' => $highestRisk,
            'risk_counts' => $riskCounts,
            'group_counts' => $groupCounts,
            'differences' => $differences,
            'identity_notes' => $identityNotes,
            'old_payload' => $old,
            'current_payload' => $current,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function canonicalize(array $payload): array
    {
        return $this->canonicalArray($payload);
    }

    /** @param array<string,mixed> $payload */
    public function hash(array $payload): string
    {
        return $this->hashCanonical($this->canonicalize($payload));
    }

    /** @return array<string,mixed> */
    public function decodePayloadJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Payload-JSON ist ungueltig: ' . $error->getMessage(),
                0,
                $error
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException(
                'Payload-JSON muss ein JSON-Objekt enthalten.'
            );
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function canonicalArray(array $values): array
    {
        if (array_is_list($values)) {
            return array_map(
                fn(mixed $value): mixed => $this->canonicalValue($value),
                $values
            );
        }

        $canonical = [];

        foreach ($values as $key => $value) {
            $canonical[(string)$key] = $this->canonicalValue($value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->canonicalArray($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string)$value;
        }

        throw new RuntimeException(
            'Payload enthaelt einen nicht vergleichbaren Wert vom Typ '
            . get_debug_type($value) . '.'
        );
    }

    /** @param array<string,mixed> $canonicalPayload */
    private function hashCanonical(array $canonicalPayload): string
    {
        try {
            $json = json_encode(
                $canonicalPayload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException(
                'Kanonischer Payload konnte nicht serialisiert werden: '
                . $error->getMessage(),
                0,
                $error
            );
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<mixed> $old
     * @param array<mixed> $current
     * @param list<array<string,mixed>> $differences
     */
    private function diffRecursive(
        array $old,
        array $current,
        string $path,
        array &$differences
    ): void {
        $keys = array_values(array_unique(array_merge(
            array_map('strval', array_keys($old)),
            array_map('strval', array_keys($current))
        )));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $old);
            $currentExists = array_key_exists($key, $current);
            $fieldPath = $path === '' ? $key : $path . '.' . $key;
            $rootField = explode('.', $fieldPath, 2)[0];

            if (!$oldExists) {
                $differences[] = $this->difference(
                    $fieldPath,
                    $rootField,
                    self::CHANGE_ADDED,
                    null,
                    $current[$key]
                );
                continue;
            }

            if (!$currentExists) {
                $differences[] = $this->difference(
                    $fieldPath,
                    $rootField,
                    self::CHANGE_REMOVED,
                    $old[$key],
                    null
                );
                continue;
            }

            $oldValue = $old[$key];
            $currentValue = $current[$key];

            if (is_array($oldValue) && is_array($currentValue)) {
                $this->diffRecursive(
                    $oldValue,
                    $currentValue,
                    $fieldPath,
                    $differences
                );
                continue;
            }

            if ($oldValue !== $currentValue) {
                $differences[] = $this->difference(
                    $fieldPath,
                    $rootField,
                    self::CHANGE_CHANGED,
                    $oldValue,
                    $currentValue
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function difference(
        string $fieldPath,
        string $rootField,
        string $changeType,
        mixed $oldValue,
        mixed $currentValue
    ): array {
        return [
            'field' => $fieldPath,
            'root_field' => $rootField,
            'group' => self::FIELD_GROUPS[$rootField] ?? 'other',
            'change_type' => $changeType,
            'old' => $oldValue,
            'new' => $currentValue,
            'risk' => self::FIELD_RISKS[$rootField] ?? self::RISK_MEDIUM,
        ];
    }

    /**
     * Kennzeichnet Namensaenderungen bei gleichbleibender UID sowie echte
     * Personenwechsel bei geaenderter UID.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $current
     * @return list<array<string,string>>
     */
    private function identityNotes(array $old, array $current): array
    {
        $pairs = [
            ['role' => 'pilot', 'name' => 'pilotname', 'uid' => 'uidpilot'],
            [
                'role' => 'attendant',
                'name' => 'attendantname',
                'uid' => 'uidattendant',
            ],
            [
                'role' => 'attendant2',
                'name' => 'attendantname2',
                'uid' => 'uidattendant2',
            ],
            [
                'role' => 'attendant3',
                'name' => 'attendantname3',
                'uid' => 'uidattendant3',
            ],
            [
                'role' => 'tow_pilot',
                'name' => 'towpilotname',
                'uid' => 'towuidpilot',
            ],
        ];

        $notes = [];

        foreach ($pairs as $pair) {
            $oldName = $old[$pair['name']] ?? null;
            $newName = $current[$pair['name']] ?? null;
            $oldUid = $old[$pair['uid']] ?? null;
            $newUid = $current[$pair['uid']] ?? null;

            if ($oldUid !== null && $newUid !== null && $oldUid !== $newUid) {
                $notes[] = [
                    'role' => $pair['role'],
                    'type' => 'person_identity_changed',
                    'message' => 'VF-Personen-UID wurde geaendert.',
                ];
                continue;
            }

            if (
                $oldUid !== null
                && $oldUid === $newUid
                && $oldName !== $newName
            ) {
                $notes[] = [
                    'role' => $pair['role'],
                    'type' => 'display_name_changed_same_identity',
                    'message' => 'Anzeigename geaendert, VF-Personen-UID unveraendert.',
                ];
            }
        }

        return $notes;
    }
}
