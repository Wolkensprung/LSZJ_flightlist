<?php
declare(strict_types=1);

namespace LSZJ\MasterData;

final class CallsignNormalizer
{
    public static function normalize(mixed $value): string
    {
        $raw = strtoupper(TextNormalizer::clean($value));
        if ($raw === '') {
            return '';
        }
        if (in_array($raw, ['EXTERN', 'FREMD', 'SWIFT', 'ATOS'], true)) {
            return $raw;
        }

        $compact = preg_replace('/[^A-Z0-9]/', '', $raw) ?? $raw;
        $patterns = [
            '/^(HB)([A-Z]{3}|[0-9]{3,4})$/',
            '/^(D)([A-Z0-9]{4})$/',
            '/^(F)([A-Z0-9]{4})$/',
            '/^(OE)([A-Z0-9]{4})$/',
            '/^(VH)([A-Z0-9]{3})$/',
            '/^(ZS)([A-Z0-9]{3})$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $compact, $match) === 1) {
                return $match[1] . '-' . $match[2];
            }
        }
        if (preg_match('/^[A-Z0-9]{1,3}-[A-Z0-9]{2,5}$/', $raw) === 1) {
            return $raw;
        }
        return $raw;
    }

    public static function searchKey(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$value)) ?? '';
    }
}
