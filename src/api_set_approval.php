<?php
/**
 * api_set_approval.php
 *
 * Setzt den Freigabestatus fuer accounting_entries, tow_segments oder operations.
 *
 * Aktionen:
 * - approve: setzt approved
 * - request_correction: setzt correction_required
 * - reset_pending: setzt pending
 *
 * Re-Export-Logik:
 * - Wenn ein accounting_entry auf correction_required oder pending gesetzt wird,
 *   wird exported_at/export_batch geloescht.
 * - Dadurch wird ein spaeter erneut freigegebener Eintrag wieder exportfaehig.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/flight_validation.php';

$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$entity = $input['entity'] ?? '';
$id = (int)($input['id'] ?? 0);
$action = $input['action'] ?? '';
$user = $actor['display_name'];
$userId = $actor['id'];
$note = trim($input['note'] ?? '');

$tables = [
    'accounting_entry' => 'accounting_entries',
    'tow_segment' => 'tow_segments',
    'operation' => 'operations',
];

if (!$id || !isset($tables[$entity])) {
    json_response(['error' => 'entity oder id fehlt'], 400);
}

if (!in_array($action, ['approve', 'request_correction', 'reset_pending'], true)) {
    json_response(['error' => 'Ungueltige action'], 400);
}

$table = $tables[$entity];

if ($action === 'approve' && $entity === 'accounting_entry') {
    $check=$pdo->prepare('SELECT * FROM accounting_entries WHERE id=?');$check->execute([$id]);$entry=$check->fetch();
    if(!$entry) json_response(['ok'=>false,'error'=>'Eintrag nicht gefunden.'],404);
    $missing=flight_entry_missing_fields($entry);
    if($missing) json_response(['ok'=>false,'error'=>'Freigabe nicht möglich. Pflichtfelder fehlen oder sind ungültig.','missing_fields'=>[['entry_id'=>$id,'entry_type'=>$entry['entry_type'],'callsign'=>$entry['callsign'],'fields'=>$missing]]],422);
}
if ($action === 'approve' && $entity === 'operation') {
    $issues=flight_operation_validation($pdo,$id);
    if($issues) json_response(['ok'=>false,'error'=>'Freigabe nicht möglich. Pflichtfelder fehlen oder sind ungültig.','missing_fields'=>$issues],422);
}

if ($action === 'approve') {
    $status = 'approved';
    $sql = "UPDATE {$table}
            SET approval_status = ?, approved_by = ?, approved_by_user_id = ?, approved_at = NOW(), correction_note = NULL
            WHERE id = ?";
    $params = [$status, $user, $userId, $id];
} elseif ($action === 'request_correction') {
    $status = 'correction_required';

    if ($entity === 'accounting_entry') {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_by_user_id = NULL, approved_at = NULL,
                    correction_note = ?, exported_at = NULL, vf_exported_at = NULL, export_batch = NULL
                WHERE id = ?";
        $params = [$status, $note, $id];
    } else {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_by_user_id = NULL, approved_at = NULL,
                    correction_note = ?
                WHERE id = ?";
        $params = [$status, $note, $id];
    }
} else {
    $status = 'pending';

    if ($entity === 'accounting_entry') {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_by_user_id = NULL, approved_at = NULL,
                    exported_at = NULL, vf_exported_at = NULL, export_batch = NULL
                WHERE id = ?";
        $params = [$status, $id];
    } else {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_by_user_id = NULL, approved_at = NULL
                WHERE id = ?";
        $params = [$status, $id];
    }
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

json_response([
    'ok' => true,
    'entity' => $entity,
    'id' => $id,
    'approval_status' => $status,
    'changed' => $stmt->rowCount(),
    'export_reset' => $entity === 'accounting_entry' && in_array($action, ['request_correction','reset_pending'], true),
]);
