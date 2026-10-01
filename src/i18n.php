<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function i18n_current_lang(): string
{
    $lang = (string)($_GET['lang'] ?? $_COOKIE['lszj_lang'] ?? 'de');
    return in_array($lang, ['de', 'fr'], true) ? $lang : 'de';
}

function t(string $key, ?string $lang = null): string
{
    static $cache = [];
    $lang = $lang ?? i18n_current_lang();
    if (!in_array($lang, ['de', 'fr'], true)) {
        $lang = 'de';
    }
    $cacheKey = $lang . "\0" . $key;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        $stmt = db()->prepare(
            'SELECT translation_text FROM i18n_translations WHERE translation_key = :translation_key AND lang = :lang'
        );
        $stmt->execute(['translation_key' => $key, 'lang' => $lang]);
        $value = $stmt->fetchColumn();
        return $cache[$cacheKey] = ($value === false || $value === '') ? $key : (string)$value;
    } catch (Throwable $e) {
        return $cache[$cacheKey] = $key;
    }
}
