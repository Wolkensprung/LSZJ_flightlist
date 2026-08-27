param([string]$ProjectRoot="C:\Projekte\LSZJ_flightlist")
$ErrorActionPreference="Stop"
$public=Join-Path $ProjectRoot "public"
$stamp=Get-Date -Format "yyyyMMdd_HHmmss"
$targets=@("dashboard.php","flight_approvals.php","manual_flight.php","duty_officer.php")
$snippet='<?php if (has_role(''ADMIN'')): ?><a href="user_admin.php">Benutzerverwaltung</a><?php endif; ?>'
$utf8=New-Object System.Text.UTF8Encoding($false)
foreach($name in $targets){
  $path=Join-Path $public $name
  if(-not(Test-Path $path)){Write-Warning "Fehlt: $path";continue}
  $content=[IO.File]::ReadAllText($path)
  if($content.Contains('href="user_admin.php"')){Write-Host "Bereits vorhanden: $name";continue}
  $match=[regex]::Match($content,'(?is)(<div\s+class=["'']nav["''][^>]*>.*?)(</div>)')
  if(-not $match.Success){Write-Warning "Navigation nicht gefunden: $name";continue}
  Copy-Item $path "$path.bak-$stamp"
  $replacement=$match.Groups[1].Value+$snippet+$match.Groups[2].Value
  $content=$content.Substring(0,$match.Index)+$replacement+$content.Substring($match.Index+$match.Length)
  [IO.File]::WriteAllText($path,$content,$utf8)
  Write-Host "Benutzerverwaltung ergänzt: $name"
}
