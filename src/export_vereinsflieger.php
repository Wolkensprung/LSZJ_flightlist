<?php
/**
 * export_vereinsflieger.php
 *
 * Exportiert freigegebene und noch nicht exportierte accounting_entries
 * im Vereinsflieger-CSV-Format. Unterstützt `date` oder `from`/`to`.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$from = $_GET['from'] ?? ($_GET['date'] ?? date('Y-m-d'));
$to = $_GET['to'] ?? $from;
if ($to < $from) {
    http_response_code(400);
    echo 'Ungültiger Zeitraum';
    exit;
}
$batch = 'VF-' . date('Ymd-His');
$header = [
    'callsign','pilotname','attendantname','departuretime','departurelocation',
    'arrivaltime','arrivallocation','flighttime','landingcount','starttype',
    'motorstart','motorend','chargemode','invoiced','comment','towheight',
    'towtime','towcallsign','towpilotname','towarrivallocation','ftid','km',
    'planewkz','planedesignation','wid','uidwinch','attendantname2',
    'attendantname3','offblock','onblock','runwaydeparture','runwayarrival',
    'checkin','checkout'
];
$stmt = $pdo->prepare(
    "SELECT * FROM accounting_entries
     WHERE approval_status = 'approved'
       AND exported_at IS NULL
       AND DATE(departure_time) BETWEEN ? AND ?
     ORDER BY departure_time, id"
);
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();
$rangeLabel = $from === $to ? str_replace('-', '', $from) : str_replace('-', '', $from) . '_' . str_replace('-', '', $to);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vereinsflieger_export_' . $rangeLabel . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, $header, ';');
$ids = [];
foreach ($rows as $r) {
    $ids[] = (int)$r['id'];
    fputcsv($out, [
        $r['callsign'] ?? '',
        $r['pilot_name'] ?? '',
        $r['attendant_name'] ?? '',
        vf_dt($r['departure_time'] ?? null),
        $r['departure_location'] ?? '',
        vf_dt($r['arrival_time'] ?? null),
        $r['arrival_location'] ?? '',
        $r['flight_minutes'] ?? '',
        $r['landing_count'] ?? 1,
        $r['start_type'] ?? '',
        '',
        '',
        $r['charge_mode'] ?? 2,
        $r['invoiced'] ?? 0,
        $r['comment'] ?? '',
        $r['tow_height_m'] ?? '',
        $r['tow_minutes'] ?? '',
        $r['tow_callsign'] ?? '',
        $r['tow_pilot_name'] ?? '',
        $r['tow_arrival_location'] ?? '',
        $r['vf_flight_type_id'] ?? '',
        $r['km'] ?? '',
        $r['plane_wkz'] ?? '',
        $r['plane_designation'] ?? '',
        '', '', '', '', '', '', '', '', '', ''
    ], ';');
}
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE accounting_entries SET exported_at = NOW(), export_batch = ? WHERE id IN ($placeholders)");
    $upd->execute(array_merge([$batch], $ids));
}
fclose($out);
