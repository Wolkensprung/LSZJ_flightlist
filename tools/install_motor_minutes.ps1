param([string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist")
$ErrorActionPreference = "Stop"
$src=Join-Path $ProjectRoot 'src'
$public=Join-Path $ProjectRoot 'public'
$stamp=Get-Date -Format 'yyyyMMdd_HHmmss'
$utf8=New-Object System.Text.UTF8Encoding($false)

function Save([string]$Path,[string]$Old,[string]$New){
 if($Old -eq $New){Write-Host "Keine Aenderung: $Path";return}
 Copy-Item $Path "$Path.bak-$stamp"
 [IO.File]::WriteAllText($Path,$New,$utf8)
 Write-Host "Angepasst: $Path"
}
function Require-File([string]$Path){if(-not(Test-Path $Path)){throw "Datei fehlt: $Path"}}

# 1) api_update_flight_data.php
$p=Join-Path $src 'api_update_flight_data.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains("'motor_minutes'")){
 $anchor="            'flight_minutes',"
 if(-not $c.Contains($anchor)){throw 'Marker flight_minutes in api_update_flight_data.php fehlt.'}
 $c=$c.Replace($anchor,$anchor+"`r`n            'motor_minutes',")
}
Save $p $o $c

# 2) api_create_manual_flight.php: Feld nur beim Segelflug speichern
$p=Join-Path $src 'api_create_manual_flight.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains('motor_minutes_for_entry')){
 $marker="require __DIR__ . '/helpers.php';"
 if($c.Contains($marker)){$c=$c.Replace($marker,$marker+"`r`nrequire_once __DIR__ . '/motor_minutes.php';")}
 else{throw 'helpers.php-Marker in api_create_manual_flight.php fehlt.'}
 # Add column and value in generic INSERT used by current project
 $c=$c.Replace('flight_minutes, landing_count','flight_minutes, motor_minutes, landing_count')
 $c=$c.Replace("`$input['flight_minutes'] ?? null, `$input['start_type']", "`$input['flight_minutes'] ?? null, motor_minutes_for_entry((string)`$type, `$input['motor_minutes'] ?? null), `$input['start_type']")
}
Save $p $o $c

# 3) api_save_operation_correction.php: permit glider motor minutes
$p=Join-Path $src 'api_save_operation_correction.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains("'motor_minutes'=>")){
 $marker="require __DIR__ . '/lszj_correction_lib.php';"
 if($c.Contains($marker)){$c=$c.Replace($marker,$marker+"`r`nrequire_once __DIR__ . '/motor_minutes.php';")}
 else{throw 'Korrektur-Library-Marker fehlt.'}
 $needle="'flight_minutes'=>mins_v(`$date,`$takeoff,`$gliLd),"
 if(-not $c.Contains($needle)){throw 'Segelflug-data-Marker in api_save_operation_correction.php fehlt.'}
 $c=$c.Replace($needle,$needle+"'motor_minutes'=>motor_minutes_normalize(qv(`$i,'motor_minutes')),")
}
Save $p $o $c

# 4) flight_approvals.php: field displayed only in glider card and sent on update
$p=Join-Path $public 'flight_approvals.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains('Motorminuten')){
 $needle='<label>Flugminuten <input id="${p}min" value="${esc(e.flight_minutes??'''')}"></label>'
 if(-not $c.Contains($needle)){throw 'Flugminuten-GUI-Marker in flight_approvals.php fehlt.'}
 $addition='<label>Motorminuten <input id="${p}motormin" type="number" min="0" value="${esc(e.motor_minutes??'''')}"></label>'
 $c=$c.Replace($needle,$needle+$addition)
 $needle2="d.flight_minutes=qs(p+'min').value;"
 if(-not $c.Contains($needle2)){throw 'Glider-update-Marker in flight_approvals.php fehlt.'}
 $c=$c.Replace($needle2,$needle2+"d.motor_minutes=qs(p+'motormin').value;")
}
Save $p $o $c

# 5) manual_flight.php: add only to glider form and payload
$p=Join-Path $public 'manual_flight.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains('id="motor_minutes"')){
 $needle='<label>Flugminuten <input id="flight_minutes" type="number" min="0"></label>'
 if(-not $c.Contains($needle)){throw 'Flugminuten-Marker in manual_flight.php fehlt.'}
 $c=$c.Replace($needle,$needle+'<label>Motorminuten <input id="motor_minutes" type="number" min="0"></label>')
 $payload="flight_minutes:qs('flight_minutes').value"
 if($c.Contains($payload)){$c=$c.Replace($payload,$payload+",motor_minutes:qs('motor_minutes').value")}
 else{throw 'Payload-Marker in manual_flight.php fehlt.'}
}
Save $p $o $c

# 6) flight_correction.php: add to glider section and payload
$p=Join-Path $public 'flight_correction.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains('id="motor_minutes"')){
 $needle='<label>Flugminuten <input id="flight_minutes" type="number" min="0"></label>'
 if(-not $c.Contains($needle)){throw 'Flugminuten-Marker in flight_correction.php fehlt.'}
 $c=$c.Replace($needle,$needle+'<label>Motorminuten <input id="motor_minutes" type="number" min="0"></label>')
 $payload="flight_minutes:qs('flight_minutes').value"
 if($c.Contains($payload)){$c=$c.Replace($payload,$payload+",motor_minutes:qs('motor_minutes').value")}
 else{throw 'Payload-Marker in flight_correction.php fehlt.'}
}
Save $p $o $c

# 7) Export: include helper, select motor_minutes, calculate synthetic times.
$p=Join-Path $src 'export_vf_flights_csv.php'; Require-File $p
$c=[IO.File]::ReadAllText($p);$o=$c
if(-not $c.Contains('motor_minutes_export_times')){
 $marker="require __DIR__ . '/helpers.php';"
 if($c.Contains($marker)){$c=$c.Replace($marker,$marker+"`r`nrequire_once __DIR__ . '/motor_minutes.php';")}
 else{throw 'helpers.php-Marker in export_vf_flights_csv.php fehlt.'}
 if(-not $c.Contains('motor_minutes')){throw 'Export muss motor_minutes im SELECT erhalten. Bitte aktuellen Export schicken; keine unsichere automatische Spaltenzuordnung.'}
 # expose calculated values for existing mapping code
 $loop='foreach ($rows as $row) {'
 if($c.Contains($loop)){$c=$c.Replace($loop,$loop+"`r`n    `$motorTimes = motor_minutes_export_times(`$row['motor_minutes'] ?? null);`r`n    `$row['motor_start'] = `$motorTimes['motor_start'];`r`n    `$row['motor_end'] = `$motorTimes['motor_end'];")}
 else{throw 'Export-Zeilenloop nicht gefunden.'}
}
Save $p $o $c

Write-Host ''
Write-Host "Motorminuten installiert. Backups: .bak-$stamp"
