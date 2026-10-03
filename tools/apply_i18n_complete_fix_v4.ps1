[CmdletBinding()]
param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'
$Patcher = Join-Path $PSScriptRoot 'apply_i18n_complete_fix_v4.php'

if (-not (Test-Path -LiteralPath $Patcher)) {
    throw "Patcher fehlt: $Patcher"
}

& php $Patcher $ProjectRoot
if ($LASTEXITCODE -ne 0) {
    throw "PHP-Patcher fehlgeschlagen, Exitcode: $LASTEXITCODE"
}
