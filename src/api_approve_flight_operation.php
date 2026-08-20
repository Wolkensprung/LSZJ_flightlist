<?php
/**
 * api_approve_flight_operation.php
 *
 * Setzt den Status fuer alle accounting_entries einer Operation.
 * Die Operation gilt fachlich als approved, sobald mindestens ein Teil approved ist.
 * Der Button in flight_approvals.php approved jedoch bewusst alle vorhandenen Teile
 * derselben Operation, damit Segelflug + Schleppanteil gemeinsam freigegeben werden.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$operationId = (int)($input['operation_id'] ?? 0);
$action = $input['action'] ?? '';
$user = trim($input['user'] ?? 'unknown');
$note = trim($input['note'] ?? '');

if (!$operationId || !in_array($action, ['approve','request_correction','reset_pending'], true)) {
    json_response(['ok'=>false, 'error'=>'operation_id oder action fehlt'], 400);
}

if ($action === 'approve') {
    $stmt = $pdo->prepare(
        "UPDATE accounting_entries
         SET approval_status='approved', approved_by=?, approved_at=NOW(), correction_note=NULL
         WHERE operation_id=?"
    );
    $stmt->execute([$user, $operationId]);

    $pdo->prepare("UPDATE operations SET approval_status='approved', approved_by=?, approved_at=NOW(), correction_note=NULL WHERE id=?")
        ->execute([$user, $operationId]);
    $status = 'approved';
}
elseif ($action === 'request_correction') {
    $stmt = $pdo->prepare(
        "UPDATE accounting_entries
         SET approval_status='correction_required', approved_by=NULL, approved_at=NULL,
             correction_note=?, exported_at=NULL, export_batch=NULL
         WHERE operation_id=?"
    );
    $stmt->execute([$note, $operationId]);

    $pdo->prepare("UPDATE operations SET approval_status='correction_required', approved_by=NULL, approved_at=NULL, correction_note=? WHERE id=?")
        ->execute([$note, $operationId]);
    $status = 'correction_required';
}
else {
    $stmt = $pdo->prepare(
        "UPDATE accounting_entries
         SET approval_status='pending', approved_by=NULL, approved_at=NULL,
             exported_at=NULL, export_batch=NULL
         WHERE operation_id=?"
    );
    $stmt->execute([$operationId]);

    $pdo->prepare("UPDATE operations SET approval_status='pending', approved_by=NULL, approved_at=NULL WHERE id=?")
        ->execute([$operationId]);
    $status = 'pending';
}

json_response([
    'ok'=>true,
    'operation_id'=>$operationId,
    'approval_status'=>$status,
    'changed'=>$stmt->rowCount(),
]);
