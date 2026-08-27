param([string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist")

$ErrorActionPreference = "Stop"
$path = Join-Path $ProjectRoot "public\duty_officer.php"
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"

if (-not (Test-Path $path)) {
    throw "Datei fehlt: $path"
}

$content = [System.IO.File]::ReadAllText($path)

if ($content.Contains('href="user_admin.php"')) {
    Write-Host "Benutzerverwaltung bereits vorhanden: duty_officer.php"
    exit 0
}

$anchor = '<div><a class="button secondary" href="logout.php">Logout</a></div>'
$replacement = '<?php if (has_role(''ADMIN'')): ?><div><a class="button secondary" href="user_admin.php">Benutzerverwaltung</a></div><?php endif; ?>' + $anchor

if (-not $content.Contains($anchor)) {
    throw "Erwarteter Logout-Link in duty_officer.php nicht gefunden. Keine Aenderung vorgenommen."
}

Copy-Item $path "$path.bak-$stamp"
$content = $content.Replace($anchor, $replacement)
$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($path, $content, $utf8)

Write-Host "Benutzerverwaltung ergaenzt: duty_officer.php"
Write-Host "Backup: duty_officer.php.bak-$stamp"
