<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

$user = auth_require_login();
require_role('ADMIN');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VF-Personenimport</title>
    <link rel="stylesheet" href="app.css">
</head>
<body>
<div class="card">
    <div class="row">
        <strong>Angemeldet: <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <a class="button secondary" href="dashboard.php">Dashboard</a>
        <a class="button secondary" href="master_data_import.php">CSV-Stammdatenimport</a>
        <a class="button secondary" href="logout.php">Logout</a>
    </div>
</div>

<h1>Personen aus Vereinsflieger</h1>
<p class="info">
    Sicherer Abgleich über VF-Benutzernummer und, falls diese geändert hat,
    über die eindeutige Mitgliedsnummer. Nur lokal vorhandene Personen bleiben
    unverändert. Identitätskonflikte sperren den Import.
</p>

<div class="card">
    <div class="row">
        <button id="previewButton" type="button">Vorschau laden</button>
        <button id="importButton" class="ok" type="button" disabled>Sicher importieren</button>
    </div>
    <p>
        <label>
            <input id="confirmCheckbox" type="checkbox">
            Ich habe neue, geänderte und erkannte UID-Wechsel geprüft.
        </label>
    </p>
</div>

<div id="result" class="card">Noch keine Vorschau geladen.</div>

<script>
'use strict';

const VERSION = '2026-09-09-uid-matching-v1';
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const previewButton = document.getElementById('previewButton');
const importButton = document.getElementById('importButton');
const confirmCheckbox = document.getElementById('confirmCheckbox');
const resultBox = document.getElementById('result');
let previewValid = false;
let importAllowed = false;
let busy = false;

const labels = {
    vf_member_no: 'Mitgliedsnummer', display_name: 'Name', email: 'E-Mail',
    mobile: 'Mobil', membership_status: 'Mitgliedsstatus',
    cost_level: 'Kostenstufe', is_active: 'Aktivstatus'
};

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[character]);
}

function updateButtons() {
    previewButton.disabled = busy;
    importButton.disabled = busy || !previewValid || !importAllowed || !confirmCheckbox.checked;
}

async function callApi(action) {
    const response = await fetch(`api_vf_person_sync.php?v=${VERSION}`, {
        method: 'POST', cache: 'no-store', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Cache-Control': 'no-cache'},
        body: JSON.stringify({
            action,
            confirm_safe_import: action === 'import' && confirmCheckbox.checked,
            csrf_token: csrfToken,
            page_version: VERSION
        })
    });
    const text = (await response.text()).replace(/^\uFEFF/, '').trim();
    let data;
    try { data = JSON.parse(text); }
    catch (error) { throw new Error(`API liefert kein JSON (HTTP ${response.status}): ${text.slice(0, 180)}`); }
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
}

function differences(items) {
    if (!items?.length) return '';
    return items.map(item => `${esc(labels[item.field] || item.field)}: «${esc(item.local)}» → «${esc(item.vf)}»`).join('<br>');
}

function simpleTable(title, items) {
    if (!items?.length) return `<h3>${esc(title)}</h3><p>Keine.</p>`;
    return `<h3>${esc(title)}</h3><table><tr><th>VF-UID</th><th>Mitgliedsnr.</th><th>Name</th><th>Status</th></tr>`
        + items.map(item => `<tr><td>${esc(item.vf_user_no)}</td><td>${esc(item.vf_member_no)}</td><td>${esc(item.display_name)}</td><td>${esc(item.membership_status)}</td></tr>`).join('')
        + '</table>';
}

function changeTable(items) {
    if (!items?.length) return '<h3>Geänderte Personen</h3><p>Keine.</p>';
    return `<h3>Geänderte Personen</h3><table><tr><th>VF-UID</th><th>Name</th><th>Abweichungen</th></tr>`
        + items.map(item => `<tr><td>${esc(item.vf_user_no)}</td><td>${esc(item.display_name)}</td><td>${differences(item.differences)}</td></tr>`).join('')
        + '</table>';
}

