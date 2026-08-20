<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/lszj_correction_lib.php';
$pdo=db(); $opId=(int)($_GET['operation_id']??0); if(!$opId) json_response(['ok'=>false,'error'=>'operation_id fehlt.'],400);
$opStmt=$pdo->prepare('SELECT * FROM operations WHERE id=?'); $opStmt->execute([$opId]); $op=$opStmt->fetch(); if(!$op) json_response(['ok'=>false,'error'=>'Operation nicht gefunden.'],404);
$entries=get_entries($pdo,$opId);
$segStmt=$pdo->prepare('SELECT * FROM tow_segments WHERE operation_id=? LIMIT 1'); $segStmt->execute([$opId]); $towSegment=$segStmt->fetch()?:null;
$date=$op['operation_date']; $start=$op['takeoff_time'];
$candMot=$pdo->prepare("SELECT o.id AS operation_id,e.id AS entry_id,e.callsign,e.tow_pilot_name,e.departure_time,e.arrival_time,e.flight_minutes FROM operations o JOIN accounting_entries e ON e.operation_id=o.id WHERE o.operation_date=? AND e.entry_type='towplane_own' AND o.id<>? ORDER BY ABS(TIMESTAMPDIFF(MINUTE, e.departure_time, CONCAT(o.operation_date,' ',?))) LIMIT 10");
$candMot->execute([$date,$opId,$start ?: '00:00:00']);
$candGli=$pdo->prepare("SELECT o.id AS operation_id,e.id AS entry_id,e.callsign,e.pilot_name,e.departure_time,e.arrival_time,e.flight_minutes FROM operations o JOIN accounting_entries e ON e.operation_id=o.id WHERE o.operation_date=? AND e.entry_type='glider_flight' AND o.id<>? ORDER BY ABS(TIMESTAMPDIFF(MINUTE, e.departure_time, CONCAT(o.operation_date,' ',?))) LIMIT 10");
$candGli->execute([$date,$opId,$start ?: '00:00:00']);
json_response(['ok'=>true,'operation'=>$op,'glider_entry'=>$entries['glider'],'tow_charge'=>$entries['tow_charge'],'towplane_own'=>$entries['towplane_own'],'tow_segment'=>$towSegment,'candidate_motors'=>$candMot->fetchAll(),'candidate_gliders'=>$candGli->fetchAll()]);
