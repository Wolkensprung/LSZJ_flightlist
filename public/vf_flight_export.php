<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
    <title>VF-Flugexport C1</title>
    <link rel="stylesheet" href="app.css">
</head>
<body>
<div class="card">
    <div class="row">
        <strong>Angemeldet: <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <a class="button secondary" href="dashboard.php">Dashboard</a>
        <a class="button secondary" href="logout.php">Logout</a>
    </div>
</div>

<h1>Vereinsflieger REST-Flugexport</h1>
<p class="info">
    Sichere Personenauflösung: Pilot, Begleiter und Schlepppilot müssen
    eindeutig einer aktiven Person in pilots_master zugeordnet werden.
    Kein Stapelversand, kein Löschen und kein flight/edit.
</p>

<div class="card">
    <div class="row">
        <label>Von <input id="from" type="date" value="<?= $today ?>"></label>
        <label>Bis <input id="to" type="date" value="<?= $today ?>"></label>
        <button id="preview" type="button">Vorschau laden</button>
    </div>
</div>

<div id="result" class="card">Noch keine Vorschau geladen.</div>

<script>
'use strict';

const csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
const result = document.getElementById('result');
let running = false;

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;',
        '"': '&quot;', "'": '&#039;'
    })[character]);
}

async function api(body) {
    const response = await fetch(
        'api_vf_flight_export.php?v=20260910-person-resolution-v1',
        {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({...body, csrf_token: csrf})
        }
    );

    const text = (await response.text()).replace(/^\uFEFF/, '').trim();
    let data;
    try {
        data = JSON.parse(text);
    } catch (error) {
        throw new Error('API liefert kein JSON: ' + text.slice(0, 180));
    }

    if (!response.ok || !data.ok) {
        throw new Error(data.error || 'HTTP ' + response.status);
    }

    return data;
}

function personLine(label, resolution) {
    if (!resolution || resolution.status === 'empty') {
        return `<div><strong>${esc(label)}:</strong> nicht belegt</div>`;
    }

    if (resolution.status === 'resolved') {
        return `<div class="okbox"><strong>${esc(label)}:</strong> `
            + `${esc(resolution.input_name)} → `
            + `${esc(resolution.display_name)} `
            + `(VF-UID ${esc(resolution.vf_user_no)}, `
            + `${esc(resolution.match_type)})</div>`;
    }

    const candidates = (resolution.candidates || [])
        .map(item => `${esc(item.display_name)} (${esc(item.vf_user_no)})`)
        .join('<br>');

    return `<div class="warnbox"><strong>${esc(label)}:</strong> `
        + `${esc(resolution.issue)}`
        + (candidates ? `<br>${candidates}` : '')
        + '</div>';
}

function personResolutionBlock(resolution) {
    return '<details open><summary>Personenzuordnung</summary>'
        + personLine('Pilot', resolution?.pilot)
        + personLine('Begleiter', resolution?.attendant)
        + personLine('Schlepppilot', resolution?.tow_pilot)
        + '</details>';
}

function payloadDetails(payload) {
    return '<details><summary>Payload anzeigen</summary><pre>'
        + esc(JSON.stringify(payload, null, 2))
        + '</pre></details>';
}

async function preview() {
    if (running) return;
    running = true;
    result.textContent = 'Vorschau wird geladen …';

    try {
        const data = await api({
            action: 'preview',
            from: document.getElementById('from').value,
            to: document.getElementById('to').value
        });

        let html = `<h2>Vorschau</h2><div class="grid">`
            + `<div class="metric"><b>${data.candidate_count}</b><span>Kandidaten</span></div>`
            + `<div class="metric"><b>${data.sendable_count}</b><span>sendbar</span></div>`
            + `<div class="metric"><b>${data.blocked_count}</b><span>blockiert</span></div>`
            + '</div>';

        html += '<table><thead><tr>'
            + '<th>ID</th><th>Flug</th><th>Zeit</th>'
            + '<th>Prüfung</th><th>Test</th>'
            + '</tr></thead><tbody>';

        for (const item of data.items) {
            const issues = item.issues.length
                ? '<div class="warnbox">'
                    + item.issues.map(esc).join('<br>')
                    + '</div>'
                : '<div class="okbox">Validiert</div>';

            const button = item.send_allowed
                ? `<label><input type="checkbox" id="c${item.entry_id}"> `
                    + 'Genau diesen Flug geprüft</label>'
                    + `<button class="ok" type="button" `
                    + `onclick="sendOne(${item.entry_id})">`
                    + 'Einzelnen Testflug senden</button>'
                : 'Gesperrt';

            html += `<tr>`
                + `<td>${item.entry_id}<br><small>Op ${item.operation_id}</small></td>`
                + `<td>${esc(item.callsign)}<br>${esc(item.pilot)}<br>`
                + `<small>${esc(item.entry_type)}</small></td>`
                + `<td>${esc(item.departuretime)}<br>${esc(item.arrivaltime)}</td>`
                + `<td>${issues}`
                + personResolutionBlock(item.person_resolution)
                + payloadDetails(item.payload)
                + `</td><td>${button}</td></tr>`;
        }

        html += '</tbody></table>';
        result.innerHTML = html;
    } catch (error) {
        result.innerHTML = '<div class="warnbox">'
            + esc(error.message)
            + '</div>';
    } finally {
        running = false;
    }
}

async function sendOne(id) {
    if (running) return;

    const checkbox = document.getElementById('c' + id);
    if (!checkbox?.checked) {
        alert('Bitte zuerst die Bestätigung für genau diesen Flug aktivieren.');
        return;
    }

    if (!confirm('Genau Flug-ID ' + id + ' einmal an Vereinsflieger senden?')) {
        return;
    }

    running = true;

    try {
        const data = await api({
            action: 'send_one',
            entry_id: id,
            confirm_single_test: true
        });

        result.innerHTML = '<div class="okbox"><h2>Testflug erfolgreich gesendet</h2>'
            + '<p>LSZJ-ID ' + id + ', VF-flid '
            + esc(data.result.flid) + '.</p></div>';
    } catch (error) {
        result.innerHTML = '<div class="warnbox">'
            + esc(error.message)
            + '</div>';
    } finally {
        running = false;
    }
}

document.getElementById('preview').addEventListener('click', preview);
</script>
</body>
</html>
