<?php
/**
 * api_import_ktrax_range.php
 *
 * Importiert fehlende kTrax-Tage fuer einen Von/Bis-Zeitraum.
 * Der Button soll bewusst nicht der normale "Laden"-Button sein.
 *
 * Logik:
 * - pro Tag pruefen, ob bereits kTrax-Daten vorhanden sind
 * - fehlende Tage via bestehendem import_ktrax.php nachladen
 * - Importstatus in ktrax_import_log protokollieren
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$config = app_config();
$pdo = db();

function ensure_ktrax_import_log(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ktrax_import_log (
        import_date DATE NOT NULL,
        airfield VARCHAR(16) NOT NULL DEFAULT 'LSZJ',
        status VARCHAR(32) NOT NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        raw_imported INT NULL,
        operations_created INT NULL,
        operations_skipped_existing INT NULL,
        tow_segments_created INT NULL,
        towplane_own_entries_created INT NULL,
        message TEXT NULL,
        PRIMARY KEY (import_date, airfield)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function valid_date_string(?string $date): bool {
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function ktrax_day_exists(PDO $pdo, string $date, string $airfield): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ktrax_import_log WHERE import_date = ? AND airfield = ? AND status = 'success'");
    $stmt->execute([$date, strtoupper($airfield)]);
    if ((int)$stmt->fetchColumn() > 0) return true;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM raw_flights WHERE source = 'ktrax' AND flight_date = ?");
    $stmt->execute([$date]);
    if ((int)$stmt->fetchColumn() > 0) return true;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM operations WHERE created_from = 'ktrax' AND operation_date = ?");
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn() > 0;
}

function log_ktrax_import(PDO $pdo, string $date, string $airfield, string $status, array $data = []): void {
    $stmt = $pdo->prepare("INSERT INTO ktrax_import_log
        (import_date, airfield, status, imported_at, raw_imported, operations_created, operations_skipped_existing, tow_segments_created, towplane_own_entries_created, message)
        VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            imported_at = VALUES(imported_at),
            raw_imported = VALUES(raw_imported),
            operations_created = VALUES(operations_created),
            operations_skipped_existing = VALUES(operations_skipped_existing),
            tow_segments_created = VALUES(tow_segments_created),
            towplane_own_entries_created = VALUES(towplane_own_entries_created),
            message = VALUES(message)");
    $stmt->execute([
        $date,
        strtoupper($airfield),
        $status,
        $data['raw_imported'] ?? null,
        $data['operations_created'] ?? null,
        $data['operations_skipped_existing'] ?? null,
        $data['tow_segments_created'] ?? null,
        $data['towplane_own_entries_created'] ?? null,
        $data['message'] ?? null,
    ]);
}

function build_self_import_url(string $date, string $airfield): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/src')), '/');
    return $scheme . '://' . $host . $dir . '/import_ktrax.php?date=' . rawurlencode($date) . '&airfield=' . rawurlencode($airfield);
}

$from = $_GET['from'] ?? $_POST['from'] ?? ($_GET['date'] ?? null);
$to = $_GET['to'] ?? $_POST['to'] ?? $from;
$airfield = strtolower($_GET['airfield'] ?? $_POST['airfield'] ?? $config['ktrax']['default_airfield'] ?? 'lszj');
$force = !empty($_GET['force']) || !empty($_POST['force']);

if (!valid_date_string($from) || !valid_date_string($to)) {
    json_response(['ok' => false, 'error' => 'Ungültiger Datumsbereich. Erwartet YYYY-MM-DD.'], 400);
}
if ($to < $from) {
    json_response(['ok' => false, 'error' => 'Bis darf nicht vor Von liegen.'], 400);
}

$start = new DateTime($from);
$end = new DateTime($to);
$days = ((int)$start->diff($end)->format('%a')) + 1;
$maxDays = (int)($config['ktrax']['max_range_import_days'] ?? 31);
if ($days > $maxDays) {
    json_response(['ok' => false, 'error' => 'Der Zeitraum ist zu gross. Maximal erlaubt: ' . $maxDays . ' Tage.'], 400);
}

ensure_ktrax_import_log($pdo);

$details = [];
$daysImported = 0;
$daysSkipped = 0;
$daysFailed = 0;

$current = clone $start;
while ($current <= $end) {
    $date = $current->format('Y-m-d');
    if (!$force && ktrax_day_exists($pdo, $date, $airfield)) {
        $daysSkipped++;
        $details[] = ['date' => $date, 'status' => 'skipped', 'message' => 'bereits vorhanden'];
        $current->modify('+1 day');
        continue;
    }

    $url = build_self_import_url($date, $airfield);
    $context = stream_context_create(['http' => ['timeout' => 60]]);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        $daysFailed++;
        log_ktrax_import($pdo, $date, $airfield, 'error', ['message' => 'import_ktrax.php konnte nicht aufgerufen werden']);
        $details[] = ['date' => $date, 'status' => 'error', 'message' => 'import_ktrax.php konnte nicht aufgerufen werden'];
        $current->modify('+1 day');
        continue;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['ok'])) {
        $daysFailed++;
        $message = is_array($data) ? ($data['error'] ?? 'Import lieferte kein ok=true') : 'Antwort ist kein JSON';
        log_ktrax_import($pdo, $date, $airfield, 'error', ['message' => $message]);
        $details[] = ['date' => $date, 'status' => 'error', 'message' => $message];
        $current->modify('+1 day');
        continue;
    }

    $daysImported++;
    log_ktrax_import($pdo, $date, $airfield, 'success', $data + ['message' => 'importiert']);
    $details[] = [
        'date' => $date,
        'status' => 'imported',
        'raw_imported' => $data['raw_imported'] ?? null,
        'operations_created' => $data['operations_created'] ?? null,
        'message' => 'importiert'
    ];
    $current->modify('+1 day');
}

json_response([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'airfield' => strtoupper($airfield),
    'days_checked' => $days,
    'days_imported' => $daysImported,
    'days_skipped' => $daysSkipped,
    'days_failed' => $daysFailed,
    'details' => $details,
]);
