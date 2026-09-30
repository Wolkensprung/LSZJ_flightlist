<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

// Eine bestehende persönliche oder C-Büro-Sitzung wird nicht überschrieben.
if (auth_user() !== null) {
    header('Location: dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ QR-Login</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .wrap{max-width:720px;margin:30px auto;padding:0 12px}
        .qr{display:block;width:min(320px,100%);height:auto;margin:18px auto;border:1px solid #d9e0e8}
        .status{padding:14px;border-left:5px solid #1769aa;background:#eef6fc;border-radius:8px;line-height:1.5}
        .good{border-color:#17823b;background:#eefaf1}
        .bad{border-color:#b00020;background:#fff0f0;color:#970018}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    </style>
</head>
<body>
<main class="wrap">
    <h1>Mit Smartphone anmelden</h1>
    <section class="card">
        <p>QR-Code mit dem persönlichen Smartphone scannen und die Anmeldung dort bestätigen.</p>
        <div class="actions">
            <button id="create" type="button">QR-Code erzeugen</button>
            <a class="button secondary" href="passkey_login.php">Mit Passkey anmelden</a>
        </div>
        <img id="qr" class="qr" alt="QR-Code für die C-Büro-Anmeldung" hidden>
        <div id="status" class="status" role="status" aria-live="polite" hidden></div>
    </section>
</main>
<script>
const csrf = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
let token = null;
let timer = null;
let consuming = false;

const qrImage = document.getElementById('qr');
const statusBox = document.getElementById('status');
const createButton = document.getElementById('create');

function show(message, kind = '') {
    statusBox.hidden = false;
    statusBox.className = 'status' + (kind ? ' ' + kind : '');
    statusBox.textContent = message;
}

function stopPolling() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

async function consumeApprovedSession() {
    if (!token || consuming) return;
    consuming = true;
    stopPolling();
    show('Freigabe erhalten. C-Büro wird angemeldet ...', 'good');

    try {
        const response = await fetch('api_qr_login_consume.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrf, token}),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.error || 'QR-Anmeldung konnte nicht übernommen werden.');
        }
        window.location.assign(result.redirect || 'dashboard.php');
    } catch (error) {
        show(error.message || String(error), 'bad');
        createButton.disabled = false;
        consuming = false;
    }
}

async function poll() {
    if (!token || consuming) return;

    try {
        const response = await fetch('api_qr_login_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrf, token}),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.error || 'Status konnte nicht gelesen werden.');
        }

        const status = result.session.status;
        if (status === 'pending') {
            show('Warte auf die Bestätigung am Smartphone ...');
            return;
        }
        if (status === 'approved') {
            await consumeApprovedSession();
            return;
        }

        stopPolling();
        createButton.disabled = false;
        qrImage.hidden = true;

        if (status === 'expired') {
            show('Der QR-Code ist abgelaufen. Erzeuge einen neuen QR-Code.', 'bad');
        } else if (status === 'consumed') {
            show('Diese QR-Anmeldung wurde bereits verwendet.', 'bad');
        } else {
            show('QR-Anmeldung beendet: ' + status, 'bad');
        }
    } catch (error) {
        stopPolling();
        createButton.disabled = false;
        show(error.message || String(error), 'bad');
    }
}

createButton.addEventListener('click', async () => {
    createButton.disabled = true;
    consuming = false;
    stopPolling();

    try {
        const response = await fetch('api_qr_login_create.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrf}),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.error || 'QR-Session konnte nicht erzeugt werden.');
        }

        token = result.session.token;
        qrImage.src = 'api_qr_login_code.php?token=' + encodeURIComponent(token);
        qrImage.hidden = false;
        show('Warte auf die Bestätigung am Smartphone ...');
        timer = setInterval(poll, 2000);
    } catch (error) {
        createButton.disabled = false;
        show(error.message || String(error), 'bad');
    }
});

window.addEventListener('beforeunload', stopPolling);
</script>
</body>
</html>
