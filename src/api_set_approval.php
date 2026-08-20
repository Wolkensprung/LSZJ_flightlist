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

$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$entity = $input['entity'] ?? '';
$id = (int)($input['id'] ?? 0);
$action = $input['action'] ?? '';
$user = trim($input['user'] ?? 'unknown');
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

if ($action === 'approve') {
    $status = 'approved';
    $sql = "UPDATE {$table}
            SET approval_status = ?, approved_by = ?, approved_at = NOW(), correction_note = NULL
            WHERE id = ?";
    $params = [$status, $user, $id];
} elseif ($action === 'request_correction') {
    $status = 'correction_required';

    if ($entity === 'accounting_entry') {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_at = NULL,
                    correction_note = ?, exported_at = NULL, export_batch = NULL
                WHERE id = ?";
        $params = [$status, $note, $id];
    } else {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_at = NULL,
                    correction_note = ?
                WHERE id = ?";
        $params = [$status, $note, $id];
    }
} else {
    $status = 'pending';

    if ($entity === 'accounting_entry') {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_at = NULL,
                    exported_at = NULL, export_batch = NULL
                WHERE id = ?";
        $params = [$status, $id];
    } else {
        $sql = "UPDATE {$table}
                SET approval_status = ?, approved_by = NULL, approved_at = NULL
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
