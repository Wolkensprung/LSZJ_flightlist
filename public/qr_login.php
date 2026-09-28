<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/session.php';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ QR-Login, Iteration 1</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .wrap{max-width:760px;margin:36px auto;padding:0 12px}
        .panel{padding:18px;border:1px solid #d9e0e8;border-radius:10px;background:#fff}
        .status{margin-top:16px;padding:13px;border-left:5px solid #1769aa;background:#eef6fc;border-radius:8px;line-height:1.5}
        .error{border-left-color:#b00020;background:#fff0f0;color:#970018}
        .mono{overflow-wrap:anywhere;font-family:Consolas,monospace;font-size:.9rem}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    </style>
</head>
<body>
<main class="wrap">
    <h1>QR-Login, Iteration 1</h1>
    <section class="panel">
        <p>Diese Iteration erzeugt eine kurzlebige C-Büro-Session und prüft das Statusmodell. Ein QR-Bild und die Smartphone-Freigabe folgen in der nächsten Iteration.</p>
        <div class="actions">
            <button id="create" type="button">Neue QR-Session erzeugen</button>
            <button id="check" type="button" disabled>Status prüfen</button>
        </div>
        <div id="result" class="status" hidden></div>
    </section>
</main>
<script>
const csrf = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
const createButton = document.getElementById('create');
const checkButton = document.getElementById('check');
const result = document.getElementById('result');
let token = null;

function show(html, error = false) {
    result.hidden = false;
    result.className = 'status' + (error ? ' error' : '');
    result.innerHTML = html;
}

createButton.addEventListener('click', async () => {
    createButton.disabled = true;
    try {
        const response = await fetch('api_qr_login_create.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: csrf}),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Session konnte nicht erstellt werden.');
        token = data.session.token;
        checkButton.disabled = false;
        show(
            '<strong>Status: ' + data.session.status + '</strong><br>' +
            'Gültig bis: ' + data.session.expires_at + '<br>' +
            'Smartphone-Link (Iteration 2):<br><span class="mono">' + data.session.approve_url + '</span>'
        );
    } catch (error) {
        show(error.message || String(error), true);
    } finally {
        createButton.disabled = false;
    }
});

checkButton.addEventListener('click', async () => {
    if (!token) return;
    try {
        const response = await fetch('api_qr_login_status.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token: csrf, token}),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Status konnte nicht gelesen werden.');
        show('<strong>Status: ' + data.session.status + '</strong><br>Gültig bis: ' + data.session.expires_at);
    } catch (error) {
        show(error.message || String(error), true);
    }
});
</script>
</body>
</html>
