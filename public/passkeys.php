<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

$user = auth_user();
if ($user === null) {
    header('Location: passkey_login.php?reason=expired');
    exit;
}

$stmt = db()->prepare(
    'SELECT id, device_name, created_at, last_used_at
     FROM user_passkeys
     WHERE user_id = :user_id
       AND revoked_at IS NULL
     ORDER BY created_at DESC'
);
$stmt->execute(['user_id' => (int)$user['id']]);
$passkeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activeCount = count($passkeys);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ Passkeys</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .passkey-wrap{max-width:780px;margin:32px auto;padding:0 12px}
        .passkey-list{list-style:none;padding:0;margin:0;display:grid;gap:12px}
        .passkey-item{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:16px;border:1px solid #d9e0e8;border-radius:10px;background:#fff}
        .passkey-title{font-weight:800;margin-bottom:4px}
        .passkey-meta{color:#667085;font-size:.92rem;line-height:1.45}
        .danger-button{border:1px solid #b00020;background:#fff;color:#970018;border-radius:8px;padding:9px 12px;font:inherit;font-weight:700;cursor:pointer;white-space:nowrap}
        .danger-button:hover{background:#fff0f0}
        .danger-button:disabled{cursor:not-allowed;opacity:.48}
        .status{padding:12px;border-radius:8px;margin-top:12px;line-height:1.5}
        .good{background:#eefaf1;border-left:5px solid #17823b}
        .bad{background:#fff0f0;color:#970018;border-left:5px solid #b00020}
        .hint{color:#667085;line-height:1.5}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
        @media(max-width:560px){.passkey-item{align-items:stretch;flex-direction:column}.danger-button{width:100%}}
    </style>
</head>
<body>
<main class="passkey-wrap">
    <h1>Passkeys</h1>

    <section class="card">
        <strong><?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <p>Registriere dieses persönliche Gerät für die Anmeldung mit Face ID, Fingerabdruck oder Geräte-PIN.</p>
        <label for="device-name">Gerätename</label>
        <input id="device-name" maxlength="255" value="Persönliches Gerät">
        <div class="actions">
            <button id="register" type="button">Passkey registrieren</button>
            <a class="button secondary" href="dashboard.php">Zurück zum Dashboard</a>
        </div>
        <div id="status" class="status" role="status" aria-live="polite" hidden></div>
    </section>

    <section class="card">
        <h2>Aktive Passkeys</h2>
        <?php if ($passkeys === []): ?>
            <p>Noch keine Passkeys registriert.</p>
        <?php else: ?>
            <ul class="passkey-list">
                <?php foreach ($passkeys as $passkey): ?>
                    <li class="passkey-item" data-passkey-id="<?= (int)$passkey['id'] ?>">
                        <div>
                            <div class="passkey-title">
                                <?= htmlspecialchars((string)$passkey['device_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="passkey-meta">
                                Erstellt: <?= htmlspecialchars((string)$passkey['created_at'], ENT_QUOTES, 'UTF-8') ?><br>
                                Zuletzt verwendet:
                                <?= $passkey['last_used_at'] === null
                                    ? 'noch nie'
                                    : htmlspecialchars((string)$passkey['last_used_at'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <button
                            class="danger-button revoke-passkey"
                            type="button"
                            <?= $activeCount <= 1 ? 'disabled title="Der letzte aktive Passkey kann nicht widerrufen werden."' : '' ?>
                        >Widerrufen</button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($activeCount <= 1): ?>
                <p class="hint">Registriere zuerst auf einem anderen Gerät einen zweiten Passkey, bevor Du den letzten aktiven Passkey widerrufst.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<script>
const csrf = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
const statusBox = document.getElementById('status');
const registerButton = document.getElementById('register');

function show(message, ok = false) {
    statusBox.hidden = false;
    statusBox.className = 'status ' + (ok ? 'good' : 'bad');
    statusBox.textContent = message;
}

function friendlyWebAuthnRegistrationError(error) {
    const name = String(error?.name || '');
    const message = String(error?.message || error || '');
    const normalized = (name + ' ' + message).toLowerCase();

    if (
        name === 'InvalidStateError' ||
        normalized.includes('not, or is no longer, usable') ||
        normalized.includes('already registered') ||
        normalized.includes('already exists')
    ) {
        return 'Auf diesem Gerät existiert bereits ein Passkey für die LSZJ Startliste. Verwende für einen zweiten Passkey ein anderes Gerät oder ein anderes Geräteprofil.';
    }

    if (
        name === 'NotAllowedError' ||
        normalized.includes('notallowederror') ||
        normalized.includes('timed out') ||
        normalized.includes('timeout') ||
        normalized.includes('operation was cancelled') ||
        normalized.includes('operation was canceled')
    ) {
        return 'Die Passkey-Registrierung wurde abgebrochen oder ist abgelaufen. Bitte starte die Registrierung erneut.';
    }

    if (name === 'NotSupportedError' || normalized.includes('notsupportederror')) {
        return 'Dieses Gerät oder dieser Browser unterstützt die benötigte Passkey-Funktion nicht.';
    }

    if (name === 'SecurityError' || normalized.includes('securityerror')) {
        return 'Die Passkey-Registrierung wurde aus Sicherheitsgründen abgelehnt. Prüfe, ob die Seite über die korrekte HTTPS-Adresse geöffnet wurde.';
    }

    return message || 'Passkey konnte nicht registriert werden.';
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

function prepareCreationOptions(options) {
    options.challenge = b64urlToBytes(options.challenge);
    options.user.id = b64urlToBytes(options.user.id);
    if (options.excludeCredentials) {
        options.excludeCredentials = options.excludeCredentials.map(item => ({
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
            attestationObject: bytesToB64url(credential.response.attestationObject),
        },
        clientExtensionResults: credential.getClientExtensionResults(),
        authenticatorAttachment: credential.authenticatorAttachment || null,
    };
}

registerButton.addEventListener('click', async () => {
    statusBox.hidden = true;
    registerButton.disabled = true;

    try {
        if (!window.PublicKeyCredential) {
            throw new DOMException('Passkeys werden nicht unterstützt.', 'NotSupportedError');
        }

        const request = await fetch('api_passkey_register_options.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf_token: csrf}),
        });
        const options = await request.json();
        if (!request.ok || options.ok === false) {
            throw new Error(options.error || 'Optionen konnten nicht geladen werden.');
        }

        const credential = await navigator.credentials.create({
            publicKey: prepareCreationOptions(options),
        });
        if (!credential) {
            throw new DOMException('Registrierung wurde abgebrochen.', 'NotAllowedError');
        }

        const finish = await fetch('api_passkey_register_finish.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: csrf,
                device_name: document.getElementById('device-name').value,
                credential: credentialToJSON(credential),
            }),
        });
        const result = await finish.json();
        if (!finish.ok || !result.ok) {
            throw new Error(result.error || 'Passkey konnte nicht gespeichert werden.');
        }

        show(result.message, true);
        setTimeout(() => location.reload(), 700);
    } catch (error) {
        console.error('WebAuthn registration failed:', error);
        show(friendlyWebAuthnRegistrationError(error));
    } finally {
        registerButton.disabled = false;
    }
});

document.querySelectorAll('.revoke-passkey').forEach(button => {
    button.addEventListener('click', async () => {
        const item = button.closest('[data-passkey-id]');
        const passkeyId = Number(item?.dataset.passkeyId || 0);
        if (!passkeyId) return;

        if (!window.confirm('Diesen Passkey wirklich widerrufen? Danach kann er nicht mehr zur Anmeldung verwendet werden.')) {
            return;
        }

        button.disabled = true;
        try {
            const response = await fetch('api_passkey_revoke.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({csrf_token: csrf, passkey_id: passkeyId}),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Passkey konnte nicht widerrufen werden.');
            }
            show(result.message, true);
            setTimeout(() => location.reload(), 500);
        } catch (error) {
            show(error.message || String(error));
            button.disabled = false;
        }
    });
});
</script>
</body>
</html>
