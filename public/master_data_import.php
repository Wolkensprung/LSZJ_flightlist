<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/page_security.php';
$currentUser = lszj_require_page_role('ADMIN');

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/master_data_bootstrap.php';

use LSZJ\MasterData\VereinsfliegerCsvImporter;

$result = null;
$error = null;
$importScope = '';

/**
 * @return array{name:string,tmp_name:string,size:int,error:int}
 */
function validatedCsvUpload(string $field, string $label): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        throw new RuntimeException("CSV-Upload fehlt: {$label}");
    }

    $upload = $_FILES[$field];
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException("CSV-Upload fehlt oder ist fehlerhaft: {$label}");
    }

    $name = (string)($upload['name'] ?? '');
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $size = (int)($upload['size'] ?? 0);

    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException("Nur CSV-Dateien sind erlaubt: {$label}");
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException("CSV-Datei ist grösser als 5 MB: {$label}");
    }
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException("Temporäre Upload-Datei ist ungültig: {$label}");
    }

    return [
        'name' => $name,
        'tmp_name' => $tmpName,
        'size' => $size,
        'error' => $error,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $importScope = (string)($_POST['import_scope'] ?? '');
        if (!in_array($importScope, ['members', 'aircraft', 'both'], true)) {
            throw new RuntimeException('Bitte den gewünschten Importumfang wählen.');
        }

        $importer = new VereinsfliegerCsvImporter(db());
        $result = [];

        if (in_array($importScope, ['members', 'both'], true)) {
            $members = validatedCsvUpload('members', 'Mitglieder.csv');
            $result['members'] = $importer->importMembers($members['tmp_name'], $members['name']);
        }

        if (in_array($importScope, ['aircraft', 'both'], true)) {
            $aircraft = validatedCsvUpload('aircraft', 'Luftfahrzeuge.csv');
            $result['aircraft'] = $importer->importAircraft($aircraft['tmp_name'], $aircraft['name']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $result = null;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vereinsflieger Stammdatenimport</title>
<style>
body{font-family:Arial,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f5f7fa;color:#1f2937}.card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px #0001;margin-bottom:18px}label{font-weight:700;display:block;margin:18px 0 7px}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}.actions button{margin:0;background:#0b64c0;color:#fff;border:0;border-radius:8px;padding:11px 18px;font-size:16px;cursor:pointer}.actions button.secondary{background:#475569}.ok{border-left:5px solid #16803c}.err{border-left:5px solid #b42318}.muted{color:#64748b;font-size:14px}
</style>
<script src="i18n.js?v=20261003_3" defer></script>
</head><body>
<div class="card"><h1>Vereinsflieger-Stammdaten</h1>
<p>Importiert die standardisierten CSV-Exporte. Mitglieder werden inklusive Vereinsflieger-Benutzernummer, Mailadresse und Mobilnummer gespeichert.</p>
<form method="post" enctype="multipart/form-data">
<label for="members">Mitglieder.csv</label><input id="members" name="members" type="file" accept=".csv,text/csv">
<label for="aircraft">Luftfahrzeuge.csv</label><input id="aircraft" name="aircraft" type="file" accept=".csv,text/csv">
<div class="actions" role="group" aria-label="Importumfang">
<button type="submit" name="import_scope" value="members">Nur Mitglieder importieren</button>
<button type="submit" name="import_scope" value="aircraft">Nur Luftfahrzeuge importieren</button>
<button class="secondary" type="submit" name="import_scope" value="both">Beide Dateien importieren</button>
</div>
</form></div>
<?php if ($error): ?><div class="card err"><strong>Import fehlgeschlagen:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($result): ?><div class="card ok"><h2>Import erfolgreich</h2>
<?php if (isset($result['members'])): ?><p>Mitglieder: <?= (int)$result['members']['rows_imported'] ?> importiert, <?= (int)$result['members']['rows_skipped'] ?> übersprungen.</p><?php endif; ?>
<?php if (isset($result['aircraft'])): ?><p>Luftfahrzeuge: <?= (int)$result['aircraft']['rows_imported'] ?> importiert, <?= (int)$result['aircraft']['rows_skipped'] ?> übersprungen.</p><?php endif; ?>
<p class="muted">Fehlende Datensätze werden nur im tatsächlich importierten Datenbestand deaktiviert, nicht gelöscht. Doppelte Luftfahrzeugzeilen werden pro Callsign konsolidiert.</p></div><?php endif; ?>
</body></html>
