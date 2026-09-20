<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

$user = auth_require_login();
require_any_role(['DUTY_OFFICER', 'ADMIN']);
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LSZJ Tagesabschluss</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .checklist { list-style: none; padding: 0; margin: 0; }
        .checklist li { margin: .45rem 0; padding: .7rem .8rem; border-radius: 8px; }
        .check-ok { background: #eefaf1; border-left: 5px solid #17823b; }
        .check-open { background: #fff8e5; border-left: 5px solid #b36b00; }
        .check-bad { background: #fff0f0; border-left: 5px solid #b00020; }
        .status-word { display: inline-block; min-width: 5rem; font-weight: 700; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .metric-small { background: #fff; border: 1px solid #ddd; border-radius: 9px; padding: 10px; }
        .metric-small strong { display: block; font-size: 1.5rem; }
        .issue-list { margin: .4rem 0 0; }
        .hidden { display: none !important; }
        .form-block label { display: block; margin-bottom: .5rem; white-space: normal; }
        .form-block textarea { margin-left: 0; width: 100%; }
    </style>
</head>
<body>
<div class="card">
    <div class="row">
        <div><strong>Angemeldet:</strong> <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="flight_approvals.php">Flugfreigaben</a>
            <a href="duty_officer.php">Flugdienstleiter</a>
        </div>
    </div>
</div>

<h1>LSZJ Tagesabschluss</h1>
<p class="info">
    Der Flugdienstleiter schliesst den Betriebstag erst ab, nachdem die Flugdaten geprüft
    und alle Flüge freigegeben wurden. Mit dem Abschluss werden die Tagesdaten für den
    Export nach Vereinsflieger freigegeben.
</p>

<div class="card top">
    <label for="date">Betriebstag
        <input id="date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <button id="checkButton" type="button">Tagesabschluss prüfen</button>
</div>

<div id="message" aria-live="polite"></div>

<section class="card">
    <h2>Checkliste Flugdienstleiter</h2>
    <ul id="checklist" class="checklist">
        <li class="check-open"><span class="status-word">OFFEN</span> Noch nicht geprüft.</li>
    </ul>
</section>

<section class="card">
    <h2>Tagesstatus</h2>
    <div id="metrics" class="metrics"></div>
    <div id="issues"></div>
</section>

<section id="closeSection" class="card form-block hidden">
    <h2>Betriebstag abschliessen und für VF freigeben</h2>
    <p class="okbox">
        Alle automatischen Prüfungen sind erfüllt. Mit dem Abschluss bestätigt der
        Flugdienstleiter die fachliche Prüfung und gibt die Tagesdaten für den C4-Export frei.
    </p>
    <label>
        <input id="confirmReviewed" type="checkbox">
        Ich habe die Flugdaten des gesamten Betriebstags geprüft.
    </label>
    <label>
        <input id="confirmApproved" type="checkbox">
        Ich bestätige, dass alle Flüge vollständig und freigegeben sind.
    </label>
    <label for="closeNote">Bemerkung zum Tagesabschluss</label>
    <textarea id="closeNote" maxlength="1000"></textarea>
    <button id="closeButton" class="ok" type="button">Betriebstag abschliessen und für VF freigeben</button>
</section>

<section id="closedSection" class="card hidden">
    <h2>Betriebstag abgeschlossen</h2>
    <div id="closedInfo" class="okbox"></div>
    <div id="reopenBlock" class="form-block">
        <label for="reopenReason">Begründung für Wiederöffnung <span class="required-marker">*</span></label>
        <textarea id="reopenReason" minlength="5" maxlength="1000"></textarea>
        <button id="reopenButton" class="warn" type="button">Betriebstag wieder öffnen</button>
    </div>
</section>

<script>
'use strict';
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
const el = id => document.getElementById(id);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
}[character]));

function showMessage(text, type = 'info') {
    el('message').innerHTML = text
        ? `<div class="${type === 'error' ? 'warnbox' : type === 'ok' ? 'okbox' : 'info'}">${escapeHtml(text)}</div>`
        : '';
}

async function getStatus() {
    const date = el('date').value;
    const response = await fetch(`api_flight_day_status.php?date=${encodeURIComponent(date)}`, {
        credentials: 'same-origin',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Tagesstatus konnte nicht geladen werden.');
    return data;
}

async function postAction(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...body, csrf_token: csrfToken})
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Aktion fehlgeschlagen.');
    return data;
}

function render(status) {
    const state = status.state || {};
    const counts = status.counts || {};
    const closed = state.status === 'closed';
    const red = Number(status.red_count || 0);
    const yellow = Number(status.yellow_count || 0);
    const checks = [
        ['Alle gestarteten Flüge besitzen eine Landung.', !(status.red || []).some(item => item.code === 'missing_landing')],
        ['Keine strukturell unvollständigen Flugoperationen.', !(status.red || []).some(item => item.code === 'structural')],
        ['Keine offenen Flüge.', Number(counts.pending || 0) === 0],
        ['Keine Flüge mit Korrektur erforderlich.', Number(counts.correction_required || 0) === 0],
        ['Alle Flüge sind freigegeben oder bereits exportiert.', yellow === 0],
        ['Tagesabschlussprüfung ist grün.', Boolean(status.green)],
        ['Betriebstag ist abgeschlossen und für den VF-Export freigegeben.', closed]
    ];

    el('checklist').innerHTML = checks.map(([label, ok]) =>
        `<li class="${ok ? 'check-ok' : 'check-open'}"><span class="status-word">${ok ? 'OK' : 'OFFEN'}</span>${escapeHtml(label)}</li>`
    ).join('');

    el('metrics').innerHTML = [
        ['Rot', red], ['Gelb', yellow], ['Freigegeben', counts.approved || 0],
        ['Exportiert', counts.exported || 0], ['Offen', counts.pending || 0],
        ['Korrektur nötig', counts.correction_required || 0]
    ].map(([label, value]) => `<div class="metric-small"><strong>${escapeHtml(value)}</strong>${escapeHtml(label)}</div>`).join('');

    const issues = [...(status.red || []), ...(status.yellow || [])];
    el('issues').innerHTML = issues.length
        ? `<h3>Offene Punkte</h3><ul class="issue-list">${issues.map(item => `<li>Operation ${escapeHtml(item.operation_id)}: ${escapeHtml(item.message || item.code)}${item.fields?.length ? ` (${escapeHtml(item.fields.join(', '))})` : ''}</li>`).join('')}</ul>`
        : '<p class="okbox">Keine fachlichen oder strukturellen offenen Punkte.</p>';

    el('closeSection').classList.toggle('hidden', closed || !status.green);
    el('closedSection').classList.toggle('hidden', !closed);
    if (closed) {
        el('closedInfo').innerHTML = `Für den VF-Export freigegeben am <strong>${escapeHtml(state.closed_at || '')}</strong>${state.closed_by_name ? ` durch <strong>${escapeHtml(state.closed_by_name)}</strong>` : ''}.`;
    }
}

async function load() {
    showMessage('Tagesstatus wird geprüft.');
    try {
        const status = await getStatus();
        render(status);
        showMessage('', 'info');
    } catch (error) {
        showMessage(error.message, 'error');
    }
}

el('checkButton').addEventListener('click', load);
el('date').addEventListener('change', load);
el('closeButton').addEventListener('click', async () => {
    if (!el('confirmReviewed').checked || !el('confirmApproved').checked) {
        showMessage('Beide Bestätigungen sind für den Tagesabschluss erforderlich.', 'error');
        return;
    }
    if (!confirm(`Betriebstag ${el('date').value} abschliessen und für VF freigeben?`)) return;
    try {
        await postAction('api_close_flight_day.php', {date: el('date').value, note: el('closeNote').value.trim()});
        showMessage('Betriebstag wurde abgeschlossen und für den VF-Export freigegeben.', 'ok');
        await load();
    } catch (error) {
        showMessage(error.message, 'error');
    }
});
el('reopenButton').addEventListener('click', async () => {
    const reason = el('reopenReason').value.trim();
    if (reason.length < 5) {
        showMessage('Für die Wiederöffnung ist eine Begründung mit mindestens fünf Zeichen erforderlich.', 'error');
        return;
    }
    if (!confirm(`Betriebstag ${el('date').value} wieder öffnen? Die VF-Exportfreigabe wird aufgehoben.`)) return;
    try {
        await postAction('api_reopen_flight_day.php', {date: el('date').value, reason});
        showMessage('Betriebstag wurde wieder geöffnet. Die VF-Exportfreigabe ist aufgehoben.', 'ok');
        await load();
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

document.addEventListener('DOMContentLoaded', load);
</script>
</body>
</html>
