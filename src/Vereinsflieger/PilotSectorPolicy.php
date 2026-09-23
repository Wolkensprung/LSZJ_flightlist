<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

final class PilotSectorPolicy
{
    private const GLIDER = 'Segelflug';
    private const MOTOR_GLIDER = 'Motorsegler';
    private const MOTOR = 'Motorflug';

    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value)
            ? $value
            : preg_split('/[;,|]+/u', (string)$value);

        $result = [];

        foreach ($values ?: [] as $item) {
            if (is_array($item)) {
                $item = $item['name']
                    ?? $item['title']
                    ?? $item['value']
                    ?? '';
            }

            $item = trim(
                html_entity_decode(
                    (string)$item,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            if ($item === '') {
                continue;
            }

            $key = mb_strtolower(
                $item,
                'UTF-8'
            );

            $normalized = match ($key) {
                'sf',
                'segelflug' =>
                    self::GLIDER,

                'ms',
                'motorsegler',
                'reisemotorsegler' =>
                    self::MOTOR_GLIDER,

                'mf',
                'motorflug' =>
                    self::MOTOR,

                default =>
                    $item,
            };

            $result[$normalized] = $normalized;
        }

        return array_values($result);
    }

    public static function capabilities(mixed $value): array
    {
        $sectors = self::normalize($value);

        $hasGlider = in_array(
            self::GLIDER,
            $sectors,
            true
        );

        $hasMotorGlider = in_array(
            self::MOTOR_GLIDER,
            $sectors,
            true
        );

        $hasMotor = in_array(
            self::MOTOR,
            $sectors,
            true
        );

        $hasKnownFlightSector =
            $hasGlider
            || $hasMotorGlider
            || $hasMotor;

        return [
            'sectors_json' => json_encode(
                $sectors,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),

            'can_fly_glider' => (
                !$hasKnownFlightSector
                || $hasGlider
                || $hasMotorGlider
            ) ? 1 : 0,

            'can_fly_motor' => (
                !$hasKnownFlightSector
                || $hasMotor
                || $hasMotorGlider
            ) ? 1 : 0,
        ];
    }
}