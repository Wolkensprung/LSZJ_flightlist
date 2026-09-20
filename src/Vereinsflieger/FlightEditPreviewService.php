<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;
use PDO;use RuntimeException;
final class FlightEditPreviewService {
 public function __construct(private PDO $pdo,private FlightExportChangeService $changes,private FlightSyncBaselineService $baselines,private FlightPayloadComparator $comparator){}
 public function preview(int $entryId,int $actorId):array{
  $check=$this->changes->checkOne($entryId,$actorId);if(($check['check_status']??'')!=='local_change_pending')throw new RuntimeException('D2 erfordert local_change_pending.');
  $base=$this->baselines->latest($entryId);$current=$check['current_payload']??null;if(!is_array($current))throw new RuntimeException('Aktueller Payload fehlt.');$cmp=$this->comparator->compare($base['payload'],$current);if($cmp['equal'])throw new RuntimeException('Keine Uebertragung erforderlich.');
  $q=$this->pdo->prepare("SELECT ae.id,ae.operation_id,ae.row_version,ae.departure_time,ae.callsign,e.id export_id,e.vf_flid,c.id check_id,l.id local_change_id,l.change_reason,l.changed_at FROM accounting_entries ae JOIN vf_flight_exports e ON e.id=(SELECT id FROM vf_flight_exports WHERE accounting_entry_id=ae.id AND status='success' ORDER BY id DESC LIMIT 1) JOIN vf_flight_export_change_checks c ON c.id=(SELECT id FROM vf_flight_export_change_checks WHERE accounting_entry_id=ae.id ORDER BY id DESC LIMIT 1) JOIN vf_flight_local_changes l ON l.id=(SELECT id FROM vf_flight_local_changes WHERE accounting_entry_id=ae.id ORDER BY id DESC LIMIT 1) WHERE ae.id=?");$q->execute([$entryId]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m)throw new RuntimeException('D2-Metadaten oder D1-Audit fehlen.');
  return ['accounting_entry_id'=>$entryId,'operation_id'=>(int)$m['operation_id'],'row_version'=>(int)$m['row_version'],'vf_flid'=>(int)$m['vf_flid'],'vf_flight_export_id'=>(int)$m['export_id'],'change_check_id'=>(int)$m['check_id'],'local_change_id'=>(int)$m['local_change_id'],'change_reason'=>$m['change_reason'],'changed_at'=>$m['changed_at'],'baseline'=>$base,'current_payload'=>$current,'current_payload_hash'=>$this->comparator->hash($current),'differences'=>$cmp['differences'],'difference_count'=>$cmp['difference_count'],'highest_risk'=>$cmp['highest_risk'],'sendable'=>true,'invoice_status'=>'not_checked'];
 }
}
