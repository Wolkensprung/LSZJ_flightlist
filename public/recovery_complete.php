<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/recovery_complete.php';

$error = null;
$user = null;
$completed = false;
$rawToken = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));

if ($rawToken === '') {
    $error = 'Recovery-Link ist ungültig oder unvollständig.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_require_valid($_POST['csrf_token'] ?? null);
        $user = recovery_consume_token($rawToken);
        auth_login((int)$user['id'], SESSION_DEVICE_SMARTPHONE);
        $completed = true;
    } catch (Throwable $e) {
        error_log('Recovery complete POST: ' . $e->getMessage());
        $error = $e->getMessage();
    }
} else {
    try {
        // GET prüft nur. Der Token wird erst nach dem Button-Klick per POST verbraucht.
        $user = recovery_validate_token($rawToken);
    } catch (Throwable $e) {
        error_log('Recovery complete GET: ' . $e->getMessage());
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
        :root{--blue:#1769aa;--blue-dark:#0f568e;--green:#17823b;--red:#b00020;--text:#1f2937;--muted:#667085;--border:#d9e0e8;--bg:#f4f6f8}
        body{margin:0;background:var(--bg);color:var(--text)}
        .recovery-shell{width:min(100% - 32px,680px);margin:48px auto}
        .recovery-card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.08);padding:clamp(24px,5vw,38px)}
        .recovery-kicker{margin:0 0 8px;color:var(--blue);font-size:.85rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        h1{margin:0 0 16px;line-height:1.2}
        .result-panel{padding:18px 20px;border-radius:9px;border-left:5px solid;line-height:1.55}
        .result-success{border-left-color:var(--green);background:#eefaf1}
        .result-error{border-left-color:var(--red);background:#fff0f0;color:#970018}
        .result-title{display:block;margin-bottom:6px;font-size:1.06rem}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}
        .primary-button,.primary-link,.secondary-link{display:inline-flex;min-height:44px;box-sizing:border-box;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font:inherit;font-weight:700;text-decoration:none}
        .primary-button,.primary-link{border:0;background:var(--blue);color:#fff;cursor:pointer}
        .primary-button:hover,.primary-link:hover{background:var(--blue-dark)}
        .secondary-link{border:1px solid #9aa6b2;background:#fff;color:#344054}
        .hint{color:var(--muted);line-height:1.5}
        @media(max-width:520px){.recovery-shell{width:min(100% - 20px,680px);margin:16px auto}.recovery-card{padding:22px 18px}.primary-button,.primary-link,.secondary-link{width:100%}}
    </style>
</head>
<body>
<main class="recovery-shell">
    <section class="recovery-card" aria-labelledby="recovery-title">
        <p class="recovery-kicker">LSZJ Startliste</p>
        <h1 id="recovery-title">Passkey-Recovery</h1>

        <?php if ($error !== null): ?>
            <div class="result-panel result-error" role="alert">
                <strong class="result-title">Recovery nicht möglich</strong>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p class="hint">Fordere einen neuen Recovery-Link an. Ein Link kann nur einmal verwendet werden und verliert nach Ablauf seine Gültigkeit.</p>
            <div class="actions">
                <a class="primary-link" href="recovery_request.php">Neuen Recovery-Link anfordern</a>
                <a class="secondary-link" href="passkey_login.php">Zur Anmeldung</a>
            </div>

        <?php elseif ($completed): ?>
            <div class="result-panel result-success" role="status">
                <strong class="result-title">Recovery bestätigt</strong>
                <span>Du bist angemeldet als <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?>.</span>
            </div>
            <p class="hint">Registriere jetzt auf diesem persönlichen Gerät einen neuen Passkey.</p>
            <div class="actions">
                <a class="primary-link" href="passkeys.php">Neuen Passkey registrieren</a>
            </div>

        <?php else: ?>
            <div class="result-panel result-success" role="status">
                <strong class="result-title">Recovery-Link gültig</strong>
                <span>Der Link wurde geprüft. Bestätige jetzt die Wiederherstellung für <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?>.</span>
            </div>
            <p class="hint">Erst beim Klick auf den Button wird der einmalige Link verbraucht und eine Sitzung zur Passkey-Registrierung erstellt.</p>
            <form method="post" class="actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8') ?>">
                <button class="primary-button" type="submit">Recovery bestätigen</button>
                <a class="secondary-link" href="passkey_login.php">Abbrechen</a>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
