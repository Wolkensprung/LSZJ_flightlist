<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$date = $input['date'] ?? date('Y-m-d');
$type = $input['entry_type'] ?? 'manual';
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO operations (operation_date, kind, glider_callsign, tow_callsign, takeoff_time, takeoff_airfield, glider_landing_time, glider_landing_airfield, created_from, status)
        VALUES (?, 'manual', ?, ?, ?, ?, ?, ?, 'manual', 'in_review')")
        ->execute([$date, $input['callsign'] ?? null, $input['tow_callsign'] ?? null, $input['takeoff_time'] ?? null, $input['departure_location'] ?? null, $input['landing_time'] ?? null, $input['arrival_location'] ?? null]);
    $opId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO accounting_entries
        (operation_id, entry_type, callsign, pilot_name, attendant_name, departure_time, departure_location, arrival_time, arrival_location, flight_minutes, landing_count, start_type, comment, tow_height_m, tow_minutes, tow_callsign, vf_flight_type_id, approval_role, cost_center)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $opId, $type, $input['callsign'] ?? null, $input['pilot_name'] ?? null, $input['attendant_name'] ?? null,
            dt_string($date, $input['takeoff_time'] ?? null), $input['departure_location'] ?? null,
            dt_string($date, $input['landing_time'] ?? null), $input['arrival_location'] ?? null,
            $input['flight_minutes'] ?? null, $input['start_type'] ?? null, $input['comment'] ?? null,
            $input['tow_height_m'] ?? null, $input['tow_minutes'] ?? null, $input['tow_callsign'] ?? null,
            $input['vf_flight_type_id'] ?? null, $input['approval_role'] ?? 'dispatcher', $input['cost_center'] ?? null
        ]);
    $pdo->commit();
    json_response(['ok' => true, 'operation_id' => $opId]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => $e->getMessage()], 500);
}
