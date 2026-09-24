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

# API: Parameter all einlesen.
if (-not $api.Contains('$showAll')) {
    $contextPattern = [regex]::Escape('$context=strtolower(trim((string)($_GET[''context'']??'''')));')
    $contextRegex = New-Object System.Text.RegularExpressions.Regex($contextPattern)

    if (-not $contextRegex.IsMatch($api)) {
        throw 'API-Kontextzeile nicht gefunden. Keine Datei wurde geschrieben.'
    }

    $showAllLine = '$showAll=(string)($_GET[''all'']??'''')===''1'';'
    $api = $contextRegex.Replace(
        $api,
        '$0' + [Environment]::NewLine + $showAllLine,
        1
    )
}

# API: all=1 hebt den Spartenfilter auf.
if (-not $api.Contains('$sectorFilter = $showAll')) {
    $filterPattern = '(?s)\$sectorFilter\s*=\s*match\(\$context\)\s*\{.*?\};'
    $filterRegex = New-Object System.Text.RegularExpressions.Regex($filterPattern)

    if (-not $filterRegex.IsMatch($api)) {
        throw 'API-Spartenfilter nicht gefunden. Keine Datei wurde geschrieben.'
    }

    $filterReplacement = @'
$sectorFilter = $showAll
    ? ''
    : match($context){
        'glider' => 'AND pm.can_fly_glider = 1',
        'motor' => 'AND pm.can_fly_motor = 1',
        default => '',
    };
'@

    $api = $filterRegex.Replace($api, $filterReplacement, 1)
}

# JavaScript: robuste Suche unabhängig von Leerzeichen/Minifizierung.
$allJs = "if(showAllPilots&&!isLoginInput(input))url.searchParams.set('all','1');"

if (-not $js.Contains($allJs)) {
    $contextJsPattern = "if\s*\(\s*context\s*\)\s*url\.searchParams\.set\(\s*'context'\s*,\s*context\s*\)\s*;"
    $contextJsRegex = New-Object System.Text.RegularExpressions.Regex($contextJsPattern)

    if (-not $contextJsRegex.IsMatch($js)) {
        throw 'JavaScript-Kontextstelle nicht gefunden. Keine Datei wurde geschrieben.'
    }

    $js = $contextJsRegex.Replace(
        $js,
        '$0' + $allJs,
        1
    )
}

# Policy: keine/unbekannte Sparte ergibt 0/0.
$policy = $policy.Replace(
    "!`$hasKnownFlightSector`r`n                || `$hasGlider",
    '$hasGlider'
)
$policy = $policy.Replace(
    "!`$hasKnownFlightSector`n                || `$hasGlider",
    '$hasGlider'
)
$policy = $policy.Replace(
    "!`$hasKnownFlightSector`r`n                || `$hasMotor",
    '$hasMotor'
)
$policy = $policy.Replace(
    "!`$hasKnownFlightSector`n                || `$hasMotor",
    '$hasMotor'
)

if ($policy.Contains('!$hasKnownFlightSector')) {
    throw 'Policy konnte nicht sicher angepasst werden. Keine Datei wurde geschrieben.'
}

# Tests auf 0/0 korrigieren.
$emptyPattern = "('sectors'\s*=>\s*\[\]\s*,\s*'glider'\s*=>\s*)1(\s*,\s*'motor'\s*=>\s*)1"
$unknownPattern = "('sectors'\s*=>\s*\['Andere Sparte'\]\s*,\s*'glider'\s*=>\s*)1(\s*,\s*'motor'\s*=>\s*)1"
$test = [regex]::Replace($test, $emptyPattern, '${1}0${2}0')
$test = [regex]::Replace($test, $unknownPattern, '${1}0${2}0')

# Vorprüfung.
if (-not $api.Contains('$sectorFilter = $showAll')) {
    throw 'API-Vorprüfung fehlgeschlagen. Keine Datei wurde geschrieben.'
}
if (-not $js.Contains($allJs)) {
    throw 'JavaScript-Vorprüfung fehlgeschlagen. Keine Datei wurde geschrieben.'
}
if ($policy.Contains('!$hasKnownFlightSector')) {
    throw 'Policy-Vorprüfung fehlgeschlagen. Keine Datei wurde geschrieben.'
}

[System.IO.File]::WriteAllText($apiPath, $api, $utf8NoBom)
[System.IO.File]::WriteAllText($jsPath, $js, $utf8NoBom)
[System.IO.File]::WriteAllText($policyPath, $policy, $utf8NoBom)
[System.IO.File]::WriteAllText($testPath, $test, $utf8NoBom)

Write-Host 'Pilotensuche-Fix v4 erfolgreich angewendet.' -ForegroundColor Green
Write-Host '  - Keine bekannte Sparte: kein Flugbereich.'
Write-Host '  - Checkbox aus: Spartenfilter aktiv.'
Write-Host '  - Checkbox an: all=1 und alle Piloten sichtbar.'
Write-Host '  - Login bleibt von all=1 unberuehrt.'
