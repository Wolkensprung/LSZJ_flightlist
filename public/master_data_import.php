<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/master_data_bootstrap.php';

use LSZJ\MasterData\VereinsfliegerCsvImporter;

$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach (['members','aircraft'] as $field) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException("CSV-Upload fehlt oder ist fehlerhaft: {$field}");
            }
            if (strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new RuntimeException("Nur CSV-Dateien sind erlaubt: {$field}");
            }
            if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException("CSV-Datei ist groesser als 5 MB: {$field}");
            }
        }
        $importer = new VereinsfliegerCsvImporter(db());
        $result = [
            'members'=>$importer->importMembers($_FILES['members']['tmp_name'], $_FILES['members']['name']),
            'aircraft'=>$importer->importAircraft($_FILES['aircraft']['tmp_name'], $_FILES['aircraft']['name']),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vereinsflieger Stammdatenimport</title>
<style>
body{font-family:Arial,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f5f7fa;color:#1f2937}.card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px #0001;margin-bottom:18px}label{font-weight:700;display:block;margin:18px 0 7px}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}button{margin-top:20px;background:#0b64c0;color:#fff;border:0;border-radius:8px;padding:11px 18px;font-size:16px}.ok{border-left:5px solid #16803c}.err{border-left:5px solid #b42318}.muted{color:#64748b;font-size:14px}
</style>
</head><body>
<div class="card"><h1>Vereinsflieger-Stammdaten</h1>
<p>Importiert die standardisierten CSV-Exporte. Mitglieder werden inklusive Vereinsflieger-Benutzernummer, Mailadresse und Mobilnummer gespeichert.</p>
<form method="post" enctype="multipart/form-data">
<label for="members">Mitglieder.csv</label><input id="members" name="members" type="file" accept=".csv,text/csv" required>
<label for="aircraft">Luftfahrzeuge.csv</label><input id="aircraft" name="aircraft" type="file" accept=".csv,text/csv" required>
<button type="submit">Stammdaten importieren</button></form></div>
<?php if ($error): ?><div class="card err"><strong>Import fehlgeschlagen:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($result): ?><div class="card ok"><h2>Import erfolgreich</h2>
<p>Mitglieder: <?= (int)$result['members']['rows_imported'] ?> importiert, <?= (int)$result['members']['rows_skipped'] ?> uebersprungen.</p>
<p>Luftfahrzeuge: <?= (int)$result['aircraft']['rows_imported'] ?> importiert, <?= (int)$result['aircraft']['rows_skipped'] ?> uebersprungen.</p>
<p class="muted">Fehlende Datensaetze werden deaktiviert, nicht geloescht. Doppelte Luftfahrzeugzeilen werden pro Callsign konsolidiert.</p></div><?php endif; ?>
</body></html>
