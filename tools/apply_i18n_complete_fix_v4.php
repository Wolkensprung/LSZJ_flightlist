<?php
declare(strict_types=1);

$projectRoot = $argv[1] ?? dirname(__DIR__);
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

function projectPath(string $root, string $relative): string
{
    return $root . '/' . ltrim($relative, '/');
}

function readProjectFile(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException("Datei fehlt: {$path}");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Datei konnte nicht gelesen werden: {$path}");
    }
    return $content;
}

function writeProjectFile(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("Datei konnte nicht geschrieben werden: {$path}");
    }
}

function replaceLiteral(string $content, string $old, string $new): string
{
    return str_replace($old, $new, $content);
}

function updateAssetVersion(string $content, string $asset, string $version): string
{
    $pattern = '~' . preg_quote($asset, '~') . '(?:\\?v=[^"\'\s>]*)?~u';
    return preg_replace($pattern, $asset . '?v=' . $version, $content) ?? $content;
}

$i18nPath = projectPath($projectRoot, 'public/i18n.js');
$qrPath = projectPath($projectRoot, 'public/qr_login.php');
$approvalsPath = projectPath($projectRoot, 'public/flight_approvals.php');
$autocompletePath = projectPath($projectRoot, 'public/master_data_autocomplete.js');

// 1. alt-Attribute in der zentralen Übersetzungsroutine ergänzen.
$i18n = readProjectFile($i18nPath);
$i18n = replaceLiteral(
    $i18n,
    "['title','placeholder','aria-label','value']",
    "['title','placeholder','aria-label','alt','value']"
);
$i18n = replaceLiteral(
    $i18n,
    "['title', 'placeholder', 'aria-label', 'value']",
    "['title', 'placeholder', 'aria-label', 'alt', 'value']"
);
if (!str_contains($i18n, "'aria-label','alt','value'")
    && !str_contains($i18n, "'aria-label', 'alt', 'value'")) {
    throw new RuntimeException('i18n.js: alt wurde nicht in der Attributliste gefunden.');
}
writeProjectFile($i18nPath, $i18n);

// 2. QR-Bild vor gültiger src sicher verbergen.
$qr = readProjectFile($qrPath);
$qr = replaceLiteral(
    $qr,
    'alt="QR-Code für die C-Büro-Anmeldung" hidden>',
    'alt="QR-Code für die C-Büro-Anmeldung" hidden aria-hidden="true">'
);
$qr = replaceLiteral(
    $qr,
    'qrImage.hidden=false;',
    "qrImage.hidden=false;qrImage.removeAttribute('aria-hidden');"
);
$qr = replaceLiteral(
    $qr,
    'qrImage.hidden = false;',
    "qrImage.hidden = false; qrImage.removeAttribute('aria-hidden');"
);
$qr = replaceLiteral(
    $qr,
    'qrImage.hidden=true;',
    "qrImage.hidden=true;qrImage.setAttribute('aria-hidden','true');qrImage.removeAttribute('src');"
);
$qr = replaceLiteral(
    $qr,
    'qrImage.hidden = true;',
    "qrImage.hidden = true; qrImage.setAttribute('aria-hidden','true'); qrImage.removeAttribute('src');"
);
$qr = updateAssetVersion($qr, 'i18n.js', '20261003_3');
writeProjectFile($qrPath, $qr);

// 3. Technischen Operationstyp direkt über i18n anzeigen.
$approvals = readProjectFile($approvalsPath);
$approvals = replaceLiteral(
    $approvals,
    '${esc(op.kind||\'\')}',
    '${esc(window.lszjI18n ? window.lszjI18n.t(op.kind||\'\') : (op.kind||\'\'))}'
);
$approvals = updateAssetVersion($approvals, 'ktrax_import_range.js', '20261003_3');
$approvals = updateAssetVersion($approvals, 'i18n.js', '20261003_3');
writeProjectFile($approvalsPath, $approvals);

// 4. Dynamische Autocomplete-Texte direkt übersetzen.
$autocomplete = readProjectFile($autocompletePath);
$pageBase = "const pageBase = new URL('.', window.location.href);";
$translator = "const tr = key => window.lszjI18n ? window.lszjI18n.t(key) : key;";
if (!str_contains($autocomplete, $translator)) {
    if (!str_contains($autocomplete, $pageBase)) {
        throw new RuntimeException('master_data_autocomplete.js: pageBase-Anker fehlt.');
    }
    $autocomplete = replaceLiteral($autocomplete, $pageBase, $pageBase . ' ' . $translator);
}

