param([string]$ProjectRoot = "C:\Projekte\LSZJ_flightlist")

$ErrorActionPreference = "Stop"
$src = Join-Path $ProjectRoot "src"
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Patch-File([string]$Name, [array]$Replacements) {
    $path = Join-Path $src $Name
    if (-not (Test-Path $path)) { throw "Datei fehlt: $path" }
    $content = [System.IO.File]::ReadAllText($path)
    if ($content.Contains("api_authenticated_actor")) {
        Write-Host "Bereits umgestellt: $Name"
        return
    }
    $original = $content
    foreach ($replacement in $Replacements) {
        $old = $replacement[0]
        $new = $replacement[1]
        if (-not $content.Contains($old)) {
            throw "Erwarteter Code in $Name nicht gefunden: $old"
        }
        $content = $content.Replace($old, $new)
    }
    Copy-Item $path "$path.bak-$stamp"
    [System.IO.File]::WriteAllText($path, $content, $utf8)
    Write-Host "Auf auth_user() umgestellt: $Name"
}

$helper = Join-Path $src "api_authenticated_actor.php"
if (-not (Test-Path $helper)) {
    throw "Datei fehlt: $helper. ZIP bitte im Projektstamm entpacken."
}

Patch-File "api_approve_entry.php" @(
    @("require __DIR__ . '/helpers.php';", "require __DIR__ . '/helpers.php';`r`nrequire_once __DIR__ . '/api_authenticated_actor.php';"),
    @("`$pdo = db();", "`$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);`r`n`$pdo = db();"),
    @("`$user = trim(`$input['user'] ?? 'unknown');", "`$user = `$actor['display_name'];`r`n`$userId = `$actor['id'];"),
    @("SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?", "SET approval_status='approved', approved_by=?, approved_by_user_id=?, approved_at=NOW() WHERE id=?"),
    @("`$stmt->execute([`$user, `$id]);", "`$stmt->execute([`$user, `$userId, `$id]);")
)

Patch-File "api_update_entry.php" @(
    @("require __DIR__ . '/helpers.php';", "require __DIR__ . '/helpers.php';`r`nrequire_once __DIR__ . '/api_authenticated_actor.php';"),
    @("`$pdo = db();", "`$actor = api_authenticated_actor(['PILOT', 'DUTY_OFFICER', 'ADMIN']);`r`n`$pdo = db();"),
    @("`$user = trim(`$input['user'] ?? 'unknown');", "`$user = `$actor['display_name'];")
)

Write-Host ""
Write-Host "Welle 1 abgeschlossen. Client-Werte fuer user werden in den beiden APIs nicht mehr vertraut."
Write-Host "Backups tragen die Endung .bak-$stamp"
