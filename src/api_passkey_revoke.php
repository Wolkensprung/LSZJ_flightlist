<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }

    $user = auth_require_login();
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        json_response(['ok' => false, 'error' => 'Ungültige JSON-Daten.'], 400);
    }

    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);

    $passkeyId = filter_var(
        $input['passkey_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($passkeyId === false) {
        json_response(['ok' => false, 'error' => 'Ungültiger Passkey.'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $owned = $pdo->prepare(
            'SELECT id
             FROM user_passkeys
             WHERE id = :id
               AND user_id = :user_id
               AND revoked_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $owned->execute([
            'id' => (int)$passkeyId,
            'user_id' => (int)$user['id'],
        ]);

        if ($owned->fetchColumn() === false) {
            throw new RuntimeException(
                'Passkey wurde nicht gefunden oder ist bereits widerrufen.'
            );
        }

        $count = $pdo->prepare(
            'SELECT COUNT(*)
             FROM user_passkeys
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        );
        $count->execute(['user_id' => (int)$user['id']]);

        if ((int)$count->fetchColumn() <= 1) {
            throw new RuntimeException(
                'Der letzte aktive Passkey kann nicht widerrufen werden. Registriere zuerst auf einem anderen Gerät einen neuen Passkey.'
            );
        }

        $update = $pdo->prepare(
            'UPDATE user_passkeys
             SET revoked_at = NOW()
             WHERE id = :id
               AND user_id = :user_id
               AND revoked_at IS NULL'
        );
        $update->execute([
            'id' => (int)$passkeyId,
            'user_id' => (int)$user['id'],
        ]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Passkey konnte nicht widerrufen werden.');
        }

        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Passkey wurde widerrufen.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Passkey revoke: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
