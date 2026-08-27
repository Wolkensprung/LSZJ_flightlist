<?php
declare(strict_types=1);

$projectRoot = $argv[1] ?? 'C:\\Projekte\\LSZJ_flightlist';
$path = rtrim($projectRoot, "\\/") . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'flight_day.php';

if (!is_file($path)) {
    fwrite(STDERR, "Datei fehlt: {$path}" . PHP_EOL);
    exit(1);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Datei konnte nicht gelesen werden: {$path}" . PHP_EOL);
    exit(1);
}

$old = <<<'PHP'
            if($needsLanding && !empty($e['departure_time']) && empty($e['arrival_time'])){
                $red[]=['code'=>'missing_landing','operation_id'=>$opId,'entry_id'=>(int)$e['id'],'entry_type'=>$type,'callsign'=>$e['callsign'],'pilot'=>$type==='glider_flight'?$e['pilot_name']:$e['tow_pilot_name'],'departure_time'=>$e['departure_time'],'message'=>'Gestarteter Flug ohne erfasste Landung','ktrax_url'=>'https://ktrax.kisstech.ch/ktrax'];
            }
            if(in_array($status,['pending','correction_required'],true)){
PHP;

$new = <<<'PHP'
            $isRedIncident = $needsLanding
                && !empty($e['departure_time'])
                && empty($e['arrival_time']);

            if($isRedIncident){
                $red[]=['code'=>'missing_landing','operation_id'=>$opId,'entry_id'=>(int)$e['id'],'entry_type'=>$type,'callsign'=>$e['callsign'],'pilot'=>$type==='glider_flight'?$e['pilot_name']:$e['tow_pilot_name'],'departure_time'=>$e['departure_time'],'message'=>'Gestarteter Flug ohne erfasste Landung','ktrax_url'=>'https://ktrax.kisstech.ch/ktrax'];
            }
            elseif(in_array($status,['pending','correction_required'],true)){
PHP;

if (str_contains($content, '$isRedIncident')) {
    echo "Prioritaetsregel bereits vorhanden. Keine Aenderung." . PHP_EOL;
    exit(0);
}

if (!str_contains($content, $old)) {
    fwrite(STDERR, "Erwarteter Block wurde nicht gefunden. Keine Aenderung vorgenommen." . PHP_EOL);
    exit(2);
}

$updated = str_replace($old, $new, $content, $count);
if ($count !== 1) {
    fwrite(STDERR, "Block wurde {$count}-mal gefunden. Keine Aenderung vorgenommen." . PHP_EOL);
    exit(3);
}

$backup = $path . '.bak-priority-' . date('Ymd_His');
if (!copy($path, $backup)) {
    fwrite(STDERR, "Backup konnte nicht erstellt werden." . PHP_EOL);
    exit(4);
}

if (file_put_contents($path, $updated) === false) {
    copy($backup, $path);
    fwrite(STDERR, "Schreiben fehlgeschlagen; Original wurde wiederhergestellt." . PHP_EOL);
    exit(5);
}

echo "Korrigiert: {$path}" . PHP_EOL;
echo "Backup: {$backup}" . PHP_EOL;
echo "Rot uebersteuert jetzt Gelb fuer denselben Flug ohne Landung." . PHP_EOL;
