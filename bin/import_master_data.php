<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Nur Kommandozeile.\n");
}
if ($argc !== 3) {
    exit("Aufruf: php bin/import_master_data.php Mitglieder.csv Luftfahrzeuge.csv\n");
}
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/master_data_bootstrap.php';

use LSZJ\MasterData\VereinsfliegerCsvImporter;

try {
    $importer = new VereinsfliegerCsvImporter(db());
    $result = [
        'ok'=>true,
        'members'=>$importer->importMembers($argv[1], basename($argv[1])),
        'aircraft'=>$importer->importAircraft($argv[2], basename($argv[2])),
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Import fehlgeschlagen: {$e->getMessage()}\n");
    exit(1);
}
