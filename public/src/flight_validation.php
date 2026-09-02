<?php
declare(strict_types=1);

function flight_entry_missing_fields(array $entry): array
{
    $type=(string)($entry['entry_type']??'');
    $missing=[];
    $blank=static fn(string $key): bool => trim((string)($entry[$key]??''))==='';
    $positive=static fn(string $key): bool => (int)($entry[$key]??0)>0;

    if ($blank('callsign')) $missing[]='Flugzeug';
    if ($blank('departure_time')) $missing[]='Startzeit';
    if (empty($entry['vf_flight_type_id'])) $missing[]='Flugart';
    if ($entry['charge_mode']===null || $entry['charge_mode']==='') $missing[]='Abrechnung';

    if ($type==='glider_flight') {
        if ($blank('pilot_name')) $missing[]='Segelflugpilot';
        if ($blank('arrival_time')) $missing[]='Landezeit';
        if (!$positive('flight_minutes')) $missing[]='Flugzeit';
    } elseif ($type==='towplane_own') {
        if ($blank('tow_pilot_name')) $missing[]='Motorpilot';
        if ($blank('arrival_time')) $missing[]='Landezeit';
        if (!$positive('flight_minutes')) $missing[]='Flugzeit';
    } elseif ($type==='tow_charge') {
        if ($blank('tow_callsign')) $missing[]='Schleppflugzeug';
        if ($blank('tow_pilot_name')) $missing[]='Schlepppilot';
        if (!$positive('tow_minutes')) $missing[]='Schleppzeit';
    } else {
        $missing[]='Unterstützter Eintragstyp';
    }
    return array_values(array_unique($missing));
}

function flight_operation_validation(PDO $pdo,int $operationId): array
{
    $stmt=$pdo->prepare('SELECT * FROM accounting_entries WHERE operation_id=? ORDER BY id');
    $stmt->execute([$operationId]);
    $entries=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$entries) return [['entry_id'=>null,'entry_type'=>null,'callsign'=>null,'fields'=>['Mindestens ein Abrechnungseintrag']]];
    $issues=[];
    foreach($entries as $entry){
        $fields=flight_entry_missing_fields($entry);
        if($fields)$issues[]=['entry_id'=>(int)$entry['id'],'entry_type'=>$entry['entry_type'],'callsign'=>$entry['callsign'],'fields'=>$fields];
    }
    return $issues;
}

function flight_validation_summary(array $issues): array
{
    $counts=[];
    foreach($issues as $issue)foreach($issue['fields'] as $field)$counts[$field]=($counts[$field]??0)+1;
    ksort($counts);
    return $counts;
}
