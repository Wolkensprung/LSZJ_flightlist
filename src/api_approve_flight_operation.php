<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/flight_validation.php';

$actor=api_authenticated_actor(['PILOT','DUTY_OFFICER','ADMIN']);
$pdo=db();
$input=json_decode(file_get_contents('php://input'),true)?:$_POST;
$operationId=(int)($input['operation_id']??0);
$action=(string)($input['action']??'');
$note=trim((string)($input['note']??''));
if(!$operationId||!in_array($action,['approve','request_correction','reset_pending'],true))json_response(['ok'=>false,'error'=>'operation_id oder action fehlt'],400);

$pdo->beginTransaction();
try{
    $lock=$pdo->prepare('SELECT id FROM operations WHERE id=? FOR UPDATE');
    $lock->execute([$operationId]);
    if(!$lock->fetchColumn())throw new RuntimeException('Operation nicht gefunden.');

    if($action==='approve'){
        $issues=flight_operation_validation($pdo,$operationId);
        if($issues){
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'Freigabe nicht möglich. Pflichtfelder fehlen oder sind ungültig.','missing_fields'=>$issues,'missing_summary'=>flight_validation_summary($issues)],422);
        }
        $stmt=$pdo->prepare("UPDATE accounting_entries SET approval_status='approved',approved_by=?,approved_by_user_id=?,approved_at=NOW(),correction_note=NULL WHERE operation_id=?");
        $stmt->execute([$actor['display_name'],$actor['id'],$operationId]);
        $pdo->prepare("UPDATE operations SET approval_status='approved',approved_by=?,approved_by_user_id=?,approved_at=NOW(),correction_note=NULL WHERE id=?")->execute([$actor['display_name'],$actor['id'],$operationId]);
        $status='approved';
    }elseif($action==='request_correction'){
        $stmt=$pdo->prepare("UPDATE accounting_entries SET approval_status='correction_required',approved_by=NULL,approved_by_user_id=NULL,approved_at=NULL,correction_note=?,exported_at=NULL,export_batch=NULL WHERE operation_id=?");
        $stmt->execute([$note,$operationId]);
        $pdo->prepare("UPDATE operations SET approval_status='correction_required',approved_by=NULL,approved_by_user_id=NULL,approved_at=NULL,correction_note=? WHERE id=?")->execute([$note,$operationId]);
        $status='correction_required';
    }else{
        $stmt=$pdo->prepare("UPDATE accounting_entries SET approval_status='pending',approved_by=NULL,approved_by_user_id=NULL,approved_at=NULL,exported_at=NULL,export_batch=NULL WHERE operation_id=?");
        $stmt->execute([$operationId]);
        $pdo->prepare("UPDATE operations SET approval_status='pending',approved_by=NULL,approved_by_user_id=NULL,approved_at=NULL WHERE id=?")->execute([$operationId]);
        $status='pending';
    }
    $pdo->commit();
    json_response(['ok'=>true,'operation_id'=>$operationId,'approval_status'=>$status,'changed'=>$stmt->rowCount()]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
