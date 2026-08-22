$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$targets = @(
  'public\flight_approvals.php',
  'public\manual_flight.php',
  'public\flight_correction.php'
)
foreach ($rel in $targets) {
  $path = Join-Path $root $rel
  if (-not (Test-Path $path)) { Write-Warning "Nicht gefunden: $rel"; continue }
  $content = Get-Content $path -Raw -Encoding UTF8
  $backup = "$path.bak-dropdowns"
  if (-not (Test-Path $backup)) { Copy-Item $path $backup }
  if ($content -notmatch 'master_data_autocomplete\.css') {
    $content = $content -replace '</head>', "<link rel=`"stylesheet`" href=`"master_data_autocomplete.css`">`r`n</head>"
  }
  if ($content -notmatch 'master_data_autocomplete\.js') {
    $content = $content -replace '</body>', "<script src=`"master_data_autocomplete.js`"></script>`r`n</body>"
  }
  [System.IO.File]::WriteAllText($path, $content, [System.Text.UTF8Encoding]::new($false))
  Write-Host "Aktualisiert: $rel"
}
Write-Host 'Fertig. Backups enden auf .bak-dropdowns.'
