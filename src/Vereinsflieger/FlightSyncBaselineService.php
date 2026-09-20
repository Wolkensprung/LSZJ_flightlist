<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;
use PDO;use RuntimeException;use JsonException;
final class FlightSyncBaselineService {
 public function __construct(private PDO $pdo,private FlightPayloadComparator $comparator){}
 public function latest(int $entryId):array {
  $s=$this->pdo->prepare("SELECT * FROM vf_flight_sync_snapshots WHERE accounting_entry_id=? ORDER BY id DESC LIMIT 1");$s->execute([$entryId]);$r=$s->fetch(PDO::FETCH_ASSOC);
  if($r){$p=$this->decode($r['payload_json']);return ['id'=>(int)$r['id'],'source'=>$r['source'],'source_record_id'=>$r['source_record_id'],'vf_flid'=>(int)$r['vf_flid'],'payload_hash'=>$this->comparator->hash($p),'payload'=>$p];}
  $s=$this->pdo->prepare("SELECT * FROM vf_flight_exports WHERE accounting_entry_id=? AND status='success' AND vf_flid IS NOT NULL ORDER BY id DESC LIMIT 1");$s->execute([$entryId]);$e=$s->fetch(PDO::FETCH_ASSOC);if(!$e)throw new RuntimeException('Keine erfolgreiche Synchronisationsbaseline gefunden.');
  $p=$this->decode($e['payload_json']);return ['id'=>null,'source'=>'add','source_record_id'=>(int)$e['id'],'vf_flid'=>(int)$e['vf_flid'],'payload_hash'=>$this->comparator->hash($p),'payload'=>$p];
 }
 public function recordEdit(int $entryId,?int $operationId,int $flid,int $runId,array $payload,int $actorId):int {
  $json=$this->encode($payload);$s=$this->pdo->prepare("INSERT INTO vf_flight_sync_snapshots(accounting_entry_id,operation_id,vf_flid,source,source_record_id,payload_hash,payload_json,created_by) VALUES(?,?,?,'edit',?,?,?,?)");$s->execute([$entryId,$operationId,$flid,$runId,$this->comparator->hash($payload),$json,$actorId]);return (int)$this->pdo->lastInsertId();
 }
 private function decode(string $j):array{try{$v=json_decode($j,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Ungueltige Baseline: '.$e->getMessage(),0,$e);}if(!is_array($v)||array_is_list($v))throw new RuntimeException('Baseline ist kein JSON-Objekt.');return $v;}
 private function encode(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
