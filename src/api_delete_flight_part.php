<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
$actor = api_authenticated_actor(['DUTY_OFFICER', 'ADMIN']);

require __DIR__ . '/lszj_correction_lib.php';
$pdo=db(); $input=jinput();
$operationId=(int)qv($input,'operation_id',0); $part=qv($input,'part');
if(!$operationId || !in_array($part,['glider','motor'],true)) json_response(['ok'=>false,'error'=>'operation_id oder part fehlt.'],400);
try{
    $pdo->beginTransaction();
    $opStmt=$pdo->prepare('SELECT * FROM operations WHERE id=? FOR UPDATE'); $opStmt->execute([$operationId]); $op=$opStmt->fetch();
    if(!$op) throw new RuntimeException('Operation nicht gefunden.');
    $e=get_entries($pdo,$operationId);
    if($part==='glider'){
        if(!$e['glider']) throw new RuntimeException('Kein Segelflug in dieser Operation vorhanden.');
        $pdo->prepare("DELETE FROM accounting_entries WHERE operation_id=? AND entry_type='glider_flight'")->execute([$operationId]);
        $pdo->prepare("DELETE FROM tow_segments WHERE operation_id=?")->execute([$operationId]);
        if($e['tow_charge']){
            $tc=$e['tow_charge']; $plane=$tc['tow_callsign'];
            if(!$plane) throw new RuntimeException('Motorflug kann nicht erhalten werden: tow_callsign fehlt.');
            update_row($pdo,'accounting_entries',[
                'entry_type'=>'towplane_own','callsign'=>$plane,'arrival_time'=>add_minutes_sql($tc['departure_time'],$tc['tow_minutes']),
                'flight_minutes'=>$tc['tow_minutes'],'tow_callsign'=>null,'tow_minutes'=>null,'tow_height_m'=>null,
                'pilot_name'=>null,'attendant_name'=>null,'approval_status'=>'pending','exported_at'=>null,'export_batch'=>null
            ],'id=:id',['id'=>$tc['id']]);
            update_row($pdo,'operations',[
                'kind'=>'towplane_only','glider_callsign'=>null,'glider_pilot_name'=>null,'instructor_name'=>null,
                'glider_landing_time'=>null,'glider_landing_airfield'=>null,'tow_callsign'=>$plane,
                'approval_status'=>'pending','correction_note'=>'Segelflug gelöscht, Motorflug erhalten'
            ],'id=:id',['id'=>$operationId]);
        } elseif($e['towplane_own']) {
            update_row($pdo,'operations',[
                'kind'=>'towplane_only','glider_callsign'=>null,'glider_pilot_name'=>null,'instructor_name'=>null,
                'glider_landing_time'=>null,'glider_landing_airfield'=>null,'approval_status'=>'pending',
                'correction_note'=>'Segelflug gelöscht, Motorflug erhalten'
            ],'id=:id',['id'=>$operationId]);
        } else {
            $pdo->prepare('DELETE FROM operations WHERE id=?')->execute([$operationId]);
        }
    } else {
        if(!$e['tow_charge'] && !$e['towplane_own']) throw new RuntimeException('Kein Motorflug in dieser Operation vorhanden.');
        $pdo->prepare("DELETE FROM accounting_entries WHERE operation_id=? AND entry_type IN ('tow_charge','towplane_own')")->execute([$operationId]);
        $pdo->prepare("DELETE FROM tow_segments WHERE operation_id=?")->execute([$operationId]);
        if($e['glider']){
            update_row($pdo,'accounting_entries',[
                'start_type'=>1,'tow_callsign'=>null,'tow_pilot_name'=>null,'tow_minutes'=>null,'tow_height_m'=>null,
                'approval_status'=>'pending','exported_at'=>null,'export_batch'=>null
            ],"operation_id=:operation_id AND entry_type='glider_flight'",['operation_id'=>$operationId]);
            update_row($pdo,'operations',[
                'kind'=>'self_launch','tow_callsign'=>null,'tow_height_m'=>null,'approval_status'=>'pending',
                'correction_note'=>'Motorflug gelöscht, Segelflug erhalten'
            ],'id=:id',['id'=>$operationId]);
        } else {
            $pdo->prepare('DELETE FROM operations WHERE id=?')->execute([$operationId]);
        }
    }
    if($op && $part && $pdo->inTransaction()){
        // If operation still exists, reset remaining entries.
        $chk=$pdo->prepare('SELECT COUNT(*) FROM operations WHERE id=?'); $chk->execute([$operationId]);
        if((int)$chk->fetchColumn()>0) set_pending_operation($pdo,$operationId);
    }
    $pdo->commit(); json_response(['ok'=>true,'operation_id'=>$operationId,'part'=>$part]);
}catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); json_response(['ok'=>false,'error'=>$e->getMessage()],500); }
