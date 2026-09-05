<?php
declare(strict_types=1);

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
        <div>
            <strong>Angemeldet:</strong>
            <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div>
            <a class="button secondary" href="dashboard.php">Dashboard</a>
        </div>

        <div>
            <a class="button secondary" href="master_data_import.php">
                CSV-Stammdatenimport
            </a>
        </div>

        <div>
            <a class="button secondary" href="logout.php">Logout</a>
        </div>
    </div>
</div>

<h1>Personen aus Vereinsflieger</h1>

<p class="info">
    Die Vorschau liest die Personenliste aus Vereinsflieger, ändert aber keine Daten.
    Erst der bestätigte Import schreibt nach <code>pilots_master</code>.
</p>

<div class="card">
    <div class="row">
        <button id="previewButton" type="button">
            Vorschau laden
        </button>

        <button id="importButton" class="ok" type="button" disabled>
            Bestätigt importieren
        </button>
    </div>

    <p>
        <label>
            <input id="completeCheckbox" type="checkbox">
            Ich bestätige, dass die angezeigte VF-Liste vollständig ist.
            Fehlende bestehende Personen dürfen deaktiviert werden.
        </label>
    </p>
</div>

<div id="result" class="card">
    Noch keine Vorschau geladen.
</div>

<script>
'use strict';

const csrfToken = <?= json_encode(
    csrf_token(),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;

const previewButton = document.getElementById('previewButton');
const importButton = document.getElementById('importButton');
const completeCheckbox = document.getElementById('completeCheckbox');
const resultBox = document.getElementById('result');

let previewIsValid = false;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[character]);
}

function updateImportButton() {
    importButton.disabled = !(
        previewIsValid && completeCheckbox.checked
    );
}

function setBusy(isBusy) {
    previewButton.disabled = isBusy;

    if (isBusy) {
        importButton.disabled = true;
    } else {
        updateImportButton();
    }
}

async function parseJsonResponse(response) {
    const responseText = await response.text();

    try {
        return JSON.parse(responseText);
    } catch (error) {
        const excerpt = responseText.slice(0, 180);

        throw new Error(
            `API liefert kein JSON (HTTP ${response.status}). ` +
            `Antwortanfang: ${excerpt}`
        );
    }
}

function renderWarnings(warnings) {
    if (!Array.isArray(warnings) || warnings.length === 0) {
        return '';
    }

    return `
        <div class="warnbox">
            <strong>Hinweise:</strong><br>
            ${warnings.map(escapeHtml).join('<br>')}
        </div>
    `;
}

function renderSample(sample) {
    if (!Array.isArray(sample) || sample.length === 0) {
        return '<p>Keine Stichprobe verfügbar.</p>';
    }

    const rows = sample.map(person => `
        <tr>
            <td>${escapeHtml(person.Benutzernummer)}</td>
            <td>${escapeHtml(person.MitgliedsNr)}</td>
            <td>${escapeHtml(person.Name)}</td>
            <td>${escapeHtml(person.Mitgliedsstatus)}</td>
            <td>${escapeHtml(person.Kostenstufe)}</td>
        </tr>
    `).join('');

    return `
        <h3>Stichprobe</h3>
        <table>
            <thead>
                <tr>
                    <th>VF-Nr.</th>
                    <th>Mitgliedsnr.</th>
                    <th>Name</th>
                    <th>Mitgliedsstatus</th>
                    <th>Kostenstufe</th>
                </tr>
            </thead>
            <tbody>
                ${rows}
            </tbody>
        </table>
    `;
}

function renderPreview(data) {
    resultBox.innerHTML = `
        <h2>Vorschau</h2>

        <div class="grid">
            <div class="metric">
                <b>${escapeHtml(data.rows_received)}</b>
                <span>empfangen</span>
            </div>

            <div class="metric">
                <b>${escapeHtml(data.new)}</b>
                <span>neu</span>
            </div>

            <div class="metric">
                <b>${escapeHtml(data.changed)}</b>
                <span>geändert oder reaktiviert</span>
            </div>

            <div class="metric">
                <b>${escapeHtml(data.unchanged)}</b>
                <span>unverändert</span>
            </div>

            <div class="metric">
                <b>${escapeHtml(data.would_deactivate)}</b>
                <span>würden deaktiviert</span>
            </div>
        </div>

        ${renderWarnings(data.adapter_warnings)}
        ${renderSample(data.sample)}
    `;
}

function renderImportResult(data) {
    const importResult = data.result ?? {};

    resultBox.innerHTML = `
        <div class="okbox">
            <h2>Import erfolgreich</h2>
            <p>
                ${escapeHtml(importResult.rows_imported)} importiert,
                ${escapeHtml(importResult.rows_skipped)} übersprungen.
            </p>
        </div>

        ${renderWarnings(importResult.adapter_warnings)}
    `;
}

function renderError(error) {
    resultBox.innerHTML = `
        <div class="warnbox">
            ${escapeHtml(error.message)}
        </div>
    `;
}

async function callSyncApi(action) {
    const response = await fetch('api_vf_person_sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            action: action,
            confirm_complete: completeCheckbox.checked,
            csrf_token: csrfToken
        })
    });

    const data = await parseJsonResponse(response);

    if (!response.ok || !data.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
}

async function loadPreview() {
    previewIsValid = false;
    completeCheckbox.checked = false;
    setBusy(true);
    resultBox.textContent = 'Vorschau wird geladen …';

    try {
        const data = await callSyncApi('preview');
        previewIsValid = true;
        renderPreview(data);
    } catch (error) {
        renderError(error);
    } finally {
        setBusy(false);
    }
}

async function importPersons() {
    if (!previewIsValid || !completeCheckbox.checked) {
        return;
    }

    setBusy(true);
    resultBox.textContent = 'Personen werden importiert …';

    try {
        const data = await callSyncApi('import');
        previewIsValid = false;
        completeCheckbox.checked = false;
        renderImportResult(data);
    } catch (error) {
        renderError(error);
    } finally {
        setBusy(false);
    }
}

previewButton.addEventListener('click', loadPreview);
importButton.addEventListener('click', importPersons);
completeCheckbox.addEventListener('change', updateImportButton);

updateImportButton();
</script>

</body>
</html>