function uidChangeTable(items) {
    if (!items?.length) return '<h3>Erkannte UID-Wechsel</h3><p>Keine.</p>';
    return `<h3>Erkannte UID-Wechsel</h3><table><tr><th>Mitgliedsnr.</th><th>Name lokal</th><th>Name VF</th><th>Alte UID</th><th>Neue UID</th><th>Weitere Änderungen</th></tr>`
        + items.map(item => `<tr><td>${esc(item.vf_member_no)}</td><td>${esc(item.local_name)}</td><td>${esc(item.vf_name)}</td><td>${esc(item.old_vf_user_no)}</td><td>${esc(item.new_vf_user_no)}</td><td>${differences(item.differences)}</td></tr>`).join('')
        + '</table>';
}

function conflictTable(items) {
    if (!items?.length) return '<div class="okbox">Keine Identitätskonflikte.</div>';
    return `<div class="warnbox"><strong>Import gesperrt: ${items.length} Identitätskonflikt(e).</strong></div>`
        + `<table><tr><th>Typ</th><th>Mitgliedsnr.</th><th>VF-UID</th><th>VF-Name</th><th>Lokale Angaben</th></tr>`
        + items.map(item => `<tr><td>${esc(item.type)}</td><td>${esc(item.vf_member_no)}</td><td>${esc(item.vf_user_no || item.second_vf_user_no)}</td><td>${esc(item.vf_name)}</td><td>${esc(item.local_member_name || '')} ${esc(item.local_member_vf_user_no || '')}</td></tr>`).join('')
        + '</table>';
}

async function loadPreview() {
    busy = true; previewValid = false; importAllowed = false;
    confirmCheckbox.checked = false; updateButtons();
    resultBox.textContent = 'Vorschau wird geladen …';
    try {
        const data = await callApi('preview');
        previewValid = true; importAllowed = data.import_allowed === true;
        resultBox.innerHTML = `<h2>Sichere Vorschau</h2><div class="grid">`
            + `<div class="metric"><b>${data.rows_received}</b><span>empfangen</span></div>`
            + `<div class="metric"><b>${data.new_count}</b><span>neu</span></div>`
            + `<div class="metric"><b>${data.uid_change_count}</b><span>UID-Wechsel</span></div>`
            + `<div class="metric"><b>${data.changed_count}</b><span>geändert</span></div>`
            + `<div class="metric"><b>${data.unchanged_count}</b><span>unverändert</span></div>`
            + `<div class="metric"><b>${data.local_only_count}</b><span>nur lokal</span></div>`
            + `<div class="metric"><b>${data.identity_conflict_count}</b><span>Konflikte</span></div>`
            + `<div class="metric"><b>0</b><span>werden deaktiviert</span></div></div>`
            + conflictTable(data.identity_conflicts)
            + simpleTable('Neue Personen', data.new_sample)
            + uidChangeTable(data.uid_change_sample)
            + changeTable(data.changed_sample)
            + simpleTable('Nur lokal, bleiben erhalten', data.local_only_sample);
    } catch (error) {
        resultBox.innerHTML = `<div class="warnbox">${esc(error.message)}</div>`;
    } finally { busy = false; updateButtons(); }
}

async function runImport() {
    if (busy || !previewValid || !importAllowed || !confirmCheckbox.checked) return;
    busy = true; updateButtons(); resultBox.textContent = 'Sicherer Import läuft …';
    try {
        const data = await callApi('import');
        previewValid = false; importAllowed = false; confirmCheckbox.checked = false;
        const result = data.result;
        resultBox.innerHTML = `<div class="okbox"><h2>Import erfolgreich</h2><p>`
            + `${result.inserted} neu, ${result.uid_changed} UID-Wechsel, `
            + `${result.updated} aktualisiert, ${result.unchanged} unverändert, `
            + `${result.deactivated} deaktiviert.</p></div>`;
    } catch (error) {
        resultBox.innerHTML = `<div class="warnbox">${esc(error.message)}</div>`;
    } finally { busy = false; updateButtons(); }
}

previewButton.addEventListener('click', loadPreview);
importButton.addEventListener('click', runImport);
confirmCheckbox.addEventListener('change', updateButtons);
updateButtons();
</script>
</body>
</html>
