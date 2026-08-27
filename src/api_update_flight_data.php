<?php
/**
 * api_update_flight_data.php
 *
 * Manuelle Korrektur von Freigabe- und Exportfeldern.
 *
 * Wichtige Re-Export-Logik:
 * - Wenn ein accounting_entry nach einem Export geändert wird,
 *   verliert der alte Export seine Gueltigkeit.
 * - Deshalb werden bei jeder Änderung an accounting_entries automatisch
 *   exported_at und export_batch auf NULL gesetzt.
 * - Danach wird approval_status wieder auf pending gesetzt.
 * - Nach erneutem "Speichern & Freigeben" steht der Eintrag wieder fuer
 *   den Vereinsflieger-CSV-Export bereit.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';

$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
require_once __DIR__ . '/flight_day.php';
flight_day_assert_request_editable($input, basename(__FILE__));

$entity = $input['entity'] ?? '';
$id = (int)($input['id'] ?? 0);
$user = $actor['display_name'];

if (!$id || !in_array($entity, ['accounting_entry','tow_segment','operation'], true)) {
    json_response(['error' => 'entity oder id fehlt'], 400);
}

$config = [
    'accounting_entry' => [
        'table' => 'accounting_entries',
        'allowed' => [
            'pilot_name',
            'attendant_name',
            'tow_pilot_name',
            'flight_minutes',
            'motor_minutes',
            'tow_minutes',
            'charge_mode',
            'comment',
            'correction_note',
            'vf_flight_type_id',
            'plane_wkz',
            'plane_designation',
        ],
    ],
    'tow_segment' => [
        'table' => 'tow_segments',
        'allowed' => [
            'tow_pilot_name',
            'tow_minutes',
            'tow_height_m',
            'cost_center',
            'correction_note',
        ],
    ],
    'operation' => [
        'table' => 'operations',
        'allowed' => [
            'glider_pilot_name',
            'instructor_name',
            'correction_note',
        ],
    ],
];

$table = $config[$entity]['table'];
$allowed = $config[$entity]['allowed'];

$currentStmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
$currentStmt->execute([$id]);
$current = $currentStmt->fetch();

if (!$current) {
    json_response(['error' => 'Datensatz nicht gefunden'], 404);
}

$pdo->beginTransaction();

try {
    $sets = [];
    $params = [];
    $changedFields = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $old = (string)($current[$field] ?? '');
        $new = (string)($input[$field] ?? '');

        if ($old === $new) {
            continue;
        }

        $sets[] = "{$field} = ?";
        $params[] = $input[$field] === '' ? null : $input[$field];
        $changedFields[] = $field;

        if ($entity === 'accounting_entry') {
            $pdo->prepare(
                "INSERT INTO entry_changes
                    (entry_id, changed_by, changed_at, field_name, old_value, new_value)
                 VALUES
                    (?, ?, NOW(), ?, ?, ?)"
            )->execute([$id, $user, $field, $old, $new]);
        }
    }

    if ($sets) {
        // Jede Korrektur setzt den fachlichen Zustand wieder auf pending.
        $sets[] = "approval_status = 'pending'";

        // Eine fachliche Änderung hebt eine vorhandene Freigabe auf.
        if ($entity === 'accounting_entry' || $entity === 'operation') {
            $sets[] = "approved_by = NULL";
            $sets[] = "approved_by_user_id = NULL";
            $sets[] = "approved_at = NULL";
        }

        // Nur accounting_entries werden exportiert. Deshalb nur dort Exportstatus zuruecksetzen.
        if ($entity === 'accounting_entry') {
            $sets[] = "exported_at = NULL";
            $sets[] = "vf_exported_at = NULL";
            $sets[] = "export_batch = NULL";
        }

        $params[] = $id;

        $pdo->prepare(
            "UPDATE {$table}
             SET " . implode(', ', $sets) . "
             WHERE id = ?"
        )->execute($params);
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'entity' => $entity,
        'id' => $id,
        'updated_fields' => count($changedFields),
        'changed_fields' => $changedFields,
        'approval_status' => count($changedFields) ? 'pending' : ($current['approval_status'] ?? null),
        'export_reset' => $entity === 'accounting_entry' && count($changedFields) > 0,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => $e->getMessage()], 500);
}
