<?php
/**
 * api_create_manual_flight.php
 *
 * Manuelle Flugerfassung mit Duplikat-/Plausibilitaetspruefung.
 * Fix: check_manual_entry akzeptiert nullable callsign und gibt bei fehlenden
 * Pflichtfeldern verstaendliche Fehlermeldungen aus statt PHP-Fatal-Errors.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/motor_minutes.php';
require_once __DIR__ . '/api_authenticated_actor.php';

$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$mode = $input['mode'] ?? '';
$date = $input['date'] ?? date('Y-m-d');
$force = !empty($input['force']);

function v(array $a, string $k, $d = null) {
    return array_key_exists($k, $a) && $a[$k] !== '' ? $a[$k] : $d;
}
function dtv(string $date, $time): ?string {
    return $time ? dt_string($date, $time) : null;
}
function mins_v(string $date, $start, $end): ?int {
    return ($start && $end) ? minutes_between($date, $start, $end) : null;
}
function int_or_null($value): ?int {
    return ($value === '' || $value === null) ? null : (int)$value;
}
function require_field($value, string $message): void {
    if ($value === null || $value === '') {
        json_response(['ok' => false, 'error' => $message], 400);
    }
}
function ensure_no_overnight($start, $end, string $message): void {
    if ($start && $end && strcmp($end, $start) < 0) {
        json_response(['ok' => false, 'error' => $message], 400);
    }
}
function insert_row(PDO $pdo, string $table, array $data): int {
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($data as $col => $value) {
        $stmt->bindValue(':' . $col, $value);
    }
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}
function update_row(PDO $pdo, string $table, array $data, string $where, array $whereParams): void {
    $sets = [];
    foreach ($data as $col => $_) {
        $sets[] = $col . ' = :' . $col;
    }
    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE ' . $where);
    foreach ($data as $col => $value) {
        $stmt->bindValue(':' . $col, $value);
    }
    foreach ($whereParams as $col => $value) {
        $stmt->bindValue(':' . $col, $value);
    }
    $stmt->execute();
}
function upsert_tow_segment(PDO $pdo, array $data): void {
    $cols = array_keys($data);
    $updates = [];
    foreach ($cols as $col) {
        if ($col !== 'operation_id') {
            $updates[] = $col . ' = VALUES(' . $col . ')';
        }
    }
    $updates[] = 'updated_at = CURRENT_TIMESTAMP';
    $sql = 'INSERT INTO tow_segments (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $stmt = $pdo->prepare($sql);
    foreach ($data as $col => $value) {
        $stmt->bindValue(':' . $col, $value);
    }
    $stmt->execute();
}

function check_manual_entry(PDO $pdo, string $entryType, ?string $callsign, ?string $dep, ?string $arr, ?string $pilot, bool $force): array {
    $warnings = [];

    // Fix: missing callsign must never trigger a PHP type error.
    // Required field validation is handled before this function is called.
    if (!$callsign || !$dep) {
        return ['ok' => true, 'warnings' => []];
    }

    // Exact duplicate: same type, callsign, start and landing.
    if ($arr === null) {
        $stmt = $pdo->prepare("SELECT id FROM accounting_entries WHERE entry_type=? AND callsign=? AND departure_time=? AND arrival_time IS NULL LIMIT 1");
        $stmt->execute([$entryType, $callsign, $dep]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM accounting_entries WHERE entry_type=? AND callsign=? AND departure_time=? AND arrival_time=? LIMIT 1");
        $stmt->execute([$entryType, $callsign, $dep, $arr]);
    }
    if ($row = $stmt->fetch()) {
        return ['ok' => false, 'error' => 'Dieser Flug existiert bereits exakt in accounting_entries (ID ' . $row['id'] . ').'];
    }

    // Likely duplicate: same aircraft, same day, start +/- 15 minutes.
    $stmt = $pdo->prepare("SELECT id, entry_type, callsign, pilot_name, tow_pilot_name, departure_time, arrival_time
                           FROM accounting_entries
                           WHERE callsign=?
                             AND DATE(departure_time)=DATE(?)
                             AND ABS(TIMESTAMPDIFF(MINUTE, departure_time, ?)) <= 15
                           ORDER BY departure_time
                           LIMIT 5");
    $stmt->execute([$callsign, $dep, $dep]);
    foreach ($stmt->fetchAll() as $row) {
        $samePilot = !$pilot || $pilot === ($row['pilot_name'] ?? null) || $pilot === ($row['tow_pilot_name'] ?? null);
        if ($samePilot) {
            $warnings[] = 'Möglicher Doppel-Eintrag: ' . $row['entry_type'] . ' ' . $row['callsign'] . ' Start ' . $row['departure_time'] . ' (ID ' . $row['id'] . ').';
        }
    }

    // Overlap same aircraft.
    if ($arr !== null) {
        $stmt = $pdo->prepare("SELECT id, entry_type, departure_time, arrival_time
                               FROM accounting_entries
                               WHERE callsign=?
                                 AND departure_time IS NOT NULL
                                 AND arrival_time IS NOT NULL
                                 AND departure_time < ?
                                 AND arrival_time > ?
                               ORDER BY departure_time
                               LIMIT 5");
        $stmt->execute([$callsign, $arr, $dep]);
        foreach ($stmt->fetchAll() as $row) {
            $warnings[] = 'Zeitüberschneidung für ' . $callsign . ': ' . $row['departure_time'] . ' bis ' . $row['arrival_time'] . ' (ID ' . $row['id'] . ').';
        }
    }

    // KTrax check only for real flight entries. For tow_charge we intentionally attach to an existing glider flight.
    if ($entryType !== 'tow_charge') {
        $stmt = $pdo->prepare("SELECT e.id, e.entry_type, e.departure_time
                               FROM accounting_entries e
                               JOIN operations o ON o.id = e.operation_id
                               WHERE o.created_from='ktrax'
                                 AND e.entry_type=?
                                 AND e.callsign=?
                                 AND DATE(e.departure_time)=DATE(?)
                                 AND ABS(TIMESTAMPDIFF(MINUTE, e.departure_time, ?)) <= 15
                               ORDER BY e.departure_time
                               LIMIT 5");
        $stmt->execute([$entryType, $callsign, $dep, $dep]);
        foreach ($stmt->fetchAll() as $row) {
            $warnings[] = 'Möglicher KTrax-Treffer: ' . $row['entry_type'] . ' Start ' . $row['departure_time'] . ' (ID ' . $row['id'] . ').';
        }
    }

    // Plausibility.
    if ($arr !== null) {
        $minutes = (int)((strtotime($arr) - strtotime($dep)) / 60);
        if ($minutes < 1) $warnings[] = 'Plausibilität: Flugdauer kleiner als 1 Minute.';
        if ($entryType === 'glider_flight' && $minutes > 720) $warnings[] = 'Plausibilität: Segelflugdauer über 12 Stunden.';
        if ($entryType === 'tow_charge' && $minutes > 20) $warnings[] = 'Plausibilität: Schleppdauer über 20 Minuten.';
    }

    if ($warnings && !$force) {
        return ['ok' => false, 'warning' => true, 'warnings' => $warnings, 'error' => 'Mögliche Auffälligkeit bei der manuellen Erfassung.'];
    }
    return ['ok' => true, 'warnings' => $warnings];
}
function run_checks(PDO $pdo, array $checks, bool $force): array {
    $all = [];
    foreach ($checks as $check) {
        $result = check_manual_entry($pdo, $check['entry_type'], $check['callsign'], $check['dep'], $check['arr'], $check['pilot'] ?? null, $force);
        if (!$result['ok'] && empty($result['warning'])) return $result;
        if (!empty($result['warnings'])) $all = array_merge($all, $result['warnings']);
        if (!empty($result['warning']) && !$force) return $result;
    }
    return ['ok' => true, 'warnings' => $all];
}

function op_data(string $date, string $kind, array $input, array $extra = []): array {
    return array_merge([
        'operation_date' => $date,
        'kind' => $kind,
        'glider_callsign' => null,
        'glider_pilot_name' => null,
        'instructor_name' => null,
        'tow_callsign' => null,
        'takeoff_time' => v($input, 'takeoff_time'),
        'takeoff_airfield' => v($input, 'departure_location', 'LSZJ'),
        'glider_landing_time' => null,
        'glider_landing_airfield' => null,
        'tow_height_m' => null,
        'created_from' => 'manual',
        'approval_status' => 'pending',
        'correction_note' => 'Manuell erfasst',
    ], $extra);
}
function glider_entry(int $op, string $date, array $input, string $glider, $takeoff, $landing, ?string $towCallsign = null, int $startType = 1): array {
    return [
        'operation_id' => $op,
        'entry_type' => 'glider_flight',
        'callsign' => $glider,
        'pilot_name' => v($input, 'pilot_name'),
        'attendant_name' => v($input, 'attendant_name'),
        'departure_time' => dtv($date, $takeoff),
        'departure_location' => v($input, 'departure_location', 'LSZJ'),
        'arrival_time' => dtv($date, $landing),
        'arrival_location' => v($input, 'arrival_location', 'LSZJ'),
        'flight_minutes' => mins_v($date, $takeoff, $landing),
        'motor_minutes' => motor_minutes_normalize(v($input, 'motor_minutes')),
        'landing_count' => 1,
        'start_type' => $startType,
        'charge_mode' => (int)v($input, 'charge_mode', 2),
        'invoiced' => 0,
        'comment' => v($input, 'comment', 'Manuell erfasster Segelflug'),
        'tow_height_m' => null,
        'tow_minutes' => null,
        'tow_callsign' => $towCallsign,
        'tow_pilot_name' => null,
        'tow_arrival_location' => null,
        'vf_flight_type_id' => int_or_null(v($input, 'vf_flight_type_id')),
        'approval_role' => 'glider_pilot',
        'approval_status' => 'pending',
        'correction_note' => null,
    ];
}
function tow_own_entry(int $op, string $date, array $input, string $plane, $takeoff, $landing): array {
    return [
        'operation_id' => $op,
        'entry_type' => 'towplane_own',
        'callsign' => $plane,
        'pilot_name' => null,
        'attendant_name' => null,
        'departure_time' => dtv($date, $takeoff),
        'departure_location' => v($input, 'departure_location', 'LSZJ'),
        'arrival_time' => dtv($date, $landing),
        'arrival_location' => v($input, 'arrival_location', 'LSZJ'),
        'flight_minutes' => mins_v($date, $takeoff, $landing),
        'landing_count' => 1,
        'start_type' => 1,
        'charge_mode' => (int)v($input, 'charge_mode', 2),
        'invoiced' => 0,
        'comment' => v($input, 'comment', 'Manuell erfasster Motorflug'),
        'tow_height_m' => null,
        'tow_minutes' => null,
        'tow_callsign' => null,
        'tow_pilot_name' => v($input, 'tow_pilot_name'),
        'tow_arrival_location' => null,
        'vf_flight_type_id' => int_or_null(v($input, 'vf_flight_type_id')),
        'approval_role' => 'tow_pilot',
        'approval_status' => 'pending',
        'correction_note' => null,
    ];
}
function tow_charge_entry(int $op, string $date, array $input, string $glider, string $plane, $takeoff, $towLanding): array {
    return [
        'operation_id' => $op,
        'entry_type' => 'tow_charge',
        'callsign' => $glider,
        'pilot_name' => null,
        'attendant_name' => null,
        'departure_time' => dtv($date, $takeoff),
        'departure_location' => v($input, 'departure_location', 'LSZJ'),
        'arrival_time' => null,
        'arrival_location' => null,
        'flight_minutes' => null,
        'landing_count' => 1,
        'start_type' => 3,
        'charge_mode' => (int)v($input, 'charge_mode', 2),
        'invoiced' => 0,
        'comment' => v($input, 'tow_comment', 'Manuell erfasster Schleppanteil'),
        'tow_height_m' => null,
        'tow_minutes' => mins_v($date, $takeoff, $towLanding),
        'tow_callsign' => $plane,
        'tow_pilot_name' => v($input, 'tow_pilot_name'),
        'tow_arrival_location' => v($input, 'tow_arrival_location'),
        'vf_flight_type_id' => 3,
        'approval_role' => 'tow_pilot',
        'approval_status' => 'pending',
        'correction_note' => null,
    ];
}
function tow_segment_data(int $op, string $date, array $input, string $glider, string $plane, $takeoff, $towLanding): array {
    return [
        'operation_id' => $op,
        'glider_raw_flight_id' => null,
        'tow_raw_flight_id' => null,
        'glider_callsign' => $glider,
        'tow_callsign' => $plane,
        'tow_pilot_name' => v($input, 'tow_pilot_name'),
        'segment_start' => dtv($date, $takeoff),
        'segment_end' => dtv($date, $towLanding),
        'tow_minutes' => mins_v($date, $takeoff, $towLanding),
        'tow_height_m' => null,
        'cost_center' => 'tow',
        'approval_status' => 'pending',
        'correction_note' => 'Manuell erfasster Schlepp',
    ];
}

try {
    $checks = [];
    $operationId = null;

    // Required field checks first. This prevents PHP fatal errors from NULL callsigns.
    if ($mode === 'glider_only') {
        require_field(v($input, 'glider_callsign'), 'Segelflugzeug fehlt.');
        require_field(v($input, 'pilot_name'), 'Segelflugpilot fehlt.');
        require_field(v($input, 'takeoff_time'), 'Startzeit fehlt.');
        require_field(v($input, 'landing_time'), 'Landezeit fehlt.');
        require_field(v($input, 'vf_flight_type_id'), 'Flugart fehlt.');
        require_field(v($input, 'charge_mode'), 'Abrechnungsart fehlt.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'landing_time'), 'Landezeit darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        $checks[] = ['entry_type'=>'glider_flight','callsign'=>v($input,'glider_callsign'),'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>dtv($date,v($input,'landing_time')),'pilot'=>v($input,'pilot_name')];
    } elseif ($mode === 'towplane_only') {
        require_field(v($input, 'tow_callsign'), 'Motorflugzeug fehlt.');
        require_field(v($input, 'tow_pilot_name'), 'Motorpilot fehlt.');
        require_field(v($input, 'takeoff_time'), 'Startzeit fehlt.');
        require_field(v($input, 'landing_time'), 'Landezeit fehlt.');
        require_field(v($input, 'vf_flight_type_id'), 'Flugart fehlt.');
        require_field(v($input, 'charge_mode'), 'Abrechnungsart fehlt.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'landing_time'), 'Landezeit darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        $checks[] = ['entry_type'=>'towplane_own','callsign'=>v($input,'tow_callsign'),'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>dtv($date,v($input,'landing_time')),'pilot'=>v($input,'tow_pilot_name')];
    } elseif ($mode === 'pair') {
        require_field(v($input, 'glider_callsign'), 'Segelflugzeug fehlt.');
        require_field(v($input, 'pilot_name'), 'Segelflugpilot fehlt.');
        require_field(v($input, 'tow_callsign'), 'Motorflugzeug fehlt.');
        require_field(v($input, 'tow_pilot_name'), 'Motorpilot fehlt.');
        require_field(v($input, 'takeoff_time'), 'Startzeit fehlt.');
        require_field(v($input, 'tow_landing_time'), 'Landung Motorflugzeug fehlt.');
        require_field(v($input, 'glider_landing_time'), 'Landung Segelflugzeug fehlt.');
        require_field(v($input, 'vf_flight_type_id'), 'Flugart Segelflug fehlt.');
        require_field(v($input, 'charge_mode'), 'Abrechnungsart Segelflug fehlt.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'tow_landing_time'), 'Landung Motorflugzeug darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'glider_landing_time'), 'Landung Segelflugzeug darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        $checks[] = ['entry_type'=>'glider_flight','callsign'=>v($input,'glider_callsign'),'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>dtv($date,v($input,'glider_landing_time')),'pilot'=>v($input,'pilot_name')];
        $checks[] = ['entry_type'=>'tow_charge','callsign'=>v($input,'glider_callsign'),'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>null,'pilot'=>v($input,'tow_pilot_name')];
    } elseif ($mode === 'attach_tow_to_glider') {
        require_field(v($input, 'operation_id'), 'Operation fehlt.');
        require_field(v($input, 'tow_callsign'), 'Motorflugzeug fehlt.');
        require_field(v($input, 'tow_pilot_name'), 'Motorpilot fehlt.');
        require_field(v($input, 'takeoff_time'), 'Startzeit fehlt.');
        require_field(v($input, 'tow_landing_time'), 'Landung Motorflugzeug fehlt.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'tow_landing_time'), 'Landung Motorflugzeug darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        $stmt = $pdo->prepare('SELECT glider_callsign FROM operations WHERE id=?');
        $stmt->execute([(int)v($input, 'operation_id')]);
        $op = $stmt->fetch();
        if (!$op || !$op['glider_callsign']) json_response(['ok'=>false,'error'=>'Segelflug-Operation nicht gefunden oder ohne Segelflugzeug.'], 400);
        $checks[] = ['entry_type'=>'tow_charge','callsign'=>$op['glider_callsign'],'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>null,'pilot'=>v($input,'tow_pilot_name')];
    } elseif ($mode === 'attach_glider_to_towplane') {
        require_field(v($input, 'operation_id'), 'Operation fehlt.');
        require_field(v($input, 'glider_callsign'), 'Segelflugzeug fehlt.');
        require_field(v($input, 'pilot_name'), 'Segelflugpilot fehlt.');
        require_field(v($input, 'takeoff_time'), 'Startzeit fehlt.');
        require_field(v($input, 'tow_landing_time'), 'Landung Motorflugzeug fehlt.');
        require_field(v($input, 'glider_landing_time'), 'Landung Segelflugzeug fehlt.');
        require_field(v($input, 'vf_flight_type_id'), 'Flugart Segelflug fehlt.');
        require_field(v($input, 'charge_mode'), 'Abrechnungsart Segelflug fehlt.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'tow_landing_time'), 'Landung Motorflugzeug darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        ensure_no_overnight(v($input, 'takeoff_time'), v($input, 'glider_landing_time'), 'Landung Segelflugzeug darf nicht vor der Startzeit liegen. Ein Flug dauert nie über Mitternacht.');
        $checks[] = ['entry_type'=>'glider_flight','callsign'=>v($input,'glider_callsign'),'dep'=>dtv($date,v($input,'takeoff_time')),'arr'=>dtv($date,v($input,'glider_landing_time')),'pilot'=>v($input,'pilot_name')];
    } else {
        json_response(['ok'=>false,'error'=>'Unbekannter Modus.'], 400);
    }

    $checkResult = run_checks($pdo, $checks, $force);
    if (!$checkResult['ok']) json_response($checkResult, 409);

    $pdo->beginTransaction();

    if ($mode === 'glider_only') {
        $glider = v($input, 'glider_callsign');
        $takeoff = v($input, 'takeoff_time');
        $landing = v($input, 'landing_time');
        $operationId = insert_row($pdo, 'operations', op_data($date, 'self_launch', $input, [
            'glider_callsign' => $glider,
            'glider_pilot_name' => v($input, 'pilot_name'),
            'instructor_name' => v($input, 'attendant_name'),
            'glider_landing_time' => $landing,
            'glider_landing_airfield' => v($input, 'arrival_location', 'LSZJ'),
            'tow_height_m' => null,
            'correction_note' => 'Manuell erfasster Segelflug ohne FLARM',
        ]));
        insert_row($pdo, 'accounting_entries', glider_entry($operationId, $date, $input, $glider, $takeoff, $landing));
    } elseif ($mode === 'towplane_only') {
        $plane = v($input, 'tow_callsign');
        $takeoff = v($input, 'takeoff_time');
        $landing = v($input, 'landing_time');
        $operationId = insert_row($pdo, 'operations', op_data($date, 'towplane_only', $input, [
            'tow_callsign' => $plane,
            'tow_height_m' => null,
            'correction_note' => 'Manuell erfasster Motorflug ohne FLARM',
        ]));
        insert_row($pdo, 'accounting_entries', tow_own_entry($operationId, $date, $input, $plane, $takeoff, $landing));
    } elseif ($mode === 'pair') {
        $glider = v($input, 'glider_callsign');
        $plane = v($input, 'tow_callsign');
        $takeoff = v($input, 'takeoff_time');
        $towLanding = v($input, 'tow_landing_time');
        $gliderLanding = v($input, 'glider_landing_time');
        $operationId = insert_row($pdo, 'operations', op_data($date, 'glider_tow', $input, [
            'glider_callsign' => $glider,
            'glider_pilot_name' => v($input, 'pilot_name'),
            'instructor_name' => v($input, 'attendant_name'),
            'tow_callsign' => $plane,
            'glider_landing_time' => $gliderLanding,
            'glider_landing_airfield' => v($input, 'arrival_location', 'LSZJ'),
            'tow_height_m' => null,
            'correction_note' => 'Manuell erfasster Segelflug mit Motorflug',
        ]));
        insert_row($pdo, 'accounting_entries', glider_entry($operationId, $date, $input, $glider, $takeoff, $gliderLanding, $plane, 3));
        upsert_tow_segment($pdo, tow_segment_data($operationId, $date, $input, $glider, $plane, $takeoff, $towLanding));
        insert_row($pdo, 'accounting_entries', tow_charge_entry($operationId, $date, $input, $glider, $plane, $takeoff, $towLanding));
    } elseif ($mode === 'attach_tow_to_glider') {
        $operationId = (int)v($input, 'operation_id');
        $plane = v($input, 'tow_callsign');
        $takeoff = v($input, 'takeoff_time');
        $towLanding = v($input, 'tow_landing_time');
        $stmt = $pdo->prepare('SELECT glider_callsign FROM operations WHERE id=?');
        $stmt->execute([$operationId]);
        $op = $stmt->fetch();
        $glider = $op['glider_callsign'];
        $chargeStmt = $pdo->prepare("SELECT charge_mode FROM accounting_entries WHERE operation_id=? AND entry_type='glider_flight' LIMIT 1");
        $chargeStmt->execute([$operationId]);
        $existingChargeMode = $chargeStmt->fetchColumn();
        if ($existingChargeMode !== false && $existingChargeMode !== null && $existingChargeMode !== '') {
            $input['charge_mode'] = $existingChargeMode;
        }
        update_row($pdo, 'operations', [
            'kind' => 'glider_tow',
            'tow_callsign' => $plane,
            'tow_height_m' => null,
            'approval_status' => 'pending',
            'correction_note' => 'Manueller Schlepp zugeordnet',
        ], 'id=:id', ['id' => $operationId]);
        update_row($pdo, 'accounting_entries', [
            'start_type' => 3,
            'tow_callsign' => $plane,
            'tow_height_m' => null,
            'approval_status' => 'pending',
            'exported_at' => null,
            'export_batch' => null,
        ], "operation_id=:operation_id AND entry_type='glider_flight'", ['operation_id' => $operationId]);
        upsert_tow_segment($pdo, tow_segment_data($operationId, $date, $input, $glider, $plane, $takeoff, $towLanding));
        insert_row($pdo, 'accounting_entries', tow_charge_entry($operationId, $date, $input, $glider, $plane, $takeoff, $towLanding));
    } elseif ($mode === 'attach_glider_to_towplane') {
        $operationId = (int)v($input, 'operation_id');
        $glider = v($input, 'glider_callsign');
        $takeoff = v($input, 'takeoff_time');
        $towLanding = v($input, 'tow_landing_time');
        $gliderLanding = v($input, 'glider_landing_time');
        $stmt = $pdo->prepare('SELECT tow_callsign, takeoff_time, takeoff_airfield FROM operations WHERE id=?');
        $stmt->execute([$operationId]);
        $op = $stmt->fetch();
        if (!$op || !$op['tow_callsign']) json_response(['ok'=>false,'error'=>'Motorflug-Operation nicht gefunden.'], 400);
        $plane = $op['tow_callsign'];
        update_row($pdo, 'operations', [
            'kind' => 'glider_tow',
            'glider_callsign' => $glider,
            'glider_pilot_name' => v($input, 'pilot_name'),
            'instructor_name' => v($input, 'attendant_name'),
            'glider_landing_time' => $gliderLanding,
            'glider_landing_airfield' => v($input, 'arrival_location', 'LSZJ'),
            'tow_height_m' => null,
            'approval_status' => 'pending',
            'correction_note' => 'Manueller Segelflug zu Motorflug zugeordnet',
        ], 'id=:id', ['id' => $operationId]);
        insert_row($pdo, 'accounting_entries', glider_entry($operationId, $date, $input, $glider, $takeoff, $gliderLanding, $plane, 3));
        upsert_tow_segment($pdo, tow_segment_data($operationId, $date, $input, $glider, $plane, $takeoff, $towLanding));
        update_row($pdo, 'accounting_entries', [
            'entry_type' => 'tow_charge',
            'callsign' => $glider,
            'tow_callsign' => $plane,
            'tow_minutes' => mins_v($date, $takeoff, $towLanding),
            'tow_height_m' => null,
            'vf_flight_type_id' => 3,
            'charge_mode' => (int)v($input, 'charge_mode', 2),
            'comment' => 'Manuell zugeordneter F-Schlepp',
            'approval_status' => 'pending',
            'exported_at' => null,
            'export_batch' => null,
        ], "operation_id=:operation_id AND entry_type='towplane_own'", ['operation_id' => $operationId]);
    }

    $pdo->commit();
    json_response(['ok'=>true, 'mode'=>$mode, 'operation_id'=>$operationId, 'warnings'=>$checkResult['warnings'] ?? []]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
