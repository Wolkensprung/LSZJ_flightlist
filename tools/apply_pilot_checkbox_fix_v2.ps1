[CmdletBinding()]
param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$apiPath = Join-Path $ProjectRoot 'src\api_search_pilots.php'
$jsPath = Join-Path $ProjectRoot 'public\master_data_autocomplete.js'

foreach ($path in @($apiPath, $jsPath)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Datei fehlt: $path"
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

$api = [System.IO.File]::ReadAllText($apiPath)
$js = [System.IO.File]::ReadAllText($jsPath)

# API: Checkbox all=1 deaktiviert nur den Spartenfilter.
# Ohne all=1 werden ausschliesslich explizit passende Sparten zugelassen.
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

if ($api.Contains($apiOld)) {
    $api = $api.Replace($apiOld, $apiNew)
} elseif (
    $api -notmatch '\$showAll\s*=.*\$_GET.*all'
    -or $api -notmatch '\$sectorFilter\s*=\s*\$showAll'
) {
    throw 'API-Muster nicht gefunden. src/api_search_pilots.php wurde nicht veraendert.'
}

# JS: Bei aktivierter Checkbox all=1 senden; beim Login niemals.
$jsOld = @'
if(type==='pilot'){ const id=(input.id||'').toLowerCase(); const key=[id,(input.name||'').toLowerCase(),(input.getAttribute('data-field')||'').toLowerCase()].join(' '); let context=''; if(isLoginInput(input)) context='login'; else if(/towpilot|motorpilot|motor_pilot/.test(key)) context='motor'; else if(/pilot|attendant|begleiter|instructor|fi/.test(key)) context='glider'; if(context) url.searchParams.set('context',context); }
'@

$jsNew = @'
if(type==='pilot'){ const id=(input.id||'').toLowerCase(); const key=[id,(input.name||'').toLowerCase(),(input.getAttribute('data-field')||'').toLowerCase()].join(' '); let context=''; if(isLoginInput(input)) context='login'; else if(/towpilot|motorpilot|motor_pilot/.test(key)) context='motor'; else if(/pilot|attendant|begleiter|instructor|fi/.test(key)) context='glider'; if(context) url.searchParams.set('context',context); if(showAllPilots&&!isLoginInput(input)) url.searchParams.set('all','1'); }
'@

if ($js.Contains($jsOld)) {
    $js = $js.Replace($jsOld, $jsNew)
} elseif (
    $js -notmatch "showAllPilots.*url\.searchParams\.set\('all','1'\)"
) {
    throw 'JavaScript-Muster nicht gefunden. public/master_data_autocomplete.js wurde nicht veraendert.'
}

# Policy: Eine Person ohne bekannte Sparte gehoert standardmaessig zu keinem Flugbereich.
$policyPath = Join-Path $ProjectRoot 'src\Vereinsflieger\PilotSectorPolicy.php'
if (-not (Test-Path -LiteralPath $policyPath)) {
    throw "Datei fehlt: $policyPath"
}
$policy = [System.IO.File]::ReadAllText($policyPath)

$policyOld = @'
            'can_fly_glider' => (
                !$hasKnownFlightSector
                || $hasGlider
                || $hasMotorGlider
            ) ? 1 : 0,

            'can_fly_motor' => (
                !$hasKnownFlightSector
                || $hasMotor
                || $hasMotorGlider
            ) ? 1 : 0,
'@

$policyNew = @'
            'can_fly_glider' => (
                $hasGlider
                || $hasMotorGlider
            ) ? 1 : 0,

            'can_fly_motor' => (
                $hasMotor
                || $hasMotorGlider
            ) ? 1 : 0,
'@

if ($policy.Contains($policyOld)) {
    $policy = $policy.Replace($policyOld, $policyNew)
} elseif (
    $policy -match '!\$hasKnownFlightSector'
) {
    throw 'Policy-Muster nicht gefunden. PilotSectorPolicy.php wurde nicht veraendert.'
}

# Test: Leere oder unbekannte Sparte muss 0/0 ergeben.
$testPath = Join-Path $ProjectRoot 'tools\test_pilot_sector_policy.php'
if (-not (Test-Path -LiteralPath $testPath)) {
    throw "Datei fehlt: $testPath"
}
$test = [System.IO.File]::ReadAllText($testPath)
$test = $test.Replace("'sectors' => [],`r`n        'glider' => 1,`r`n        'motor' => 1,", "'sectors' => [],`r`n        'glider' => 0,`r`n        'motor' => 0,")
$test = $test.Replace("'sectors' => [],`n        'glider' => 1,`n        'motor' => 1,", "'sectors' => [],`n        'glider' => 0,`n        'motor' => 0,")
$test = $test.Replace("'sectors' => ['Andere Sparte'],`r`n        'glider' => 1,`r`n        'motor' => 1,", "'sectors' => ['Andere Sparte'],`r`n        'glider' => 0,`r`n        'motor' => 0,")
$test = $test.Replace("'sectors' => ['Andere Sparte'],`n        'glider' => 1,`n        'motor' => 1,", "'sectors' => ['Andere Sparte'],`n        'glider' => 0,`n        'motor' => 0,")

[System.IO.File]::WriteAllText($apiPath, $api, $utf8NoBom)
[System.IO.File]::WriteAllText($jsPath, $js, $utf8NoBom)
[System.IO.File]::WriteAllText($policyPath, $policy, $utf8NoBom)
[System.IO.File]::WriteAllText($testPath, $test, $utf8NoBom)

$policyCheck = [System.IO.File]::ReadAllText($policyPath)
$testCheck = [System.IO.File]::ReadAllText($testPath)

if ($policyCheck -match '!\$hasKnownFlightSector') {
    throw 'Policy-Nachpruefung fehlgeschlagen: unbekannte Sparte ist noch freigegeben.'
}
if ($testCheck -notmatch "'sectors'\s*=>\s*\[\][\s\S]*?'glider'\s*=>\s*0[\s\S]*?'motor'\s*=>\s*0") {
    throw 'Test-Nachpruefung fehlgeschlagen: Leerfall 0/0 fehlt.'
}

Write-Host 'Pilotensuche-Fix v2 erfolgreich angewendet.' -ForegroundColor Green
Write-Host '  - Keine bekannte Sparte: kein Flugbereich.'
Write-Host '  - Checkbox aus: Spartenfilter aktiv.'
Write-Host '  - Checkbox an: all=1 und alle Piloten sichtbar.'
Write-Host '  - Login bleibt von all=1 unberuehrt.'
