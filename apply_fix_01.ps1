$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$target = Join-Path $projectRoot "src\MasterData\VereinsfliegerCsvImporter.php"

if (-not (Test-Path $target)) {
    throw "Datei nicht gefunden: $target"
}

$content = Get-Content $target -Raw -Encoding UTF8
$needle = @'
                $costLevel = TextNormalizer::clean($row['Kostenstufe'] ?? '');

                if ($userNo === '' || $name === '') {
'@
$replacement = @'
                $costLevel = TextNormalizer::clean($row['Kostenstufe'] ?? '');

                // Exakt dieser technische Vereinsdatensatz ist keine abrechenbare Person.
                // Alle anderen Datensaetze bleiben erhalten, auch Organisationen und Externe.
                if (
                    $memberNo === '0'
                    && $userNo === '306375'
                    && $name === 'Segelfluggruppe Biel, Segelfluggruppe Biel'
                ) {
                    $warnings[] = "Zeile {$line}: technischer Vereinsdatensatz ausgeschlossen";
                    $skipped++;
                    continue;
                }

                if ($userNo === '' || $name === '') {
'@

if (-not $content.Contains($needle)) {
    throw "Einbaustelle nicht gefunden. Die Datei wurde nicht veraendert."
}

$backup = "$target.bak-fix01"
Copy-Item $target $backup -Force
$content = $content.Replace($needle, $replacement)
Set-Content $target $content -Encoding UTF8
Write-Host "Fix eingebaut. Backup: $backup"
Write-Host "Bitte danach ausfuehren: php -l .\src\MasterData\VereinsfliegerCsvImporter.php"
