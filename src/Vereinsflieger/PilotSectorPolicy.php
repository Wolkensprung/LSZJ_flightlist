<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;
use RuntimeException;
final class PilotSectorPolicy
{
    private const GLIDER = 'Segelflug';
    private const MOTOR_GLIDER = 'Motorsegler';
    private const MOTOR = 'Motorflug';
    /** @return list<string> */
    public static function normalize(mixed $value, bool $strict = true): array
    {
        if ($value === null || $value === '') return [];
        $values = is_array($value) ? $value : preg_split('/[;,|]+/u', (string)$value);
        $result = [];
        foreach ($values ?: [] as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['title'] ?? $item['value'] ?? '';
            }
            $item = trim(html_entity_decode((string)$item, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($item === '') continue;
            $key = mb_strtolower($item, 'UTF-8');
            $canonical = match ($key) {
                'segelflug' => self::GLIDER,
                'motorsegler', 'reisemotorsegler' => self::MOTOR_GLIDER,
                'motorflug' => self::MOTOR,
                default => null,
            };
            if ($canonical === null) {
                if ($strict) throw new RuntimeException('Unbekannte Sparte "'.$item.'". Import abgebrochen.');
                continue;
            }
            $result[$canonical] = $canonical;
        }
        return array_values($result);
    }
    /** @return array{sectors_json:string,can_fly_glider:int,can_fly_motor:int} */
    public static function capabilities(mixed $value, bool $strict = true): array
    {
        $sectors = self::normalize($value, $strict);
        // Keine Sparte darf externe/sonstige Piloten nicht aussperren.
        $unrestricted = $sectors === [];
        return [
            'sectors_json' => json_encode($sectors, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'can_fly_glider' => $unrestricted || in_array(self::GLIDER,$sectors,true) || in_array(self::MOTOR_GLIDER,$sectors,true) ? 1 : 0,
            'can_fly_motor' => $unrestricted || in_array(self::MOTOR,$sectors,true) || in_array(self::MOTOR_GLIDER,$sectors,true) ? 1 : 0,
        ];
    }
}
