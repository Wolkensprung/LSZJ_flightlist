<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);
$pdo = db();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id = (int)($input['id'] ?? 0);
$user = $actor['display_name'];
$userId = $actor['id'];
if (!$id) json_response(['error' => 'id fehlt'], 400);
$stmt = $pdo->prepare("UPDATE accounting_entries SET approval_status='approved', approved_by=?, approved_by_user_id=?, approved_at=NOW() WHERE id=? AND approval_status='pending'");
$stmt->execute([$user, $userId, $id]);
json_response(['ok' => true, 'changed' => $stmt->rowCount()]);
