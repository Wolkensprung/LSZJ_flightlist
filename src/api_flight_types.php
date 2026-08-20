<?php
/**
 * api_flight_types.php
 *
 * Liefert Flugarten fuer Dropdowns.
 * Neu: Segelflug-Flugarten werden in der fachlich gewuenschten Reihenfolge sortiert.
 *
 * Reihenfolge Segelflug:
 * 1. Privatflug
 * 2. Schulflug
 * 3. Checkflug
 * 4. GVVC
 * 5. Passagierflug
 * 6. Passagierflug mit Schüler
 * 7. External
 * 8. BFK
 * 9. Werkverkehr
 * 10. Überflug
 *
 * Wichtig: IDs und Werte werden nicht veraendert, nur die Anzeige-Reihenfolge.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$pdo = db();
$category = $_GET['category'] ?? null;

function table_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
}

$cols = table_columns($pdo, 'flight_types');
$hasCategory = in_array('category', $cols, true);
$hasActive = in_array('active', $cols, true);
$hasIsActive = in_array('is_active', $cols, true);
$hasEnabled = in_array('enabled', $cols, true);

$select = ['id', 'code', 'name'];
if ($hasCategory) {
    $select[] = 'category';
}

$where = [];
$params = [];

if ($category && $hasCategory) {
    $where[] = 'category = ?';
    $params[] = $category;
}

if ($hasActive) {
    $where[] = 'active = 1';
} elseif ($hasIsActive) {
    $where[] = 'is_active = 1';
} elseif ($hasEnabled) {
    $where[] = 'enabled = 1';
}

$order = "name ASC";
if ($category === 'glider') {
    $order = "CASE
        WHEN LOWER(name) = 'privatflug' THEN 1
        WHEN LOWER(name) = 'schulflug' THEN 2
        WHEN LOWER(name) = 'checkflug' THEN 3
        WHEN LOWER(name) = 'gvvc' THEN 4
        WHEN LOWER(name) = 'passagierflug' THEN 5
        WHEN LOWER(name) = 'passagierflug mit schüler' THEN 6
        WHEN LOWER(name) = 'passagierflug mit schueler' THEN 6
        WHEN LOWER(name) = 'external' THEN 7
        WHEN LOWER(name) = 'bfk' THEN 8
        WHEN LOWER(name) = 'werkverkehr' THEN 9
        WHEN LOWER(name) = 'überflug' THEN 10
        WHEN LOWER(name) = 'ueberflug' THEN 10
        ELSE 99
    END, name ASC";
}

$sql = 'SELECT ' . implode(', ', $select) . ' FROM flight_types';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $order;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

json_response([
    'ok' => true,
    'category' => $category,
    'flight_types' => $rows,
]);
