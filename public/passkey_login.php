<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

// Diese Seite ist absichtlich öffentlich und lädt nur auth.php.
// Eine bereits gültige Sitzung wird direkt zum Dashboard weitergeführt.
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
    <title>LSZJ Passkey-Login</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .login-wrap{max-width:560px;margin:40px auto}
        .status{padding:12px;border-radius:8px;margin-top:12px}
        .bad{background:#fff0f0;color:#970018}
        .hint{color:#667085}
    </style>
</head>
<body>
<div class="login-wrap">
    <h1>LSZJ Login</h1>
    <div class="card">
        <p>Melde dich mit Face ID, Fingerabdruck oder Geräte-PIN an.</p>
        <button id="login" type="button">Mit Passkey anmelden</button>
        <div id="status" class="status bad" hidden></div>
        <p class="hint">Der Passkey muss bereits für die LSZJ Startliste registriert sein.</p>
        <p><a class="button secondary" href="login.php">Zum vorläufigen Namenslogin</a></p>
    </div>
</div>
<script>
const csrf = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
const statusBox = document.getElementById('status');
const loginButton = document.getElementById('login');

function show(message) {
    statusBox.hidden = false;
    statusBox.textContent = message;
}
function b64urlToBytes(value) {
    const pad = '='.repeat((4 - value.length % 4) % 4);
    const raw = atob((value + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, c => c.charCodeAt(0));
}
function bytesToB64url(value) {
    const bytes = new Uint8Array(value);
    let raw = '';
    for (const byte of bytes) raw += String.fromCharCode(byte);
    return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}
function prepareRequestOptions(options) {
    options.challenge = b64urlToBytes(options.challenge);
    if (options.allowCredentials) {
        options.allowCredentials = options.allowCredentials.map(item => ({
            ...item,
            id: b64urlToBytes(item.id),
        }));
    }
    return options;
}
function credentialToJSON(credential) {
    return {
        id: credential.id,
        type: credential.type,
        rawId: bytesToB64url(credential.rawId),
        response: {
            clientDataJSON: bytesToB64url(credential.response.clientDataJSON),
            authenticatorData: bytesToB64url(credential.response.authenticatorData),
            signature: bytesToB64url(credential.response.signature),
            userHandle: credential.response.userHandle
                ? bytesToB64url(credential.response.userHandle)
                : null,
        },
        clientExtensionResults: credential.getClientExtensionResults(),
        authenticatorAttachment: credential.authenticatorAttachment || null,
    };
}

loginButton.addEventListener('click', async () => {
    statusBox.hidden = true;
    loginButton.disabled = true;
    try {
        if (!window.PublicKeyCredential) {
            throw new Error('Dieser Browser unterstützt Passkeys nicht.');
        }
        const request = await fetch('api_passkey_login_options.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrf}),
        });
        const options = await request.json();
        if (!request.ok || options.ok === false) {
            throw new Error(options.error || 'Login-Optionen konnten nicht geladen werden.');
        }
        const credential = await navigator.credentials.get({
            publicKey: prepareRequestOptions(options),
        });
        if (!credential) {
            throw new Error('Anmeldung wurde abgebrochen.');
        }
        const finish = await fetch('api_passkey_login_finish.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: csrf,
                credential: credentialToJSON(credential),
            }),
        });
        const result = await finish.json();
        if (!finish.ok || !result.ok) {
            throw new Error(result.error || 'Passkey-Anmeldung fehlgeschlagen.');
        }
        window.location.assign(result.redirect || 'dashboard.php');
    } catch (error) {
        show(error.message || String(error));
        loginButton.disabled = false;
    }
});
</script>
</body>
</html>
