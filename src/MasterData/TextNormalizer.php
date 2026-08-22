<?php
declare(strict_types=1);

namespace LSZJ\MasterData;

final class TextNormalizer
{
    public static function clean(mixed $value): string
    {
        $value = trim((string)$value);
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    public static function nullable(mixed $value): ?string
    {
        $value = self::clean($value);
        return $value === '' ? null : $value;
    }

    public static function searchKey(mixed $value): string
    {
        $value = self::clean($value);
        $value = strtr($value, [
            'Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ø'=>'O','Œ'=>'OE',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ý'=>'Y',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','å'=>'a','æ'=>'ae','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ø'=>'o','œ'=>'oe',
            'ù'=>'u','ú'=>'u','û'=>'u','ý'=>'y','ÿ'=>'y'
        ]);
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }

    public static function email(mixed $value): ?string
    {
        $value = self::clean($value);
        if ($value === '') {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