$replacements = [
    "label.innerHTML = '<input type=\"checkbox\"> Alle bekannten Piloten anzeigen';"
        => "label.innerHTML = '<input type=\"checkbox\"> ' + tr('Alle bekannten Piloten anzeigen');",
    "label.innerHTML='<input type=\"checkbox\"> Alle bekannten Piloten anzeigen';"
        => "label.innerHTML='<input type=\"checkbox\"> '+tr('Alle bekannten Piloten anzeigen');",
    "empty.textContent='Keine Treffer';"
        => "empty.textContent=tr('Keine Treffer');",
    "button.textContent='Externen Pilot / FI erfassen';"
        => "button.textContent=tr('Externen Pilot / FI erfassen');",
    "error.textContent='Nachname, Vorname, Mailadresse und Telefon sind Pflichtfelder.';"
        => "error.textContent=tr('Nachname, Vorname, Mailadresse und Telefon sind Pflichtfelder.');",
    "error.textContent='Bitte eine gültige Mailadresse eingeben.';"
        => "error.textContent=tr('Bitte eine gültige Mailadresse eingeben.');",
    "list.innerHTML='<div class=\"lszj-ac-empty\">Suche fehlgeschlagen</div>';"
        => "list.innerHTML='<div class=\"lszj-ac-empty\">'+tr('Suche fehlgeschlagen')+'</div>';",
];
foreach ($replacements as $old => $new) {
    $autocomplete = replaceLiteral($autocomplete, $old, $new);
}
writeProjectFile($autocompletePath, $autocomplete);

// 5. i18n.js auf jeder gerenderten PHP-Seite einbinden und Cache aktualisieren.
$publicPath = projectPath($projectRoot, 'public');
foreach (glob($publicPath . '/*.php') ?: [] as $phpFile) {
    $text = readProjectFile($phpFile);
    if (stripos($text, '<html') === false && stripos($text, '<!doctype') === false) {
        continue;
    }
    $text = preg_replace(
        '~<script\\s+src="i18n_hotfix_02\\.js[^"]*"\\s*></script>~u',
        '<script src="i18n.js?v=20261003_3"></script>',
        $text
    ) ?? $text;
    $text = updateAssetVersion($text, 'i18n.js', '20261003_3');
    if (!str_contains($text, 'i18n.js')) {
        if (str_contains($text, '</head>')) {
            $text = replaceLiteral(
                $text,
                '</head>',
                "    <script src=\"i18n.js?v=20261003_3\" defer></script>\n</head>"
            );
        } elseif (str_contains($text, '</body>')) {
            $text = replaceLiteral(
                $text,
                '</body>',
                "<script src=\"i18n.js?v=20261003_3\" defer></script>\n</body>"
            );
        }
    }
    writeProjectFile($phpFile, $text);
}

// 6. Auditbericht möglicher Resttexte erzeugen.
$markers = '~\\b(?:Alle|Bitte|Keine|Kein|Fehler|Speichern|Abbrechen|Löschen|Start|Landung|Flugzeit|Schleppzeit|Motorminuten|Benutzer|Betriebstag|Tagesabschluss)\\b|nicht gefunden|nicht möglich|erforderlich|ungültig|fehlgeschlagen|läuft|[ÄÖÜäöüß]~u';
$roots = [projectPath($projectRoot, 'public'), projectPath($projectRoot, 'src')];
$report = [];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (preg_match('~/(?:vendor|node_modules|backup|old)/~i', $path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $index => $line) {
            if (preg_match($markers, $line)) {
                $relative = ltrim(substr($path, strlen($projectRoot)), '/');
                $report[] = sprintf('%s:%d: %s', $relative, $index + 1, trim($line));
            }
        }
    }
}
$reportPath = projectPath($projectRoot, 'i18n_remaining_candidates.txt');
writeProjectFile($reportPath, implode(PHP_EOL, $report) . PHP_EOL);

echo "LSZJ i18n Komplettfix v4 angewendet.\n";
echo " - alt-Attribute werden übersetzt\n";
echo " - QR-Bild bleibt bis zur gültigen src unsichtbar\n";
echo " - dynamische Autocomplete-Texte verwenden i18n direkt\n";
echo " - technische Operationstypen werden übersetzt\n";
echo " - i18n.js ist auf allen gerenderten PHP-Seiten eingebunden\n";
echo " - Restkandidaten: {$reportPath}\n";
