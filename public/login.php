<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/session.php';

$error = null;

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
                 LIMIT 1
                 FOR UPDATE"
            );
            $select->execute(['source_id' => $sourceId]);
            $userId = $select->fetchColumn();

            if ($userId === false) {
                $contact = $pdo->prepare(
                    "SELECT id,
                            COALESCE(NULLIF(name, ''),
                                     CONCAT_WS(', ', NULLIF(last_name, ''), NULLIF(first_name, '')))
                               AS display_name
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
                    "INSERT INTO users (external_contact_id, display_name, active)
                     VALUES (:external_contact_id, :display_name, 1)"
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_require_valid($_POST['csrf_token'] ?? null);
        $sourceType = trim((string)($_POST['source_type'] ?? ''));
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $userId = login_resolve_user_id($sourceType, $sourceId);
        auth_login($userId, 'C_BUERO');
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
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
</head>
<body>
<h1>LSZJ Login</h1>

<div class="card">
    <?php if ($error !== null): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" id="login-form">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="source_type" id="source_type">
        <input type="hidden" name="source_id" id="source_id">

        <label for="pilot">Benutzer</label>
        <input id="pilot"
               type="text"
               data-autocomplete-context="login"
               autocomplete="off"
               placeholder="Nachname oder Vorname eingeben"
               required>

        <p class="hint">
            Zugelassen: Fliegendes Mitglied, Flugschüler, GVVC-Mitglied und aktive externe Kontakte.
        </p>

        <button type="submit">Anmelden</button>
    </form>
</div>

<script src="master_data_autocomplete.js"></script>
<script>
(() => {
    'use strict';
    const input = document.getElementById('pilot');
    const form = document.getElementById('login-form');
    const sourceType = document.getElementById('source_type');
    const sourceId = document.getElementById('source_id');

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
</body>
</html>
