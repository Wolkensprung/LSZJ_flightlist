<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id = (int)($input['id'] ?? 0);
$user = trim($input['user'] ?? 'unknown');
$allowed = ['pilot_name','attendant_name','flight_minutes','tow_minutes','comment','tow_pilot_name','cost_center','vf_flight_type_id','plane_wkz','plane_designation'];
if (!$id) json_response(['error' => 'id fehlt'], 400);
$current = $pdo->prepare("SELECT * FROM accounting_entries WHERE id = ?");
$current->execute([$id]);
$row = $current->fetch();
if (!$row) json_response(['error' => 'Eintrag nicht gefunden'], 404);
$pdo->beginTransaction();
try {
    $sets = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $old = (string)($row[$field] ?? '');
            $new = (string)($input[$field] ?? '');
            if ($old !== $new) {
                $sets[] = "$field = ?";
                $params[] = $input[$field] === '' ? null : $input[$field];
                $pdo->prepare("INSERT INTO entry_changes (entry_id, changed_by, changed_at, field_name, old_value, new_value) VALUES (?, ?, NOW(), ?, ?, ?)")
                    ->execute([$id, $user, $field, $old, $new]);
            }
        }
    }
    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE accounting_entries SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }
    $pdo->commit();
    json_response(['ok' => true, 'updated_fields' => count($sets)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => $e->getMessage()], 500);
}
