<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');
$status = $_GET['status'] ?? 'pending';
$stmt = $pdo->prepare("SELECT e.*, o.kind, o.glider_callsign, o.tow_callsign AS operation_tow_callsign
    FROM accounting_entries e
    JOIN operations o ON o.id = e.operation_id
    WHERE DATE(e.departure_time) = ? AND e.approval_status = ?
    ORDER BY e.departure_time, e.id");
$stmt->execute([$date, $status]);
json_response(['date' => $date, 'entries' => $stmt->fetchAll()]);
