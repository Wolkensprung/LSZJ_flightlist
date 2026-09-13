<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;

final class DuplicateFlightResult
{
    /** @return array{is_duplicate:bool,duplicate_flid:string} */
    public static function fromMessage(string $message): array
    {
        if (!str_contains($message, 'duplicateflid')) {
            return ['is_duplicate' => false, 'duplicate_flid' => ''];
        }
        if (preg_match('/["\']duplicateflid["\']\s*:\s*["\']?(\d+)/u', $message, $match) !== 1) {
            return ['is_duplicate' => true, 'duplicate_flid' => ''];
        }
        return ['is_duplicate' => true, 'duplicate_flid' => $match[1]];
    }
}
