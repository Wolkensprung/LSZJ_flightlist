<?php
/**
 * api_manual_candidates.php
 * Liefert Kandidaten fuer manuelle Zuordnungen.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
$pdo=db();
$date=$_GET['date'] ?? date('Y-m-d');

$gliderStmt=$pdo->prepare("SELECT e.id AS entry_id,e.operation_id,e.callsign,e.pilot_name,e.departure_time,e.arrival_time,e.flight_minutes,e.tow_callsign,e.approval_status,o.kind FROM accounting_entries e JOIN operations o ON o.id=e.operation_id WHERE e.entry_type='glider_flight' AND DATE(e.departure_time)=? ORDER BY e.departure_time,e.id");
$gliderStmt->execute([$date]);

$towStmt=$pdo->prepare("SELECT e.id AS entry_id,e.operation_id,e.callsign,e.tow_pilot_name,e.departure_time,e.arrival_time,e.flight_minutes,e.vf_flight_type_id,e.approval_status,o.kind FROM accounting_entries e JOIN operations o ON o.id=e.operation_id WHERE e.entry_type='towplane_own' AND DATE(e.departure_time)=? ORDER BY e.departure_time,e.id");
$towStmt->execute([$date]);

json_response(['ok'=>true,'date'=>$date,'glider_entries'=>$gliderStmt->fetchAll(),'towplane_entries'=>$towStmt->fetchAll()]);
