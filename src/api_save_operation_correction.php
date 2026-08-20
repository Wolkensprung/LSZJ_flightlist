<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/lszj_correction_lib.php';

$pdo = db();
$i = jinput();
$opId = (int)qv($i, 'operation_id', 0);
if (!$opId) json_response(['ok'=>false,'error'=>'operation_id fehlt.'], 400);

$date = qv($i, 'date');
$takeoff = qv($i, 'takeoff_time');

$gliderFilled = qv($i,'glider_callsign') || qv($i,'pilot_name') || qv($i,'glider_landing_time') || qv($i,'vf_flight_type_id') || qv($i,'charge_mode');
$motorFilled = qv($i,'tow_callsign') || qv($i,'tow_pilot_name') || qv($i,'tow_landing_time');

$missing = [];
if (!$date) $missing[] = 'Datum';
if (!$takeoff && ($gliderFilled || $motorFilled)) $missing[] = 'Startzeit';

if ($gliderFilled) {
    foreach([
        'glider_callsign'=>'Segelflugzeug',
        'pilot_name'=>'Segelflugpilot',
        'glider_landing_time'=>'Landung Segelflugzeug',
        'vf_flight_type_id'=>'Flugart Segelflug',
        'charge_mode'=>'Abrechnungsart'
    ] as $k=>$label) if (!qv($i,$k)) $missing[] = $label;
}
if ($motorFilled) {
    foreach([
        'tow_callsign'=>'Motorflugzeug',
        'tow_pilot_name'=>'Motorpilot',
        'tow_landing_time'=>'Landung Motorflugzeug'
    ] as $k=>$label) if (!qv($i,$k)) $missing[] = $label;
}
if (!$gliderFilled && !$motorFilled) $missing[] = 'Segelflug- oder Motorflugdaten';
if ($missing) json_response(['ok'=>false,'error'=>'Bitte ausfüllen: '.implode(', ', array_unique($missing)),'missing'=>array_values(array_unique($missing))],400);

if ($gliderFilled && qv($i,'glider_landing_time') < $takeoff) json_response(['ok'=>false,'error'=>'Landung Segelflugzeug darf nicht vor der Startzeit liegen.'],400);
if ($motorFilled && qv($i,'tow_landing_time') < $takeoff) json_response(['ok'=>false,'error'=>'Landung Motorflugzeug darf nicht vor der Startzeit liegen.'],400);

