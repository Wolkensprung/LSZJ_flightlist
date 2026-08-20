<?php
/**
 * api_flight_approvals.php
 * Supports single day and date range filters.
 * Parameters:
 * - date=YYYY-MM-DD, backwards compatible
 * - from=YYYY-MM-DD&to=YYYY-MM-DD, preferred
 * - status=pending|correction_required|approved|exported|all
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$pdo = db();
$from = $_GET['from'] ?? ($_GET['date'] ?? date('Y-m-d'));
$to = $_GET['to'] ?? $from;
$status = $_GET['status'] ?? 'pending';

$opsStmt = $pdo->prepare(
    "SELECT id, operation_date, kind, glider_callsign, tow_callsign, takeoff_time, takeoff_airfield,
            glider_landing_time, glider_landing_airfield, tow_height_m, created_from, approval_status, correction_note
     FROM operations
     WHERE operation_date BETWEEN ? AND ?
     ORDER BY operation_date, takeoff_time, id"
);
$opsStmt->execute([$from, $to]);
$ops = $opsStmt->fetchAll();

$entryStmt = $pdo->prepare(
    "SELECT e.*, ft.code AS flight_type_code, ft.name AS flight_type_name, cm.name AS charge_mode_name
     FROM accounting_entries e
     LEFT JOIN flight_types ft ON ft.id = e.vf_flight_type_id
     LEFT JOIN charge_modes cm ON cm.id = e.charge_mode
     WHERE e.operation_id = ?
     ORDER BY FIELD(e.entry_type, 'glider_flight', 'tow_charge', 'towplane_own'), e.id"
);
$towSegStmt = $pdo->prepare("SELECT * FROM tow_segments WHERE operation_id = ? LIMIT 1");

$rows = [];
$counts = ['pending'=>0, 'correction_required'=>0, 'approved'=>0, 'exported'=>0, 'all'=>0];

foreach ($ops as $op) {
    $entryStmt->execute([$op['id']]);
    $entries = $entryStmt->fetchAll();
    $towSegStmt->execute([$op['id']]);
    $towSegment = $towSegStmt->fetch() ?: null;
    $gliderEntry = null; $towCharge = null; $towplaneOwn = null; $statuses = [];
    foreach ($entries as $e) {
        $statuses[] = $e['approval_status'];
        if ($e['entry_type'] === 'glider_flight') $gliderEntry = $e;
        if ($e['entry_type'] === 'tow_charge') $towCharge = $e;
        if ($e['entry_type'] === 'towplane_own') $towplaneOwn = $e;
    }
    if (in_array('approved', $statuses, true)) $combinedStatus = 'approved';
    elseif (in_array('correction_required', $statuses, true)) $combinedStatus = 'correction_required';
    elseif (in_array('exported', $statuses, true)) $combinedStatus = 'exported';
    else $combinedStatus = 'pending';
    $counts[$combinedStatus] = ($counts[$combinedStatus] ?? 0) + 1;
    $counts['all']++;
    if ($status !== 'all' && $combinedStatus !== $status) continue;
    $hasGlider = $gliderEntry !== null;
    $hasTow = $towCharge !== null || $towSegment !== null;
    $hasTowplaneOnly = $towplaneOwn !== null && !$hasGlider && !$hasTow;
    $rows[] = [
        'operation'=>$op,
        'status'=>$combinedStatus,
        'entries'=>$entries,
        'glider_entry'=>$gliderEntry,
        'tow_charge'=>$towCharge,
        'towplane_own'=>$towplaneOwn,
        'tow_segment'=>$towSegment,
        'has_glider'=>$hasGlider,
        'has_tow'=>$hasTow,
        'has_towplane_only'=>$hasTowplaneOnly,
        'needs_tow_for_glider'=>$hasGlider && !$hasTow,
        'needs_glider_for_towplane'=>$hasTowplaneOnly,
    ];
}

json_response(['ok'=>true,'from'=>$from,'to'=>$to,'status_filter'=>$status,'counts'=>$counts,'rows'=>$rows]);
