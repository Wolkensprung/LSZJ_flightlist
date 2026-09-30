<?php
declare(strict_types=1);

require_once __DIR__ . '/qr_login.php';
require_once __DIR__ . '/auth.php';

/**
 * Übernimmt eine freigegebene QR-Session genau einmal in die aktuelle
 * Browser-Session des C-Büro-PCs.
 *
 * @return array{id:int,display_name:string}
 */
function qr_login_consume(string $rawToken): array
{
    qr_login_assert_schema();
    $tokenHash = qr_login_token_hash($rawToken);
    $pdo = db();
    $pdo->beginTransaction();
    $loginStarted = false;

    try {
        $stmt = $pdo->prepare(
            "SELECT q.id,
                    q.user_id,
                    q.status,
                    q.expires_at,
                    q.approved_at,
                    q.consumed_at,
                    u.active
             FROM qr_login_sessions q
             INNER JOIN users u ON u.id = q.user_id
             WHERE q.token_hash = :token_hash
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('QR-Login-Session ist unbekannt.');
        }
        if ((string)$row['status'] !== 'approved') {
            throw new RuntimeException('QR-Login-Session ist nicht zur Übernahme freigegeben.');
        }
        if ($row['consumed_at'] !== null) {
            throw new RuntimeException('QR-Login-Session wurde bereits verwendet.');
        }
        if ($row['approved_at'] === null) {
            throw new RuntimeException('QR-Login-Session hat keine gültige Freigabe.');
        }
        if ((int)$row['active'] !== 1) {
            throw new RuntimeException('Benutzer ist nicht vorhanden oder inaktiv.');
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            throw new RuntimeException('QR-Login-Session ist abgelaufen.');
        }

        $user = auth_login((int)$row['user_id'], SESSION_DEVICE_C_BUERO);
        $loginStarted = true;

        $update = $pdo->prepare(
            "UPDATE qr_login_sessions
             SET status = 'consumed',
                 consumed_at = NOW()
             WHERE id = :id
               AND status = 'approved'
               AND consumed_at IS NULL"
        );
        $update->execute(['id' => (int)$row['id']]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException('QR-Login-Session konnte nicht übernommen werden.');
        }

        $pdo->commit();

        return [
            'id' => (int)$user['id'],
            'display_name' => (string)$user['display_name'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Verhindert eine angemeldete Browser-Session, falls die atomare
        // DB-Übernahme nach auth_login() doch noch fehlschlägt.
        if ($loginStarted) {
            auth_logout();
        }

        throw $e;
    }
}