try {
    $pdo->beginTransaction();
    $opStmt=$pdo->prepare('SELECT * FROM operations WHERE id=? FOR UPDATE');
    $opStmt->execute([$opId]);
    $op=$opStmt->fetch();
    if (!$op) throw new RuntimeException('Operation nicht gefunden.');
    $e = get_entries($pdo, $opId);
    $mergeId = (int)qv($i,'merge_operation_id',0);

    if ($gliderFilled && $motorFilled) {
        $glider=qv($i,'glider_callsign'); $plane=qv($i,'tow_callsign'); $towLd=qv($i,'tow_landing_time'); $gliLd=qv($i,'glider_landing_time');
        update_row($pdo,'operations',[
            'operation_date'=>$date,'kind'=>'glider_tow','glider_callsign'=>$glider,'glider_pilot_name'=>qv($i,'pilot_name'),
            'instructor_name'=>qv($i,'attendant_name'),'tow_callsign'=>$plane,'takeoff_time'=>$takeoff,'takeoff_airfield'=>qv($i,'departure_location','LSZJ'),
            'glider_landing_time'=>$gliLd,'glider_landing_airfield'=>qv($i,'arrival_location','LSZJ'),'tow_height_m'=>null,
            'approval_status'=>'pending','correction_note'=>'Korrigiert aus Freigabeliste'
        ],'id=:id',['id'=>$opId]);
        $gliderComment=qv($i,'comment','Korrigiert: Segelflugdaten aktualisiert');
        $towComment=qv($i,'tow_comment','Korrigiert: Motorflug/Schleppdaten aktualisiert');
        $gliderData=['operation_id'=>$opId,'entry_type'=>'glider_flight','callsign'=>$glider,'pilot_name'=>qv($i,'pilot_name'),'attendant_name'=>qv($i,'attendant_name'),'departure_time'=>dtv($date,$takeoff),'departure_location'=>qv($i,'departure_location','LSZJ'),'arrival_time'=>dtv($date,$gliLd),'arrival_location'=>qv($i,'arrival_location','LSZJ'),'flight_minutes'=>mins_v($date,$takeoff,$gliLd),'landing_count'=>1,'start_type'=>3,'charge_mode'=>(int)qv($i,'charge_mode',2),'invoiced'=>0,'comment'=>$gliderComment,'tow_height_m'=>null,'tow_minutes'=>null,'tow_callsign'=>$plane,'tow_pilot_name'=>null,'tow_arrival_location'=>null,'vf_flight_type_id'=>int_null(qv($i,'vf_flight_type_id')),'approval_role'=>'glider_pilot','approval_status'=>'pending','correction_note'=>null,'exported_at'=>null,'export_batch'=>null];
        if($e['glider']) update_row($pdo,'accounting_entries',$gliderData,'id=:id',['id'=>$e['glider']['id']]); else insert_row($pdo,'accounting_entries',$gliderData);
        $towData=['operation_id'=>$opId,'entry_type'=>'tow_charge','callsign'=>$glider,'pilot_name'=>null,'attendant_name'=>null,'departure_time'=>dtv($date,$takeoff),'departure_location'=>qv($i,'departure_location','LSZJ'),'arrival_time'=>null,'arrival_location'=>null,'flight_minutes'=>null,'landing_count'=>1,'start_type'=>3,'charge_mode'=>(int)qv($i,'charge_mode',2),'invoiced'=>0,'comment'=>$towComment,'tow_height_m'=>null,'tow_minutes'=>mins_v($date,$takeoff,$towLd),'tow_callsign'=>$plane,'tow_pilot_name'=>qv($i,'tow_pilot_name'),'tow_arrival_location'=>qv($i,'tow_arrival_location'),'vf_flight_type_id'=>3,'approval_role'=>'tow_pilot','approval_status'=>'pending','correction_note'=>null,'exported_at'=>null,'export_batch'=>null];
        if($e['tow_charge']) update_row($pdo,'accounting_entries',$towData,'id=:id',['id'=>$e['tow_charge']['id']]); elseif($e['towplane_own']) update_row($pdo,'accounting_entries',$towData,'id=:id',['id'=>$e['towplane_own']['id']]); else insert_row($pdo,'accounting_entries',$towData);
        upsert_tow_segment($pdo,['operation_id'=>$opId,'glider_raw_flight_id'=>null,'tow_raw_flight_id'=>null,'glider_callsign'=>$glider,'tow_callsign'=>$plane,'tow_pilot_name'=>qv($i,'tow_pilot_name'),'segment_start'=>dtv($date,$takeoff),'segment_end'=>dtv($date,$towLd),'tow_minutes'=>mins_v($date,$takeoff,$towLd),'tow_height_m'=>null,'cost_center'=>'tow','approval_status'=>'pending','correction_note'=>'Korrigierter Schlepp']);
        if($mergeId && $mergeId!==$opId){$pdo->prepare('DELETE FROM accounting_entries WHERE operation_id=?')->execute([$mergeId]);$pdo->prepare('DELETE FROM tow_segments WHERE operation_id=?')->execute([$mergeId]);$pdo->prepare('DELETE FROM operations WHERE id=?')->execute([$mergeId]);}
    } elseif ($gliderFilled) {
        $glider=qv($i,'glider_callsign'); $gliLd=qv($i,'glider_landing_time');
        update_row($pdo,'operations',['operation_date'=>$date,'kind'=>'self_launch','glider_callsign'=>$glider,'glider_pilot_name'=>qv($i,'pilot_name'),'instructor_name'=>qv($i,'attendant_name'),'tow_callsign'=>null,'takeoff_time'=>$takeoff,'takeoff_airfield'=>qv($i,'departure_location','LSZJ'),'glider_landing_time'=>$gliLd,'glider_landing_airfield'=>qv($i,'arrival_location','LSZJ'),'tow_height_m'=>null,'approval_status'=>'pending','correction_note'=>'Korrigierter einzelner Segelflug'],'id=:id',['id'=>$opId]);
        $pdo->prepare("DELETE FROM accounting_entries WHERE operation_id=? AND entry_type IN ('tow_charge','towplane_own')")->execute([$opId]);
        $pdo->prepare('DELETE FROM tow_segments WHERE operation_id=?')->execute([$opId]);
        $gliderData=['operation_id'=>$opId,'entry_type'=>'glider_flight','callsign'=>$glider,'pilot_name'=>qv($i,'pilot_name'),'attendant_name'=>qv($i,'attendant_name'),'departure_time'=>dtv($date,$takeoff),'departure_location'=>qv($i,'departure_location','LSZJ'),'arrival_time'=>dtv($date,$gliLd),'arrival_location'=>qv($i,'arrival_location','LSZJ'),'flight_minutes'=>mins_v($date,$takeoff,$gliLd),'landing_count'=>1,'start_type'=>1,'charge_mode'=>(int)qv($i,'charge_mode',2),'invoiced'=>0,'comment'=>qv($i,'comment','Korrigiert: Segelflugdaten aktualisiert'),'tow_height_m'=>null,'tow_minutes'=>null,'tow_callsign'=>null,'tow_pilot_name'=>null,'tow_arrival_location'=>null,'vf_flight_type_id'=>int_null(qv($i,'vf_flight_type_id')),'approval_role'=>'glider_pilot','approval_status'=>'pending','correction_note'=>null,'exported_at'=>null,'export_batch'=>null];
        if($e['glider']) update_row($pdo,'accounting_entries',$gliderData,'id=:id',['id'=>$e['glider']['id']]); else insert_row($pdo,'accounting_entries',$gliderData);
    } elseif ($motorFilled) {
        $plane=qv($i,'tow_callsign'); $towLd=qv($i,'tow_landing_time');
        update_row($pdo,'operations',['operation_date'=>$date,'kind'=>'towplane_only','glider_callsign'=>null,'glider_pilot_name'=>null,'instructor_name'=>null,'tow_callsign'=>$plane,'takeoff_time'=>$takeoff,'takeoff_airfield'=>qv($i,'departure_location','LSZJ'),'glider_landing_time'=>null,'glider_landing_airfield'=>null,'tow_height_m'=>null,'approval_status'=>'pending','correction_note'=>'Korrigierter einzelner Motorflug'],'id=:id',['id'=>$opId]);
        $pdo->prepare("DELETE FROM accounting_entries WHERE operation_id=? AND entry_type IN ('glider_flight','tow_charge')")->execute([$opId]);
        $pdo->prepare('DELETE FROM tow_segments WHERE operation_id=?')->execute([$opId]);
        $motorData=['operation_id'=>$opId,'entry_type'=>'towplane_own','callsign'=>$plane,'pilot_name'=>null,'attendant_name'=>null,'departure_time'=>dtv($date,$takeoff),'departure_location'=>qv($i,'departure_location','LSZJ'),'arrival_time'=>dtv($date,$towLd),'arrival_location'=>qv($i,'arrival_location','LSZJ'),'flight_minutes'=>mins_v($date,$takeoff,$towLd),'landing_count'=>1,'start_type'=>1,'charge_mode'=>(int)qv($i,'charge_mode',2),'invoiced'=>0,'comment'=>qv($i,'tow_comment','Korrigiert: Motorflugdaten aktualisiert'),'tow_height_m'=>null,'tow_minutes'=>null,'tow_callsign'=>null,'tow_pilot_name'=>qv($i,'tow_pilot_name'),'tow_arrival_location'=>null,'vf_flight_type_id'=>int_null(qv($i,'tow_flight_type_id')),'approval_role'=>'tow_pilot','approval_status'=>'pending','correction_note'=>null,'exported_at'=>null,'export_batch'=>null];
        if($e['towplane_own']) update_row($pdo,'accounting_entries',$motorData,'id=:id',['id'=>$e['towplane_own']['id']]); else insert_row($pdo,'accounting_entries',$motorData);
    }
    set_pending_operation($pdo,$opId);
    $pdo->commit(); json_response(['ok'=>true,'operation_id'=>$opId]);
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); json_response(['ok'=>false,'error'=>$e->getMessage()],500); }
?>
