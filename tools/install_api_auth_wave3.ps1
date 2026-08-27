param([string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist")

$ErrorActionPreference = "Stop"
$src = Join-Path $ProjectRoot "src"
$public = Join-Path $ProjectRoot "public"
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Save-Patched([string]$Path,[string]$Original,[string]$Patched) {
    if ($Original -eq $Patched) { return $false }
    Copy-Item $Path "$Path.bak-$stamp"
    [System.IO.File]::WriteAllText($Path,$Patched,$utf8)
    return $true
}

function Add-ActorGuard([string]$Name,[array]$Roles) {
    $path=Join-Path $src $Name
    if(-not(Test-Path $path)){Write-Warning "Nicht vorhanden: $Name";return}
    $content=[IO.File]::ReadAllText($path)
    $original=$content
    if(-not $content.Contains("api_authenticated_actor.php")){
        $anchor="require __DIR__ . '/helpers.php';"
        if($content.Contains($anchor)){
            $content=$content.Replace($anchor,$anchor+"`r`nrequire_once __DIR__ . '/api_authenticated_actor.php';")
        } else {
            $php=$content.IndexOf("<?php")
            if($php -lt 0){throw "Kein PHP-Block in $Name"}
            $content=$content.Insert($php+5,"`r`nrequire_once __DIR__ . '/api_authenticated_actor.php';`r`n")
        }
    }
    if(-not $content.Contains("`$actor = api_authenticated_actor(")){
        $rolesText=($Roles|ForEach-Object{"'$_'"}) -join ', '
        $guard="`$actor = api_authenticated_actor([$rolesText]);`r`n"
        $dbMarker='$pdo = db();'
        if($content.Contains($dbMarker)){$content=$content.Replace($dbMarker,$guard+$dbMarker)}
        else{$content=$content.Replace("require_once __DIR__ . '/api_authenticated_actor.php';","require_once __DIR__ . '/api_authenticated_actor.php';`r`n"+$guard)}
    }
    $patterns=@(
      "`$user = trim(`$input['user'] ?? 'unknown');",
      "`$user=trim(`$input['user']??'unknown');",
      "`$user = trim((string)(`$input['user'] ?? 'unknown'));",
      "`$user=qv(`$input,'user','unknown');",
      "`$user = qv(`$input, 'user', 'unknown');"
    )
    foreach($old in $patterns){if($content.Contains($old)){$content=$content.Replace($old,"`$user = `$actor['display_name'];`r`n`$userId = `$actor['id'];")}}
    # Verbleibende direkte Client-Identitaet erkennen, ohne komplexe Regex-Quoting-Regeln.
    $unsafeMarkers=@(
      "`$input['user']",
      '`$input["user"]',
      "qv(`$input,'user'",
      'qv(`$input,"user"'
    )
    foreach($marker in $unsafeMarkers){
        if($content.Contains($marker)){
            throw "Nicht automatisch sicher patchbar: $Name liest weiterhin input[user]. Keine Datei wurde geschrieben."
        }
    }
    if(Save-Patched $path $original $content){Write-Host "Authentifiziert: $Name"}else{Write-Host "Bereits authentifiziert: $Name"}
}

function Remove-FrontendUser([string]$Name){
    $path=Join-Path $public $Name
    if(-not(Test-Path $path)){Write-Warning "Nicht vorhanden: $Name";return}
    $content=[IO.File]::ReadAllText($path)
    $original=$content
    $replacements=@(
      @('<label>Benutzer <input id="user" value="demo"></label>',''),
      @("+'&user='+encodeURIComponent(qs('user').value)",''),
      @(",user:qs('user').value||'unknown'",''),
      @(", user: qs('user').value || 'unknown'",''),
      @("user:qs('user').value||'unknown',",''),
      @("qs('user').value=getParam('user','demo');",'')
    )
    foreach($pair in $replacements){
        $content=$content.Replace($pair[0],$pair[1])
    }
    if(Save-Patched $path $original $content){Write-Host "user-Parameter entfernt: $Name"}else{Write-Host "Keine user-Aenderung noetig: $Name"}
}

$helper=Join-Path $src 'api_authenticated_actor.php'
if(-not(Test-Path $helper)){throw "Es fehlt src\\api_authenticated_actor.php aus Welle 1."}

# Schreibende Rest-Endpunkte
Add-ActorGuard 'api_set_approval.php' @('PILOT','DUTY_OFFICER','ADMIN')
Add-ActorGuard 'api_create_manual_flight.php' @('PILOT','DUTY_OFFICER','ADMIN')
Add-ActorGuard 'api_save_operation_correction.php' @('PILOT','DUTY_OFFICER','ADMIN')
Add-ActorGuard 'api_delete_flight_part.php' @('DUTY_OFFICER','ADMIN')

# Konsistenzpruefung Welle 1
Add-ActorGuard 'api_approve_entry.php' @('PILOT','DUTY_OFFICER','ADMIN')
Add-ActorGuard 'api_update_entry.php' @('PILOT','DUTY_OFFICER','ADMIN')

# Verbleibende GUI-Parameter
Remove-FrontendUser 'manual_flight.php'
Remove-FrontendUser 'flight_correction.php'

Write-Host ""
Write-Host "Welle 3 abgeschlossen. Backups tragen die Endung .bak-$stamp"
Write-Host "Bitte die unten beschriebenen Funktionstests ausfuehren."
