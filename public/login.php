<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/session.php';

$error = null;
$notice = null;

/**
 * Erlaubt nur bekannte lokale Ziele und nur freigegebene Query-Parameter.
 * Dadurch sind externe Redirects und Pfadmanipulationen ausgeschlossen.
 */
function login_safe_return_target(?string $requested): string
{
    $default = 'dashboard.php';
    $requested = trim((string)$requested);
    if ($requested === '') {
        return $default;
    }

    $parts = parse_url($requested);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
        return $default;
    }

    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path === '' || basename($path) !== $path) {
        return $default;
    }

    $allowed = [
        'dashboard.php' => ['from', 'to', 'date', 'status'],
        'flight_approvals.php' => ['from', 'to', 'date', 'status', 'user'],
        'manual_flight.php' => ['date', 'from', 'status', 'user', 'mode', 'operation_id'],
        'flight_correction.php' => ['operation_id', 'date', 'status', 'user'],
        'master_data_import.php' => [],
        'duty_officer.php' => [],
        'user_admin.php' => [],
    ];

    if (!array_key_exists($path, $allowed)) {
        return $default;
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $rawQuery);
    foreach ($allowed[$path] as $key) {
        if (isset($rawQuery[$key]) && is_scalar($rawQuery[$key])) {
            $value = trim((string)$rawQuery[$key]);
            if ($value !== '' && strlen($value) <= 255) {
                $query[$key] = $value;
            }
        }
    }

    return $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

function login_resolve_user_id(string $sourceType, int $sourceId): int
{
    if ($sourceId <= 0) {
        throw new RuntimeException('Bitte einen Benutzer aus der Trefferliste auswählen.');
    }

    $pdo = db();

    if ($sourceType === 'pilot') {
        $sql = "SELECT u.id
                FROM users u
                INNER JOIN pilots_master pm ON pm.id = u.pilot_master_id
                WHERE pm.id = :source_id
                  AND u.active = 1
                  AND pm.is_active = 1
                  AND pm.is_selectable = 1
                  AND pm.priority_group IN ('flying_member', 'student', 'gvvc')
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['source_id' => $sourceId]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new RuntimeException('Dieser Pilot ist für den Login nicht zugelassen.');
        }
        return (int)$userId;
    }

    if ($sourceType === 'external_contact') {
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN external_contacts ec ON ec.id = u.external_contact_id
                 WHERE ec.id = :source_id AND ec.is_active = 1 AND u.active = 1
                 LIMIT 1 FOR UPDATE"
            );
            $select->execute(['source_id' => $sourceId]);
            $userId = $select->fetchColumn();

            if ($userId === false) {
                $contact = $pdo->prepare(
                    "SELECT id,
                            COALESCE(NULLIF(name, ''), CONCAT_WS(', ', NULLIF(last_name, ''), NULLIF(first_name, ''))) AS display_name
                     FROM external_contacts
                     WHERE id = :source_id AND is_active = 1
                     LIMIT 1"
                );
                $contact->execute(['source_id' => $sourceId]);
                $row = $contact->fetch();
                if ($row === false) {
                    throw new RuntimeException('Externer Kontakt wurde nicht gefunden oder ist inaktiv.');
                }

                $insert = $pdo->prepare(
                    'INSERT INTO users (external_contact_id, display_name, active)
                     VALUES (:external_contact_id, :display_name, 1)'
                );
                $insert->execute([
                    'external_contact_id' => $sourceId,
                    'display_name' => $row['display_name'],
                ]);
                $userId = (int)$pdo->lastInsertId();

                $pilotRole = $pdo->prepare(
                    "INSERT IGNORE INTO user_roles (user_id, role_id)
                     SELECT :user_id, id FROM roles WHERE code = 'PILOT'"
                );
                $pilotRole->execute(['user_id' => $userId]);
            }

            $pdo->commit();
            return (int)$userId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    throw new RuntimeException('Ungültige Benutzerquelle.');
}

$returnTarget = login_safe_return_target($_POST['return'] ?? $_GET['return'] ?? null);
$currentUser = auth_user();
$showLoginForm = $currentUser === null || (string)($_GET['switch'] ?? '') === '1';

