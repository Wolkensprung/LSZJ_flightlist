<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/flight_validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/duty_officer.php';

function flight_day_is_valid_date(string $date): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $d!==false && $d->format('Y-m-d')===$date;
}
function flight_day_state(PDO $pdo,string $date): array {
    $s=$pdo->prepare("SELECT f.*,cu.display_name closed_by_name,ru.display_name reopened_by_name FROM flight_day_states f LEFT JOIN users cu ON cu.id=f.closed_by LEFT JOIN users ru ON ru.id=f.reopened_by WHERE f.operation_date=?");
    $s->execute([$date]); $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?:['operation_date'=>$date,'status'=>'open','closed_at'=>null,'closed_by'=>null,'close_note'=>null,'reopened_at'=>null,'reopened_by'=>null,'reopen_reason'=>null];
}
function flight_day_status(PDO $pdo,string $date): array {
    if(!flight_day_is_valid_date($date)) throw new RuntimeException('Ungültiges Datum.');
    $s=$pdo->prepare("SELECT o.id operation_id,o.kind,o.takeoff_time,e.* FROM operations o LEFT JOIN accounting_entries e ON e.operation_id=o.id WHERE o.operation_date=? ORDER BY o.takeoff_time,o.id,e.id");
    $s->execute([$date]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $byOp=[]; foreach($rows as $r)$byOp[(int)$r['operation_id']][]=$r;
    $red=[];$yellow=[];$counts=['approved'=>0,'exported'=>0,'pending'=>0,'correction_required'=>0];
    foreach($byOp as $opId=>$entries){
        $kind=(string)($entries[0]['kind']??''); $hasGlider=false;$hasMotor=false;
        foreach($entries as $e){
            if(empty($e['id'])) continue;
            $type=(string)$e['entry_type'];
            if($type==='glider_flight')$hasGlider=true;
            if(in_array($type,['towplane_own','tow_charge'],true))$hasMotor=true;
            $status=(string)($e['approval_status']??'pending'); if(isset($counts[$status]))$counts[$status]++;
            $needsLanding=in_array($type,['glider_flight','towplane_own'],true);
            $isRedIncident = $needsLanding
                && !empty($e['departure_time'])
                && empty($e['arrival_time']);

            if($isRedIncident){
                $red[]=['code'=>'missing_landing','operation_id'=>$opId,'entry_id'=>(int)$e['id'],'entry_type'=>$type,'callsign'=>$e['callsign'],'pilot'=>$type==='glider_flight'?$e['pilot_name']:$e['tow_pilot_name'],'departure_time'=>$e['departure_time'],'message'=>'Gestarteter Flug ohne erfasste Landung','ktrax_url'=>'https://ktrax.kisstech.ch/ktrax'];
            }
            elseif(in_array($status,['pending','correction_required'],true)){
                $fields=flight_entry_missing_fields($e);
                $yellow[]=['code'=>$status,'operation_id'=>$opId,'entry_id'=>(int)$e['id'],'entry_type'=>$type,'callsign'=>$e['callsign'],'status'=>$status,'fields'=>$fields,'message'=>$status==='pending'?'Flug ist offen':'Korrektur erforderlich'];
            }
        }
        $structural=false;$detail='';
        if($kind==='glider_tow' && (!$hasGlider || !$hasMotor)){$structural=true;$detail='Gekoppelte Operation ist unvollständig';}
        if($kind==='towplane_only' && $hasGlider){$structural=true;$detail='Motorflug-Operation enthält unerwarteten Segelflug';}
        if($structural)$red[]=['code'=>'structural','operation_id'=>$opId,'entry_id'=>null,'callsign'=>null,'message'=>$detail,'ktrax_url'=>null];
    }
    $state=flight_day_state($pdo,$date);
    return ['ok'=>true,'date'=>$date,'state'=>$state,'red'=>$red,'yellow'=>$yellow,'red_count'=>count($red),'yellow_count'=>count($yellow),'green'=>!$red&&!$yellow,'counts'=>$counts];
}
function flight_day_user_can_manage(): bool {
    return has_role('ADMIN') || duty_officer_is_current_user();
}
function flight_day_close(PDO $pdo,string $date,string $note=''): array {
    $user=auth_require_login(); if(!flight_day_user_can_manage())throw new RuntimeException('Nur der aktive Flugdienstleiter oder ein Administrator kann den Betriebstag abschliessen.');
    $status=flight_day_status($pdo,$date); if(!$status['green'])throw new RuntimeException('Abschluss nicht möglich: Die Ampel ist nicht grün.');
    $pdo->beginTransaction(); try{
        $lock=$pdo->prepare('SELECT operation_date,status FROM flight_day_states WHERE operation_date=? FOR UPDATE');$lock->execute([$date]);$current=$lock->fetch();
        if($current && $current['status']==='closed')throw new RuntimeException('Der Betriebstag ist bereits abgeschlossen.');
        $u=$pdo->prepare("INSERT INTO flight_day_states(operation_date,status,closed_at,closed_by,close_note,reopened_at,reopened_by,reopen_reason) VALUES(?,'closed',NOW(),?,?,NULL,NULL,NULL) ON DUPLICATE KEY UPDATE status='closed',closed_at=NOW(),closed_by=VALUES(closed_by),close_note=VALUES(close_note),reopened_at=NULL,reopened_by=NULL,reopen_reason=NULL");$u->execute([$date,(int)$user['id'],$note?:null]);
        $a=$pdo->prepare("INSERT INTO flight_day_audit(operation_date,action,performed_by,reason,red_count,yellow_count) VALUES(?,'close',?,?,0,0)");$a->execute([$date,(int)$user['id'],$note?:null]);$pdo->commit();return flight_day_status($pdo,$date);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function flight_day_reopen(PDO $pdo,string $date,string $reason): array {
    $user=auth_require_login(); if(!flight_day_user_can_manage())throw new RuntimeException('Nur der aktive Flugdienstleiter oder ein Administrator kann den Betriebstag wieder öffnen.');
    $reason=trim($reason);if($reason==='')throw new RuntimeException('Eine Begründung ist erforderlich.');
    $pdo->beginTransaction();try{$lock=$pdo->prepare('SELECT status FROM flight_day_states WHERE operation_date=? FOR UPDATE');$lock->execute([$date]);$state=$lock->fetchColumn();if($state!=='closed')throw new RuntimeException('Der Betriebstag ist nicht abgeschlossen.');$u=$pdo->prepare("UPDATE flight_day_states SET status='open',reopened_at=NOW(),reopened_by=?,reopen_reason=? WHERE operation_date=?");$u->execute([(int)$user['id'],$reason,$date]);$a=$pdo->prepare("INSERT INTO flight_day_audit(operation_date,action,performed_by,reason) VALUES(?,'reopen',?,?)");$a->execute([$date,(int)$user['id'],$reason]);$pdo->commit();return flight_day_status($pdo,$date);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function flight_day_assert_editable(string $date): void {
    if(!flight_day_is_valid_date($date))throw new RuntimeException('Ungültiges Betriebsdatum.');$s=db()->prepare("SELECT 1 FROM flight_day_states WHERE operation_date=? AND status='closed' LIMIT 1");$s->execute([$date]);if($s->fetchColumn())throw new RuntimeException('Der Betriebstag '.$date.' ist abgeschlossen und kann nicht bearbeitet werden.');
}
function flight_day_request_date(array $input,string $endpoint): ?string {
    if(!empty($input['date']))return substr((string)$input['date'],0,10);$pdo=db();
    if(!empty($input['operation_id'])){$s=$pdo->prepare('SELECT operation_date FROM operations WHERE id=?');$s->execute([(int)$input['operation_id']]);return $s->fetchColumn()?:null;}
    if(!empty($input['id'])){if($endpoint==='api_update_flight_data.php' && ($input['entity']??'')==='operation'){$s=$pdo->prepare('SELECT operation_date FROM operations WHERE id=?');$s->execute([(int)$input['id']]);return $s->fetchColumn()?:null;}$s=$pdo->prepare('SELECT DATE(departure_time) FROM accounting_entries WHERE id=?');$s->execute([(int)$input['id']]);return $s->fetchColumn()?:null;}return null;
}
function flight_day_assert_request_editable(array $input,string $endpoint): void {$date=flight_day_request_date($input,$endpoint);if($date)flight_day_assert_editable($date);}
