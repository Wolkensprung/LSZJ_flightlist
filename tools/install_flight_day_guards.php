<?php
declare(strict_types=1);

$projectRoot = $argv[1] ?? dirname(__DIR__);
$src = rtrim($projectRoot, "\\/") . DIRECTORY_SEPARATOR . 'src';
$stamp = date('Ymd_His');

$targets = [
    'api_create_manual_flight.php',
    'api_update_flight_data.php',
    'api_save_operation_correction.php',
    'api_approve_flight_operation.php',
    'api_approve_entry.php',
    'api_set_approval.php',
    'api_delete_flight_part.php',
];

$markers = [
    [
        'text' => <<<'CODE'
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
CODE,
        'variable' => '$input',
    ],
    [
        'text' => <<<'CODE'
$input=json_decode(file_get_contents('php://input'),true)?:$_POST;
CODE,
        'variable' => '$input',
    ],
    [
        'text' => <<<'CODE'
$i = jinput();
CODE,
        'variable' => '$i',
    ],
    [
        'text' => <<<'CODE'
$i=jinput();
CODE,
        'variable' => '$i',
    ],
];

$failed = [];

foreach ($targets as $name) {
    $path = $src . DIRECTORY_SEPARATOR . $name;

    if (!is_file($path)) {
        fwrite(STDERR, "WARNUNG: Datei fehlt: {$name}" . PHP_EOL);
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        $failed[] = $name . ': konnte nicht gelesen werden';
        continue;
    }

    if (str_contains($content, 'flight_day_assert_request_editable(')) {
        echo "Bereits geschuetzt: {$name}" . PHP_EOL;
        continue;
    }

    $patched = null;
    foreach ($markers as $marker) {
        if (!str_contains($content, $marker['text'])) {
            continue;
        }

        $insertion = $marker['text']
            . PHP_EOL
            . "require_once __DIR__ . '/flight_day.php';"
            . PHP_EOL
            . 'flight_day_assert_request_editable(' . $marker['variable'] . ', basename(__FILE__));';

        $patched = str_replace($marker['text'], $insertion, $content, $count);
        if ($count === 1) {
            break;
        }
        $patched = null;
    }

    if ($patched === null) {
        $failed[] = $name . ': Eingabe-Marker nicht gefunden oder nicht eindeutig';
        continue;
    }

    $backup = $path . '.bak-' . $stamp;
    if (!copy($path, $backup)) {
        $failed[] = $name . ': Backup konnte nicht erstellt werden';
        continue;
    }

    if (file_put_contents($path, $patched) === false) {
        copy($backup, $path);
        $failed[] = $name . ': Schreiben fehlgeschlagen; Original wiederhergestellt';
        continue;
    }

    echo "Tagessperre ergaenzt: {$name}" . PHP_EOL;
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . "Nicht geaendert:" . PHP_EOL);
    foreach ($failed as $message) {
        fwrite(STDERR, '- ' . $message . PHP_EOL);
    }
    exit(2);
}

echo PHP_EOL . "Alle vorhandenen Ziel-APIs wurden verarbeitet." . PHP_EOL;
echo "Backups tragen die Endung .bak-{$stamp}" . PHP_EOL;