if ((string)($_GET['reason'] ?? '') === 'expired') {
    $notice = 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_require_valid($_POST['csrf_token'] ?? null);
        $action = trim((string)($_POST['action'] ?? 'login'));

        if ($action === 'switch') {
            auth_logout();
            $location = 'login.php?switch=1';
            if ($returnTarget !== 'dashboard.php') {
                $location .= '&return=' . rawurlencode($returnTarget);
            }
            header('Location: ' . $location);
            exit;
        }

        if ($action !== 'login') {
            throw new RuntimeException('Unbekannte Aktion.');
        }

        $sourceType = trim((string)($_POST['source_type'] ?? ''));
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $userId = login_resolve_user_id($sourceType, $sourceId);
        auth_login($userId, 'C_BUERO');
        header('Location: ' . $returnTarget);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $currentUser = auth_user();
        $showLoginForm = true;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ Login</title>
    <link rel="stylesheet" href="app.css">
    <link rel="stylesheet" href="master_data_autocomplete.css">
    <style>
      .login-wrap{max-width:720px;margin:0 auto}.login-notice{padding:12px;border-left:5px solid #b7791f;background:#fff8e6;border-radius:6px}.login-error{padding:12px;border-left:5px solid #b00020;background:#fff0f0;color:#970018;border-radius:6px}.login-current{padding:14px;border-left:5px solid #17823b;background:#eefaf1;border-radius:6px}.login-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.hint{color:#667085}
    </style>
</head>
<body>
<div class="login-wrap">
<h1>LSZJ Login</h1>

<?php if ($notice !== null): ?>
  <p class="login-notice"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
  <p class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($currentUser !== null && !$showLoginForm): ?>
<div class="card login-current">
  <strong>Du bist angemeldet als <?= htmlspecialchars((string)$currentUser['display_name'], ENT_QUOTES, 'UTF-8') ?>.</strong>
  <div class="hint">Rollen: <?= htmlspecialchars(implode(', ', $currentUser['roles'] ?? []), ENT_QUOTES, 'UTF-8') ?></div>
  <div class="login-actions">
    <a class="button ok" href="<?= htmlspecialchars($returnTarget, ENT_QUOTES, 'UTF-8') ?>">Weiter</a>
    <a class="button secondary" href="dashboard.php">Zum Dashboard</a>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="switch">
      <input type="hidden" name="return" value="<?= htmlspecialchars($returnTarget, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit">Benutzer wechseln</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card">
  <form method="post" id="login-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="return" value="<?= htmlspecialchars($returnTarget, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="source_type" id="source_type">
    <input type="hidden" name="source_id" id="source_id">

    <label for="pilot">Benutzer</label>
    <input id="pilot" type="text" data-autocomplete-context="login" autocomplete="off" placeholder="Nachname oder Vorname eingeben" required>

    <p class="hint">Zugelassen: Fliegendes Mitglied, Flugschüler, GVVC-Mitglied und aktive externe Kontakte.</p>
    <button type="submit">Anmelden</button>
  </form>
</div>
<?php endif; ?>
</div>

<?php if ($showLoginForm): ?>
<script src="master_data_autocomplete.js"></script>
<script>
(() => {
    'use strict';
    const input = document.getElementById('pilot');
    const form = document.getElementById('login-form');
    const sourceType = document.getElementById('source_type');
    const sourceId = document.getElementById('source_id');
    if (!input || !form) return;

    input.addEventListener('change', () => {
        sourceType.value = input.dataset.sourceType || '';
        sourceId.value = input.dataset.sourceId || '';
    });
    input.addEventListener('input', () => {
        sourceType.value = '';
        sourceId.value = '';
    });
    form.addEventListener('submit', event => {
        sourceType.value = input.dataset.sourceType || '';
        sourceId.value = input.dataset.sourceId || '';
        if (!sourceType.value || !sourceId.value) {
            event.preventDefault();
            alert('Bitte einen Benutzer aus der Trefferliste auswählen.');
            input.focus();
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
