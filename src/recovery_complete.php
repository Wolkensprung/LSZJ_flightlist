<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Normalisiert und validiert das Tokenformat.
 */
function recovery_normalize_token(string $rawToken): string
{
    $rawToken = strtolower(trim($rawToken));

    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        throw new RuntimeException('Recovery-Link ist ungültig oder unvollständig.');
    }

    return $rawToken;
}

/**
 * Prüft einen Recovery-Token, ohne ihn zu verbrauchen.
 *
 * Das ist für den GET-Aufruf wichtig: Mailprogramme und Sicherheitsdienste
 * können Links automatisch aufrufen. Ein blosses Öffnen darf den Token daher
 * nicht als verwendet markieren.
 *
 * @return array{id:int,display_name:string,email:string}
 */
function recovery_validate_token(string $rawToken): array
{
    $rawToken = recovery_normalize_token($rawToken);
    $tokenHash = hash('sha256', $rawToken);

    $stmt = db()->prepare(
        "SELECT rt.user_id,
                u.display_name,
                pm.email
         FROM user_recovery_tokens rt
         INNER JOIN users u
                 ON u.id = rt.user_id
                AND u.active = 1
         INNER JOIN pilots_master pm
                 ON pm.id = u.pilot_master_id
                AND pm.is_active = 1
         WHERE rt.token_hash = :token_hash
           AND rt.used_at IS NULL
           AND rt.expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException(
            'Recovery-Link ist ungültig, abgelaufen oder wurde bereits verwendet.'
        );
    }

    return [
        'id' => (int)$row['user_id'],
        'display_name' => (string)$row['display_name'],
        'email' => (string)$row['email'],
    ];
}

/**
 * Verbraucht einen Recovery-Token atomar.
 *
 * Diese Funktion darf nur nach einer ausdrücklichen POST-Bestätigung des
 * Benutzers aufgerufen werden. Externe Kontakte bleiben durch den zwingenden
 * INNER JOIN auf pilots_master ausgeschlossen.
 *
 * @return array{id:int,display_name:string,email:string}
 */
function recovery_consume_token(string $rawToken): array
{
    $rawToken = recovery_normalize_token($rawToken);
    $tokenHash = hash('sha256', $rawToken);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT rt.id AS token_id,
                    rt.user_id,
                    u.display_name,
                    pm.email
             FROM user_recovery_tokens rt
             INNER JOIN users u
                     ON u.id = rt.user_id
                    AND u.active = 1
             INNER JOIN pilots_master pm
                     ON pm.id = u.pilot_master_id
                    AND pm.is_active = 1
             WHERE rt.token_hash = :token_hash
               AND rt.used_at IS NULL
               AND rt.expires_at >= NOW()
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Recovery-Link ist ungültig, abgelaufen oder wurde bereits verwendet.'
            );
        }

        $consume = $pdo->prepare(
            'UPDATE user_recovery_tokens
             SET used_at = NOW()
             WHERE id = :id
               AND used_at IS NULL'
        );
        $consume->execute(['id' => (int)$row['token_id']]);

        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Recovery-Link wurde bereits verwendet.');
        }

        // Weitere offene Recovery-Links desselben Benutzers entwerten.
        $invalidate = $pdo->prepare(
            'UPDATE user_recovery_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $invalidate->execute(['user_id' => (int)$row['user_id']]);

        $pdo->commit();

        return [
            'id' => (int)$row['user_id'],
            'display_name' => (string)$row['display_name'],
            'email' => (string)$row['email'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
