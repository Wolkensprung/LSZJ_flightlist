param(
    [string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist"
)

$ErrorActionPreference = "Stop"
$public = Join-Path $ProjectRoot "public"
$src = Join-Path $ProjectRoot "src"
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"

function Backup-File([string]$Path) {
    if (-not (Test-Path $Path)) { throw "Datei fehlt: $Path" }
    Copy-Item $Path "$Path.bak-$stamp"
}

function Write-Utf8NoBom([string]$Path, [string]$Content) {
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $utf8)
}

function Add-Guard([string]$FileName, [string]$GuardCode) {
    $path = Join-Path $public $FileName
    if (-not (Test-Path $path)) { throw "Datei fehlt: $path" }
    $content = [System.IO.File]::ReadAllText($path)
    if ($content.Contains("lszj_require_page_")) {
        Write-Host "Bereits geschuetzt: $FileName"
        return
    }
    Backup-File $path
    $open = $content.IndexOf("<?php")
    if ($open -lt 0) { throw "Kein PHP-Block in $FileName gefunden" }
    $content = $content.Insert($open + 5, "`r`n" + $GuardCode + "`r`n")
    Write-Utf8NoBom $path $content
    Write-Host "Geschuetzt: $FileName"
}

function Add-DutyNav([string]$FileName) {
    $path = Join-Path $public $FileName
    if (-not (Test-Path $path)) { throw "Datei fehlt: $path" }
    $content = [System.IO.File]::ReadAllText($path)

    # Nur ein echter Navigationslink gilt als bereits vorhanden.
    $navLinkPattern = '<a\s+[^>]*href=["'']duty_officer\.php["''][^>]*>\s*Flugdienstleiter\s*</a>'
    if ([regex]::IsMatch($content, $navLinkPattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
        Write-Host "Navigationslink bereits vorhanden: $FileName"
        return
    }

    # Bevorzugter Anker: Link zur manuellen Flugerfassung innerhalb der bestehenden Navigation.
    $anchors = @(
        '<a href="#" onclick="nav(''manual_flight.php'');return false;">+ Flug manuell erfassen</a>',
        '<a href="manual_flight.php">+ Flug manuell erfassen</a>',
        '<a class="button" href="#" onclick="nav(''manual_flight.php'');return false;">+ Flug manuell erfassen</a>'
    )

    foreach ($anchor in $anchors) {
        if ($content.Contains($anchor)) {
            Backup-File $path
            $newLink = '<a href="duty_officer.php">Flugdienstleiter</a>'
            $content = $content.Replace($anchor, $anchor + $newLink)
            Write-Utf8NoBom $path $content
            Write-Host "Navigationslink erweitert: $FileName"
            return
        }
    }

    # Robuster Fallback: direkt vor dem Ende des ersten div.nav einfuegen.
    $navPattern = '(?is)(<div\s+class=["'']nav["''][^>]*>.*?)(</div>)'
    $match = [regex]::Match($content, $navPattern)
    if ($match.Success) {
        Backup-File $path
        $newNav = $match.Groups[1].Value + '<a href="duty_officer.php">Flugdienstleiter</a>' + $match.Groups[2].Value
        $content = $content.Substring(0, $match.Index) + $newNav + $content.Substring($match.Index + $match.Length)
        Write-Utf8NoBom $path $content
        Write-Host "Navigationslink per Fallback erweitert: $FileName"
        return
    }

    Write-Warning "Navigation in $FileName nicht gefunden. Keine Aenderung vorgenommen."
}

$pageSecurity = Join-Path $src "page_security.php"
if (-not (Test-Path $pageSecurity)) {
    throw "Datei fehlt: $pageSecurity. Das Integrations-ZIP bitte im Projektstamm entpacken."
}
Write-Host "Sicherheitsmodul vorhanden: src\page_security.php"

$loginGuard = @'
require_once __DIR__ . '/../src/page_security.php';
$currentUser = lszj_require_page_login();
'@

$adminGuard = @'
require_once __DIR__ . '/../src/page_security.php';
$currentUser = lszj_require_page_role('ADMIN');
'@

Add-Guard "flight_approvals.php" $loginGuard
Add-Guard "manual_flight.php" $loginGuard
Add-Guard "flight_correction.php" $loginGuard
Add-Guard "master_data_import.php" $adminGuard

Add-DutyNav "dashboard.php"
Add-DutyNav "flight_approvals.php"
Add-DutyNav "manual_flight.php"

Write-Host ""
Write-Host "Integration abgeschlossen. Backups tragen die Endung .bak-$stamp"
Write-Host "Browser mit Ctrl+F5 neu laden."
