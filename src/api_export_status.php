<?php
/** api_export_status.php with date range support */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo = db();
$from = $_GET['from'] ?? ($_GET['date'] ?? date('Y-m-d'));
$to = $_GET['to'] ?? $from;
function scalar_count(PDO $pdo, string $sql, array $params): int { $stmt=$pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn(); }
$params = [$from, $to];
$approvedNotExported = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND approval_status='approved' AND exported_at IS NULL", $params);
$pending = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND approval_status='pending'", $params);
$correctionRequired = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND approval_status='correction_required'", $params);
$alreadyExported = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND exported_at IS NOT NULL", $params);
$missingFlightType = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND approval_status='approved' AND exported_at IS NULL AND vf_flight_type_id IS NULL", $params);
$missingChargeMode = scalar_count($pdo, "SELECT COUNT(*) FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND approval_status='approved' AND exported_at IS NULL AND charge_mode IS NULL", $params);
$batchStmt = $pdo->prepare("SELECT export_batch, COUNT(*) AS cnt, MIN(exported_at) AS first_exported_at, MAX(exported_at) AS last_exported_at FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND exported_at IS NOT NULL GROUP BY export_batch ORDER BY last_exported_at DESC");
$batchStmt->execute($params);
$exportBatches = $batchStmt->fetchAll();
$detailStmt = $pdo->prepare("SELECT e.id,e.entry_type,e.callsign,e.tow_callsign,e.departure_time,e.approval_status,e.exported_at,e.vf_flight_type_id,ft.code AS flight_type_code,ft.name AS flight_type_name,e.charge_mode,cm.name AS charge_mode_name FROM accounting_entries e LEFT JOIN flight_types ft ON ft.id=e.vf_flight_type_id LEFT JOIN charge_modes cm ON cm.id=e.charge_mode WHERE DATE(e.departure_time) BETWEEN ? AND ? AND e.approval_status='approved' AND e.exported_at IS NULL ORDER BY e.departure_time,e.id");
$detailStmt->execute($params);
$exportRows = $detailStmt->fetchAll();
$canExport = $approvedNotExported > 0 && $missingFlightType === 0 && $missingChargeMode === 0 && $pending === 0 && $correctionRequired === 0;
json_response(['ok'=>true,'from'=>$from,'to'=>$to,'can_export'=>$canExport,'approved_not_exported'=>$approvedNotExported,'pending'=>$pending,'correction_required'=>$correctionRequired,'already_exported'=>$alreadyExported,'missing_flight_type'=>$missingFlightType,'missing_charge_mode'=>$missingChargeMode,'export_rows'=>$exportRows,'export_batches'=>$exportBatches]);
?>
