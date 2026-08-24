<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

function vfDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('d.m.Y H:i', $timestamp);
}

function addMinutes(?string $dateTime, mixed $minutes): ?string
{
    if ($dateTime === null || trim($dateTime) === '' || !is_numeric($minutes)) {
        return null;
    }
    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp + ((int)$minutes * 60));
}

$pdo = db();
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = ["approval_status = 'approved'", 'vf_exported_at IS NULL'];
$params = [];
if ($from !== '') {
    $where[] = 'DATE(departure_time) >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'DATE(departure_time) <= ?';
    $params[] = $to;
}

$header = [
    'callsign','pilotname','attendantname','departuretime','departurelocation','arrivaltime',
    'arrivallocation','flighttime','landingcount','starttype','motorstart','motorend','chargemode',
    'invoiced','comment','towheight','towtime','towcallsign','towpilotname','towarrivallocation',
    'ftid','km','planewkz','planedesignation','wid','uidwinch','attendantname2','attendantname3',
    'offblock','onblock','runwaydeparture','runwayarrival','checkin','checkout'
];

$sql = "SELECT id, operation_id, entry_type, callsign, pilot_name, attendant_name,
               departure_time, departure_location, arrival_time, arrival_location,
               flight_minutes, landing_count, start_type, charge_mode, invoiced, comment,
               tow_height_m, tow_minutes, tow_callsign, tow_pilot_name,
               tow_arrival_location, vf_flight_type_id, km, plane_wkz, plane_designation
        FROM accounting_entries
        WHERE " . implode(' AND ', $where) . "
        ORDER BY operation_id, departure_time, id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alle noch exportierbaren Einträge pro Operation gruppieren.
$operations = [];
foreach ($entries as $entry) {
    $operations[(int)$entry['operation_id']][] = $entry;
}

$range = [];
if ($from !== '') $range[] = str_replace('-', '', $from);
if ($to !== '') $range[] = str_replace('-', '', $to);
$suffix = $range === [] ? date('Ymd_His') : implode('_', $range) . '_' . date('His');
$filename = 'flight_import_' . $suffix . '.csv';
$batch = 'VF-FLIGHT-' . date('Ymd-His');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit('CSV-Ausgabe konnte nicht geöffnet werden.');
}
fputcsv($out, $header, ';', '"', '\\');

$exportedIds = [];

