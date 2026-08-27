param([string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist")
$ErrorActionPreference = "Stop"
$php = (Get-Command php).Source
$script = Join-Path $ProjectRoot "bin\close_stale_duty_officer.php"
if (-not (Test-Path $script)) { throw "Datei fehlt: $script" }
$action = New-ScheduledTaskAction -Execute $php -Argument ('"' + $script + '"') -WorkingDirectory $ProjectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At "00:01"
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable
Register-ScheduledTask -TaskName "LSZJ Flugdienstleiter Tageswechsel" -Action $action -Trigger $trigger -Settings $settings -Description "Schliesst offene Flugdienstleiter-Schichten nach Mitternacht" -Force
Write-Host "Aufgabe installiert: LSZJ Flugdienstleiter Tageswechsel, taeglich 00:01"
