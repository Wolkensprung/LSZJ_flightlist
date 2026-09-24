<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Prüft und verbraucht einen Recovery-Token atomar.
 *
 * Sicherheitsregeln:
 * - nur 64-stellige Hex-Tokens
 * - in der DB liegt ausschliesslich SHA-256 des Tokens
 * - Token muss unbenutzt und nicht abgelaufen sein
 * - nur aktive users mit aktivem pilots_master-Eintrag
 * - external_contacts sind ausdrücklich ausgeschlossen
 * - nach erfolgreicher Verwendung werden weitere offene Tokens des Users entwertet
 *
 * @return array{id:int,display_name:string,email:string}
 */
function recovery_consume_token(string $rawToken): array
{
    $rawToken = strtolower(trim($rawToken));

    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        throw new RuntimeException('Recovery-Link ist ungültig oder unvollständig.');
    }

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

        // Weitere noch offene Recovery-Links für denselben Benutzer entwerten.
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