$writeRow = static function ($out, array $header, array $entry, ?array $towEntry = null): void {
    $row = array_fill_keys($header, '');
    $type = (string)$entry['entry_type'];

    if ($type === 'glider_flight') {
        $row['callsign'] = (string)($entry['callsign'] ?? '');
        $row['pilotname'] = (string)($entry['pilot_name'] ?? '');
        $row['attendantname'] = (string)($entry['attendant_name'] ?? '');

        // Schleppdaten liegen bei LSZJ teilweise im separaten tow_charge-Eintrag.
        $row['towcallsign'] = (string)(
            $entry['tow_callsign']
            ?? $towEntry['tow_callsign']
            ?? ''
        );
        $row['towpilotname'] = (string)(
            $entry['tow_pilot_name']
            ?? $towEntry['tow_pilot_name']
            ?? ''
        );
        $row['towtime'] = $entry['tow_minutes']
            ?? $towEntry['tow_minutes']
            ?? '';
        $row['towheight'] = $entry['tow_height_m']
            ?? $towEntry['tow_height_m']
            ?? '';
        $row['towarrivallocation'] = (string)(
            $entry['tow_arrival_location']
            ?? $towEntry['tow_arrival_location']
            ?? $towEntry['arrival_location']
            ?? $entry['departure_location']
            ?? ''
        );
    } elseif ($type === 'tow_charge' || $type === 'towplane_own') {
        // Nur für eigenständige bzw. verwaiste Motor-/Schleppflüge.
        $row['callsign'] = trim((string)($entry['tow_callsign'] ?? '')) !== ''
            ? (string)$entry['tow_callsign']
            : (string)($entry['callsign'] ?? '');
        $row['pilotname'] = (string)($entry['tow_pilot_name'] ?? $entry['pilot_name'] ?? '');
    } else {
        $row['callsign'] = (string)($entry['callsign'] ?? '');
        $row['pilotname'] = (string)($entry['pilot_name'] ?? '');
        $row['attendantname'] = (string)($entry['attendant_name'] ?? '');
    }

    $arrival = $entry['arrival_time'] ?? null;
    if (($type === 'tow_charge' || $type === 'towplane_own') && !$arrival) {
        $arrival = addMinutes($entry['departure_time'] ?? null, $entry['tow_minutes'] ?? $entry['flight_minutes'] ?? null);
    }

    $row['departuretime'] = vfDateTime($entry['departure_time'] ?? null);
    $row['departurelocation'] = (string)($entry['departure_location'] ?? '');
    $row['arrivaltime'] = vfDateTime($arrival);
    $row['arrivallocation'] = (string)(
        $entry['arrival_location']
        ?? $entry['tow_arrival_location']
        ?? $entry['departure_location']
        ?? ''
    );
    $row['flighttime'] = $entry['flight_minutes']
        ?? (($type === 'tow_charge' || $type === 'towplane_own') ? ($entry['tow_minutes'] ?? '') : '');
    $row['landingcount'] = $entry['landing_count'] ?? 1;
    $row['starttype'] = $entry['start_type'] ?? '';
    $row['chargemode'] = $entry['charge_mode'] ?? 2;
    $row['invoiced'] = $entry['invoiced'] ?? 0;
    $row['comment'] = (string)($entry['comment'] ?? '');
    $row['ftid'] = $entry['vf_flight_type_id'] ?? '';
    $row['km'] = $entry['km'] ?? '';
    $row['planewkz'] = (string)($entry['plane_wkz'] ?? '');
    $row['planedesignation'] = (string)($entry['plane_designation'] ?? '');

    fputcsv($out, array_values($row), ';', '"', '\\');
};

foreach ($operations as $operationEntries) {
    $gliders = [];
    $towCharges = [];
    $others = [];

    foreach ($operationEntries as $entry) {
        if ($entry['entry_type'] === 'glider_flight') {
            $gliders[] = $entry;
        } elseif ($entry['entry_type'] === 'tow_charge') {
            $towCharges[] = $entry;
        } else {
            $others[] = $entry;
        }
    }

    // Ein gekoppelter tow_charge wird NICHT als zweite CSV-Zeile exportiert.
    // Vereinsflieger erzeugt den Schleppflug aus den Schleppfeldern des Segelflugs.
    foreach ($gliders as $index => $glider) {
        $tow = $towCharges[$index] ?? ($towCharges[0] ?? null);
        $writeRow($out, $header, $glider, $tow);
        $exportedIds[] = (int)$glider['id'];
        if ($tow !== null) {
            $exportedIds[] = (int)$tow['id'];
        }
    }

    // Nur Schleppdatensätze ohne zugehörigen Segelflug separat exportieren.
    if ($gliders === []) {
        foreach ($towCharges as $tow) {
            $writeRow($out, $header, $tow);
            $exportedIds[] = (int)$tow['id'];
        }
    }

    // Eigenständige Motorflüge, manuelle Einträge usw. separat exportieren.
    foreach ($others as $entry) {
        $writeRow($out, $header, $entry);
        $exportedIds[] = (int)$entry['id'];
    }
}

fflush($out);
fclose($out);

$exportedIds = array_values(array_unique($exportedIds));
if ($exportedIds !== []) {
    $placeholders = implode(',', array_fill(0, count($exportedIds), '?'));
    $update = $pdo->prepare(
        "UPDATE accounting_entries
         SET vf_exported_at = NOW(),
             exported_at = COALESCE(exported_at, NOW()),
             export_batch = ?,
             approval_status = 'exported'
         WHERE approval_status = 'approved'
           AND vf_exported_at IS NULL
           AND id IN ($placeholders)"
    );
    $update->execute(array_merge([$batch], $exportedIds));
}
