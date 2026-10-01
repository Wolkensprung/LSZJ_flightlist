[CmdletBinding()]
param([string]$ProjectRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path)
$ErrorActionPreference='Stop'; $utf8=New-Object System.Text.UTF8Encoding($false)
$approvals=Join-Path $ProjectRoot 'public\flight_approvals.php'
$ktrax=Join-Path $ProjectRoot 'public\ktrax_import_range.js'
$sourceKtrax=Join-Path $PSScriptRoot '..\public\ktrax_import_range.js'
if(!(Test-Path $approvals)){throw "Datei fehlt: $approvals"}; if(!(Test-Path $sourceKtrax)){throw "Datei fehlt: $sourceKtrax"}
[IO.File]::WriteAllText($ktrax,[IO.File]::ReadAllText($sourceKtrax),$utf8)
$s=[IO.File]::ReadAllText($approvals)
# Translate technical operation kind at render time.
$s=$s.Replace('${esc(op.kind||'''')}','${esc(window.lszjI18n?window.lszjI18n.t(op.kind||''''):(op.kind||''''))}')
# Cache-bust kTrax and i18n assets.
$s=[regex]::Replace($s,'<script src="ktrax_import_range\.js(?:\?v=[^"]*)?"></script>','<script src="ktrax_import_range.js?v=20261001_4"></script>')
$s=[regex]::Replace($s,'<script src="i18n\.js(?:\?v=[^"]*)?"></script>','<script src="i18n.js?v=20261001_4"></script>')
[IO.File]::WriteAllText($approvals,$s,$utf8)
Write-Host 'i18n Komplettfix angewendet:' -ForegroundColor Green
Write-Host ' - kTrax Dialoge ersetzt'
Write-Host ' - technischer Operationstyp wird übersetzt'
Write-Host ' - Asset-Versionen aktualisiert'
