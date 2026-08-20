<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id = (int)($input['id'] ?? 0);
$user = trim($input['user'] ?? 'unknown');
if (!$id) json_response(['error' => 'id fehlt'], 400);
$stmt = $pdo->prepare("UPDATE accounting_entries SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND approval_status='pending'");
$stmt->execute([$user, $id]);
json_response(['ok' => true, 'changed' => $stmt->rowCount()]);
