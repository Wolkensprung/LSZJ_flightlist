<?php
declare(strict_types=1);

/**
 * CLI-Runner für genau einen kTrax-Tag.
 * Wird ausschliesslich von api_import_ktrax_range.php gestartet.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieser Runner darf nur per PHP-CLI gestartet werden.\n");
    exit(64);
}

$date = (string)($argv[1] ?? '');
$airfield = strtolower((string)($argv[2] ?? 'lszj'));

if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
    fwrite(STDERR, "Ungültiges Datum.\n");
    exit(64);
}

$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Ungültiges Kalenderdatum.\n");
    exit(64);
}

if (preg_match('/^[a-z0-9_-]{2,16}$/D', $airfield) !== 1) {
    fwrite(STDERR, "Ungültiger Flugplatz.\n");
    exit(64);
}

$_GET = [
    'date' => $date,
    'airfield' => $airfield,
];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/import_ktrax.php';

require __DIR__ . '/import_ktrax.php';
