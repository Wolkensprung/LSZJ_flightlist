[CmdletBinding()]
param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$apiPath = Join-Path $ProjectRoot 'src\api_search_pilots.php'
$jsPath = Join-Path $ProjectRoot 'public\master_data_autocomplete.js'
$policyPath = Join-Path $ProjectRoot 'src\Vereinsflieger\PilotSectorPolicy.php'
$testPath = Join-Path $ProjectRoot 'tools\test_pilot_sector_policy.php'

foreach ($path in @($apiPath, $jsPath, $policyPath, $testPath)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Datei fehlt: $path"
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$api = [System.IO.File]::ReadAllText($apiPath)
$js = [System.IO.File]::ReadAllText($jsPath)
$policy = [System.IO.File]::ReadAllText($policyPath)
$test = [System.IO.File]::ReadAllText($testPath)

# API nur anpassen, wenn showAll noch fehlt.
if (-not $api.Contains('$sectorFilter = $showAll')) {
    $apiOld = @'
$context=strtolower(trim((string)($_GET['context']??'')));
if($q===''){echo json_encode([],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$sectorFilter = match($context){
    'glider' => 'AND pm.can_fly_glider = 1',
    'motor' => 'AND pm.can_fly_motor = 1',
    default => '',
};
'@

    $apiNew = @'
$context=strtolower(trim((string)($_GET['context']??'')));
$showAll=(string)($_GET['all']??'')==='1';
if($q===''){echo json_encode([],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$sectorFilter = $showAll
    ? ''
    : match($context){
        'glider' => 'AND pm.can_fly_glider = 1',
        'motor' => 'AND pm.can_fly_motor = 1',
        default => '',
    };
'@

    if (-not $api.Contains($apiOld)) {
        throw 'API-Muster nicht gefunden. Keine Datei wurde geschrieben.'
    }

    $api = $api.Replace($apiOld, $apiNew)
}

# JavaScript nur anpassen, wenn all=1 noch fehlt.
$allParameterCode = "if(showAllPilots&&!isLoginInput(input)) url.searchParams.set('all','1');"

if (-not $js.Contains($allParameterCode)) {
    $jsOld = @'
if(type==='pilot'){ const id=(input.id||'').toLowerCase(); const key=[id,(input.name||'').toLowerCase(),(input.getAttribute('data-field')||'').toLowerCase()].join(' '); let context=''; if(isLoginInput(input)) context='login'; else if(/towpilot|motorpilot|motor_pilot/.test(key)) context='motor'; else if(/pilot|attendant|begleiter|instructor|fi/.test(key)) context='glider'; if(context) url.searchParams.set('context',context); }
'@

    $jsNew = @'
if(type==='pilot'){ const id=(input.id||'').toLowerCase(); const key=[id,(input.name||'').toLowerCase(),(input.getAttribute('data-field')||'').toLowerCase()].join(' '); let context=''; if(isLoginInput(input)) context='login'; else if(/towpilot|motorpilot|motor_pilot/.test(key)) context='motor'; else if(/pilot|attendant|begleiter|instructor|fi/.test(key)) context='glider'; if(context) url.searchParams.set('context',context); if(showAllPilots&&!isLoginInput(input)) url.searchParams.set('all','1'); }
'@

    if (-not $js.Contains($jsOld)) {
        throw 'JavaScript-Muster nicht gefunden. Keine Datei wurde geschrieben.'
    }

    $js = $js.Replace($jsOld, $jsNew)
}

# Ohne bekannte Sparte: kein Flugbereich.
$oldGlider = @'
            'can_fly_glider' => (
                !$hasKnownFlightSector
                || $hasGlider
                || $hasMotorGlider
            ) ? 1 : 0,
'@
$newGlider = @'
            'can_fly_glider' => (
                $hasGlider
                || $hasMotorGlider
            ) ? 1 : 0,
'@
$oldMotor = @'
            'can_fly_motor' => (
                !$hasKnownFlightSector
                || $hasMotor
                || $hasMotorGlider
            ) ? 1 : 0,
'@
$newMotor = @'
            'can_fly_motor' => (
                $hasMotor
                || $hasMotorGlider
            ) ? 1 : 0,
'@

$policy = $policy.Replace($oldGlider, $newGlider)
$policy = $policy.Replace($oldMotor, $newMotor)

if ($policy.Contains('!$hasKnownFlightSector')) {
    throw 'Policy-Muster nicht vollstaendig ersetzt. Keine Datei wurde geschrieben.'
}

# Testwerte fuer leere und unbekannte Sparte auf 0/0 setzen.
$test = $test.Replace(
    "'sectors' => [],`r`n        'glider' => 1,`r`n        'motor' => 1,",
    "'sectors' => [],`r`n        'glider' => 0,`r`n        'motor' => 0,"
)
$test = $test.Replace(
    "'sectors' => [],`n        'glider' => 1,`n        'motor' => 1,",
    "'sectors' => [],`n        'glider' => 0,`n        'motor' => 0,"
)
$test = $test.Replace(
    "'sectors' => ['Andere Sparte'],`r`n        'glider' => 1,`r`n        'motor' => 1,",
    "'sectors' => ['Andere Sparte'],`r`n        'glider' => 0,`r`n        'motor' => 0,"
)
$test = $test.Replace(
    "'sectors' => ['Andere Sparte'],`n        'glider' => 1,`n        'motor' => 1,",
    "'sectors' => ['Andere Sparte'],`n        'glider' => 0,`n        'motor' => 0,"
)

# Erst nach allen Vorpruefungen schreiben.
[System.IO.File]::WriteAllText($apiPath, $api, $utf8NoBom)
[System.IO.File]::WriteAllText($jsPath, $js, $utf8NoBom)
[System.IO.File]::WriteAllText($policyPath, $policy, $utf8NoBom)
[System.IO.File]::WriteAllText($testPath, $test, $utf8NoBom)

# Nachpruefung ohne komplexe Parser-Ausdruecke.
$apiCheck = [System.IO.File]::ReadAllText($apiPath)
$jsCheck = [System.IO.File]::ReadAllText($jsPath)
$policyCheck = [System.IO.File]::ReadAllText($policyPath)
$testCheck = [System.IO.File]::ReadAllText($testPath)

if (-not $apiCheck.Contains('$sectorFilter = $showAll')) {
    throw 'API-Nachpruefung fehlgeschlagen.'
}
if (-not $jsCheck.Contains($allParameterCode)) {
    throw 'JavaScript-Nachpruefung fehlgeschlagen.'
}
if ($policyCheck.Contains('!$hasKnownFlightSector')) {
    throw 'Policy-Nachpruefung fehlgeschlagen.'
}
if (-not $testCheck.Contains("'sectors' => []")) {
    throw 'Test-Nachpruefung fehlgeschlagen.'
}

Write-Host 'Pilotensuche-Fix v3 erfolgreich angewendet.' -ForegroundColor Green
Write-Host '  - Keine bekannte Sparte: kein Flugbereich.'
Write-Host '  - Checkbox aus: Spartenfilter aktiv.'
Write-Host '  - Checkbox an: all=1 und alle Piloten sichtbar.'
Write-Host '  - Login bleibt von all=1 unberuehrt.'
