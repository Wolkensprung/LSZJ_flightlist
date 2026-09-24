<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/recovery_complete.php';

$error = null;
$user = null;
$rawToken = trim((string)($_GET['token'] ?? ''));

if ($rawToken === '') {
    $error = 'Recovery-Link ist ungültig oder unvollständig.';
} else {
    try {
        $user = recovery_consume_token($rawToken);

        // Der Besitz des einmaligen Recovery-Links ist der Identitätsnachweis.
        // Die Sitzung ermöglicht anschliessend die Registrierung eines neuen Passkeys.
        auth_login((int)$user['id'], SESSION_DEVICE_SMARTPHONE);
    } catch (Throwable $e) {
        error_log('Recovery complete: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ Passkey-Recovery</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .recovery-wrap{max-width:700px;margin:40px auto}
        .notice{padding:14px;border-left:5px solid #17823b;background:#eefaf1;border-radius:6px}
        .error{padding:14px;border-left:5px solid #b00020;background:#fff0f0;color:#970018;border-radius:6px}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    </style>
</head>
<body>
<div class="recovery-wrap">
    <h1>Passkey-Recovery</h1>

    <?php if ($error !== null): ?>
        <div class="card error">
            <strong>Recovery nicht möglich.</strong>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="actions">
                <a class="button secondary" href="recovery_request.php">Neuen Recovery-Link anfordern</a>
                <a class="button secondary" href="passkey_login.php">Zur Anmeldung</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card notice">
            <strong>Recovery-Link bestätigt.</strong>
            <p>
                Angemeldet als
                <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?>.
            </p>
            <p>Registriere jetzt auf diesem persönlichen Gerät einen neuen Passkey.</p>
            <div class="actions">
                <a class="button ok" href="passkeys.php">Neuen Passkey registrieren</a>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
