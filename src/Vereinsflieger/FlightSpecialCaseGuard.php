<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

final class FlightSpecialCaseGuard
{
    public const DEFAULT_WINCH_START_TYPE = 2;
    public const DEFAULT_AIRCRAFT_TOW_START_TYPE = 3;
    public const GVVC_FLIGHT_TYPE_ID = 21;
    public const GVVC_CHARGE_UID = '349910';

    /**
     * Prüft einen bereits durch C1/C2 erzeugten Vorschau-Eintrag.
     * Die bestehende Payload- und Personenlogik wird nicht verändert.
     *
     * @param array<string,mixed> $previewItem
     * @return array{type:string,label:string,enabled:bool,issues:list<string>,checks:list<string>,flag:string}
     */
    public function inspect(array $previewItem): array
    {
        $payload = is_array($previewItem['payload'] ?? null)
            ? $previewItem['payload']
            : [];
        $entryType = (string)($previewItem['entry_type'] ?? '');
        $type = $this->detect($entryType, $payload);

        if ($type === 'normal') {
            return [
                'type' => 'normal',
                'label' => 'Normaler Flug',
                'enabled' => true,
                'issues' => [],
                'checks' => [],
                'flag' => '',
            ];
        }

        $flag = $this->flag($type);
        $enabled = getenv($flag) === '1';
        $issues = [];
        $checks = [];

        if (!$enabled) {
            $issues[] = sprintf(
                'Sonderfall „%s“ ist serverseitig gesperrt. %s=1 fehlt.',
                $this->label($type),
                $flag
            );
        }

        if ($type === 'aircraft_tow') {
            $this->aircraftTow($payload, $issues, $checks);
        } elseif ($type === 'winch') {
            $this->winch($payload, $issues, $checks);
        } elseif ($type === 'self_launch_motor') {
            $this->motor($payload, $issues, $checks);
        } elseif ($type === 'standalone_tow') {
            $this->standaloneTow($payload, $issues, $checks);
        } elseif ($type === 'gvvc') {
            $this->gvvc($payload, $issues, $checks);
        }

        return [
            'type' => $type,
            'label' => $this->label($type),
            'enabled' => $enabled,
            'issues' => array_values(array_unique($issues)),
            'checks' => array_values(array_unique($checks)),
            'flag' => $flag,
        ];
    }

    private function detect(string $entryType, array $payload): string
    {
        if ((int)($payload['ftid'] ?? 0) === self::GVVC_FLIGHT_TYPE_ID) {
            return 'gvvc';
        }
        if (in_array($entryType, ['tow_charge', 'towplane_own'], true)) {
            return 'standalone_tow';
        }
        $startType = (int)($payload['starttype'] ?? 0);
        if ($startType === $this->aircraftTowStartType()) {
            return 'aircraft_tow';
        }
        if ($startType === $this->winchStartType()) {
            return 'winch';
        }
        if (
            array_key_exists('motorstart', $payload)
            || array_key_exists('motorend', $payload)
        ) {
            return 'self_launch_motor';
        }
        return 'normal';
    }

    private function aircraftTow(array $payload, array &$issues, array &$checks): void
    {
        $this->required($payload, 'towcallsign', 'Schleppflugzeug', $issues, $checks);
        $this->required($payload, 'towuidpilot', 'VF-UID Schlepppilot', $issues, $checks);
        $this->positive($payload, 'towtime', 'Schleppzeit', $issues, $checks);
    }

    private function winch(array $payload, array &$issues, array &$checks): void
    {
        $this->required($payload, 'wid', 'VF-Winden-ID', $issues, $checks);
        $this->required($payload, 'uidwinch', 'VF-UID Windenfahrer', $issues, $checks);
    }

    private function motor(array $payload, array &$issues, array &$checks): void
    {
        $this->required($payload, 'motorstart', 'Motorstart', $issues, $checks);
        $this->required($payload, 'motorend', 'Motorende', $issues, $checks);
        if (
            isset($payload['motorstart'], $payload['motorend'])
            && (string)$payload['motorstart'] === (string)$payload['motorend']
        ) {
            $issues[] = 'Motorstart und Motorende dürfen nicht identisch sein.';
        }
    }

    private function standaloneTow(array $payload, array &$issues, array &$checks): void
    {
        $this->required($payload, 'callsign', 'Schleppflugzeug', $issues, $checks);
        $this->required($payload, 'uidpilot', 'VF-UID Motorpilot', $issues, $checks);
        $this->positive($payload, 'flighttime', 'Motorflugzeit', $issues, $checks);
    }

    private function gvvc(array $payload, array &$issues, array &$checks): void
    {
        if ((string)($payload['chargemode'] ?? '') !== '7') {
            $issues[] = 'GVVC: chargemode muss 7 sein.';
        } else {
            $checks[] = 'chargemode=7';
        }
        if ((string)($payload['uidcharge'] ?? '') !== self::GVVC_CHARGE_UID) {
            $issues[] = 'GVVC: uidcharge muss ' . self::GVVC_CHARGE_UID . ' sein.';
        } else {
            $checks[] = 'uidcharge=' . self::GVVC_CHARGE_UID;
        }
    }

    private function required(
        array $payload,
        string $field,
        string $label,
        array &$issues,
        array &$checks
    ): void {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '' || $value === '0') {
            $issues[] = $label . ' fehlt.';
        } else {
            $checks[] = $label . ': ' . $value;
        }
    }

    private function positive(
        array $payload,
        string $field,
        string $label,
        array &$issues,
        array &$checks
    ): void {
        $value = $payload[$field] ?? null;
        if (!is_numeric($value) || (float)$value <= 0) {
            $issues[] = $label . ' muss grösser als 0 sein.';
        } else {
            $checks[] = $label . ': ' . (string)$value;
        }
    }

    private function flag(string $type): string
    {
        return match ($type) {
            'aircraft_tow' => 'VF_C3_ENABLE_AIRCRAFT_TOW',
            'winch' => 'VF_C3_ENABLE_WINCH',
            'self_launch_motor' => 'VF_C3_ENABLE_MOTOR',
            'standalone_tow' => 'VF_C3_ENABLE_STANDALONE_TOW',
            'gvvc' => 'VF_C3_ENABLE_GVVC',
            default => 'VF_C3_DISABLED',
        };
    }

    private function label(string $type): string
    {
        return match ($type) {
            'aircraft_tow' => 'Flugzeugschlepp',
            'winch' => 'Windenstart',
            'self_launch_motor' => 'Eigenstart mit Motorlaufzeit',
            'standalone_tow' => 'Eigenständiger Schleppflug',
            'gvvc' => 'GVVC-Abrechnung',
            default => 'Normaler Flug',
        };
    }

    private function aircraftTowStartType(): int
    {
        $value = getenv('VF_STARTTYPE_AIRCRAFT_TOW');
        return $value !== false && ctype_digit($value)
            ? (int)$value
            : self::DEFAULT_AIRCRAFT_TOW_START_TYPE;
    }

    private function winchStartType(): int
    {
        $value = getenv('VF_STARTTYPE_WINCH');
        return $value !== false && ctype_digit($value)
            ? (int)$value
            : self::DEFAULT_WINCH_START_TYPE;
    }
}
