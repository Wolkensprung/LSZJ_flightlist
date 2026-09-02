<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/flight_validation.php';
$pdo=db();$from=$_GET['from']??($_GET['date']??date('Y-m-d'));$to=$_GET['to']??$from;
function scalar_count(PDO $pdo,string $sql,array $params):int{$s=$pdo->prepare($sql);$s->execute($params);return(int)$s->fetchColumn();}
$params=[$from,$to];$range="DATE(departure_time) BETWEEN ? AND ?";
$approvedNotExported=scalar_count($pdo,"SELECT COUNT(*) FROM accounting_entries WHERE $range AND approval_status='approved' AND vf_exported_at IS NULL",$params);
$pending=scalar_count($pdo,"SELECT COUNT(*) FROM accounting_entries WHERE $range AND approval_status='pending'",$params);
$correctionRequired=scalar_count($pdo,"SELECT COUNT(*) FROM accounting_entries WHERE $range AND approval_status='correction_required'",$params);
$alreadyExported=scalar_count($pdo,"SELECT COUNT(*) FROM accounting_entries WHERE $range AND vf_exported_at IS NOT NULL",$params);
$detail=$pdo->prepare("SELECT e.*,ft.code AS flight_type_code,ft.name AS flight_type_name,cm.name AS charge_mode_name FROM accounting_entries e LEFT JOIN flight_types ft ON ft.id=e.vf_flight_type_id LEFT JOIN charge_modes cm ON cm.id=e.charge_mode WHERE DATE(e.departure_time) BETWEEN ? AND ? AND e.approval_status='approved' AND e.vf_exported_at IS NULL ORDER BY e.departure_time,e.id");
$detail->execute($params);$exportRows=$detail->fetchAll(PDO::FETCH_ASSOC);
$issues=[];foreach($exportRows as $entry){$fields=flight_entry_missing_fields($entry);if($fields)$issues[]=['entry_id'=>(int)$entry['id'],'operation_id'=>(int)$entry['operation_id'],'entry_type'=>$entry['entry_type'],'callsign'=>$entry['callsign'],'fields'=>$fields];}
$summary=flight_validation_summary($issues);
$batch=$pdo->prepare("SELECT export_batch,COUNT(*) cnt,MIN(vf_exported_at) first_exported_at,MAX(vf_exported_at) last_exported_at FROM accounting_entries WHERE DATE(departure_time) BETWEEN ? AND ? AND vf_exported_at IS NOT NULL GROUP BY export_batch ORDER BY last_exported_at DESC");$batch->execute($params);
$canExport=$approvedNotExported>0&&!$issues&&$pending===0&&$correctionRequired===0;
json_response(['ok'=>true,'from'=>$from,'to'=>$to,'can_export'=>$canExport,'approved_not_exported'=>$approvedNotExported,'pending'=>$pending,'correction_required'=>$correctionRequired,'already_exported'=>$alreadyExported,'missing_flight_type'=>$summary['Flugart']??0,'missing_charge_mode'=>$summary['Abrechnung']??0,'missing_pilot'=>($summary['Segelflugpilot']??0)+($summary['Motorpilot']??0)+($summary['Schlepppilot']??0),'validation_issues'=>$issues,'validation_summary'=>$summary,'export_rows'=>$exportRows,'export_batches'=>$batch->fetchAll()]);
