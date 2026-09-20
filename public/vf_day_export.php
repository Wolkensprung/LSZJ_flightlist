<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

$user = auth_require_login();
require_role('ADMIN');
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VF-Tagesexport C4</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .checklist { list-style: none; padding: 0; margin: 0; }
        .checklist li { margin: .45rem 0; padding: .7rem .8rem; border-radius: 8px; }
        .check-ok { background: #eefaf1; border-left: 5px solid #17823b; }
        .check-open { background: #fff8e5; border-left: 5px solid #b36b00; }
        .status-word { display: inline-block; min-width: 5rem; font-weight: 700; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .summary div { background: #fff; border: 1px solid #ddd; border-radius: 9px; padding: 10px; }
        .summary strong { display: block; font-size: 1.5rem; }
        .export-table { overflow-x: auto; }
        .export-table input[type="checkbox"] { width: auto; }
        .blocked-row { background: #fff8e5; }
        .hidden { display: none !important; }
        .confirm-block label { display: block; white-space: normal; margin: .6rem 0; }
        details pre { overflow-x: auto; white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="card">
    <div class="row">
        <div><strong>Angemeldet:</strong> <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="nav">
            <a href="vf_export_journal.php">Exportjournal</a>
            <a href="flight_day.php">Tagesabschluss</a>
            <a href="dashboard.php">Dashboard</a>
        </div>
    </div>
</div>

<h1>VF-Tagesexport C4</h1>
<p class="info">
    Im Pilotbetrieb führt ein Admin den Export nach jedem Flugtag manuell aus.
    Voraussetzung ist, dass der Flugdienstleiter den geprüften Betriebstag abgeschlossen
    und damit für den Export nach Vereinsflieger freigegeben hat.
</p>

<div class="card top">
    <label for="date">Flugdatum
        <input id="date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <button id="previewButton" type="button">Vorschau laden</button>
    <button id="selectAllButton" class="secondary" type="button" disabled>Alle sendbaren auswählen</button>
</div>

<div id="message" aria-live="polite"></div>

<section class="card">
    <h2>Checkliste Admin: C4-Tagesexport</h2>
    <ul id="checklist" class="checklist">
        <li class="check-open"><span class="status-word">OFFEN</span> Noch keine Vorschau geladen.</li>
    </ul>
</section>

<section id="summarySection" class="card hidden">
    <h2>Exportvorschau</h2>
    <div id="summary" class="summary"></div>
    <div id="items" class="export-table"></div>
</section>

<section id="runSection" class="card confirm-block hidden">
    <h2>Export bestätigen</h2>
    <p>Ausgewählt: <strong id="selectedCount">0</strong> Flüge.</p>
    <label>
        <input id="confirmDayClosed" type="checkbox">
        Ich habe geprüft, dass der Flugdienstleiter diesen Betriebstag abgeschlossen und für VF freigegeben hat.
    </label>
    <label>
        <input id="confirmPayloads" type="checkbox">
        Ich habe alle ausgewählten Flüge, Personen, Sonderfälle und Payloads geprüft.
    </label>
    <button id="runButton" class="ok" type="button" disabled>Tagesexport starten</button>
</section>

<section id="resultSection" class="card hidden">
    <h2>Exportergebnis</h2>
    <div id="result"></div>
    <p><a class="button secondary" href="vf_export_journal.php">Exportjournal öffnen</a></p>
</section>

<script>
'use strict';
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
let previewData = null;
let dayStatus = null;
const el = id => document.getElementById(id);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
}[character]));

function showMessage(text, type = 'info') {
    el('message').innerHTML = text
        ? `<div class="${type === 'error' ? 'warnbox' : type === 'ok' ? 'okbox' : 'info'}">${escapeHtml(text)}</div>`
        : '';
}

async function getDayStatus(date) {
    const response = await fetch(`api_flight_day_status.php?date=${encodeURIComponent(date)}`, {
        credentials: 'same-origin', cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Tagesstatus konnte nicht geladen werden.');
    return data;
}

async function exportApi(body) {
    const response = await fetch('api_vf_day_export.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...body, csrf_token: csrfToken})
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'C4-Anfrage fehlgeschlagen.');
    return data;
}

function dayIsClosed() {
    return (dayStatus?.state?.status || 'open') === 'closed';
}

function selectedIds() {
    return [...document.querySelectorAll('input[data-entry-id]:checked')].map(input => Number(input.dataset.entryId));
}

function refreshRunState() {
    const count = selectedIds().length;
    el('selectedCount').textContent = String(count);
    el('runButton').disabled = !dayIsClosed() || !dayStatus?.green || count === 0
        || !el('confirmDayClosed').checked || !el('confirmPayloads').checked;
}

function renderChecklist() {
    const closed = dayIsClosed();
    const items = previewData?.items || [];
    const sendable = items.filter(item => item.send_allowed === true).length;
    const blocked = items.length - sendable;
    const counts = dayStatus?.counts || {};
    const checks = [
        ['Betriebstag wurde durch den Flugdienstleiter abgeschlossen und für VF freigegeben.', closed],
        ['Tagesabschlussprüfung ist grün.', Boolean(dayStatus?.green)],
        ['Keine offenen oder korrekturbedürftigen Flüge.', Number(counts.pending || 0) === 0 && Number(counts.correction_required || 0) === 0],
        ['Eine aktuelle C4-Vorschau wurde geladen.', Boolean(previewData)],
        ['Mindestens ein Flug ist sendbar.', sendable > 0],
        ['Alle gesperrten Flüge und Sonderfälle wurden geprüft.', Boolean(previewData) && blocked === 0]
    ];
    el('checklist').innerHTML = checks.map(([label, ok]) =>
        `<li class="${ok ? 'check-ok' : 'check-open'}"><span class="status-word">${ok ? 'OK' : 'OFFEN'}</span>${escapeHtml(label)}</li>`
    ).join('');
}

function renderPreview() {
    const items = previewData.items || [];
    el('summary').innerHTML = [
        ['Kandidaten', previewData.candidate_count || items.length],
        ['Sendbar', previewData.sendable_count || 0],
        ['Gesperrt', previewData.blocked_count || 0],
        ['Maximum pro Lauf', previewData.max_flights || 50]
    ].map(([label, value]) => `<div><strong>${escapeHtml(value)}</strong>${escapeHtml(label)}</div>`).join('');

    if (!items.length) {
        el('items').innerHTML = '<p class="info">Für diesen Tag sind keine neuen Exportkandidaten vorhanden.</p>';
        return;
    }

    el('items').innerHTML = `<table><thead><tr><th>Auswahl</th><th>ID</th><th>Flugzeug</th><th>Pilot</th><th>Start</th><th>Status</th><th>Prüfung</th></tr></thead><tbody>${items.map(item => {
        const allowed = item.send_allowed === true;
        const issues = Array.isArray(item.issues) ? item.issues : [];
        return `<tr class="${allowed ? '' : 'blocked-row'}"><td><input type="checkbox" data-entry-id="${Number(item.entry_id)}" ${allowed ? '' : 'disabled'}></td><td>${Number(item.entry_id)}</td><td>${escapeHtml(item.callsign)}</td><td>${escapeHtml(item.pilot)}</td><td>${escapeHtml(item.departuretime)}</td><td>${allowed ? 'sendbar' : 'gesperrt'}</td><td>${issues.length ? escapeHtml(issues.join('; ')) : 'OK'}<details><summary>Payload</summary><pre>${escapeHtml(JSON.stringify(item.payload || {}, null, 2))}</pre></details></td></tr>`;
    }).join('')}</tbody></table>`;
    document.querySelectorAll('input[data-entry-id]').forEach(input => input.addEventListener('change', refreshRunState));
}

async function loadPreview() {
    const date = el('date').value;
    showMessage('Tagesstatus und C4-Vorschau werden geladen.');
    previewData = null;
    try {
        dayStatus = await getDayStatus(date);
        renderChecklist();
        if (!dayIsClosed()) {
            throw new Error('C4 gesperrt: Der Flugdienstleiter hat den Betriebstag noch nicht abgeschlossen und für VF freigegeben.');
        }
        if (!dayStatus.green) {
            throw new Error('C4 gesperrt: Die Tagesabschlussprüfung ist nicht grün.');
        }
        previewData = await exportApi({action: 'preview', date});
        renderChecklist();
        renderPreview();
        el('summarySection').classList.remove('hidden');
        el('runSection').classList.toggle('hidden', Number(previewData.sendable_count || 0) === 0);
        el('selectAllButton').disabled = Number(previewData.sendable_count || 0) === 0;
        showMessage('Vorschau geladen. Bitte jeden ausgewählten Flug und Payload prüfen.', 'ok');
        refreshRunState();
    } catch (error) {
        renderChecklist();
        el('summarySection').classList.add('hidden');
        el('runSection').classList.add('hidden');
        el('selectAllButton').disabled = true;
        showMessage(error.message, 'error');
    }
}

el('previewButton').addEventListener('click', loadPreview);
el('date').addEventListener('change', () => {
    previewData = null;
    dayStatus = null;
    el('summarySection').classList.add('hidden');
    el('runSection').classList.add('hidden');
    el('resultSection').classList.add('hidden');
    renderChecklist();
});
el('selectAllButton').addEventListener('click', () => {
    document.querySelectorAll('input[data-entry-id]:not(:disabled)').forEach(input => { input.checked = true; });
    refreshRunState();
});
el('confirmDayClosed').addEventListener('change', refreshRunState);
el('confirmPayloads').addEventListener('change', refreshRunState);
el('runButton').addEventListener('click', async () => {
    const ids = selectedIds();
    if (!ids.length) return;
    if (!confirm(`${ids.length} ausgewählte Flüge für ${el('date').value} nach VF exportieren?`)) return;
    el('runButton').disabled = true;
    showMessage('Tagesexport läuft. Seite nicht schliessen.');
    try {
        const data = await exportApi({
            action: 'run', date: el('date').value, entry_ids: ids,
            confirm_day_export: true
        });
        const result = data.result || data;
        const rows = result.items || [];
        el('result').innerHTML = `<div class="summary"><div><strong>${escapeHtml(result.successful || 0)}</strong>Erfolgreich</div><div><strong>${escapeHtml(result.failed || 0)}</strong>Fehler</div><div><strong>${escapeHtml(result.reconciliation_required || 0)}</strong>Abstimmung</div></div>${rows.length ? `<table><thead><tr><th>ID</th><th>Status</th><th>VF-flid</th><th>Meldung</th></tr></thead><tbody>${rows.map(row => `<tr><td>${escapeHtml(row.entry_id)}</td><td>${escapeHtml(row.status)}</td><td>${escapeHtml(row.flid)}</td><td>${escapeHtml(row.error)}</td></tr>`).join('')}</tbody></table>` : ''}`;
        el('resultSection').classList.remove('hidden');
        showMessage('Tagesexport abgeschlossen. Bitte Einzelergebnisse und Exportjournal prüfen.', 'ok');
        await loadPreview();
    } catch (error) {
        showMessage(error.message, 'error');
        refreshRunState();
    }
});

renderChecklist();
</script>
</body>
</html>
