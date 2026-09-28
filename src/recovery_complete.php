<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const RECOVERY_SESSION_MAX_AGE_SECONDS = 900;

function recovery_normalize_token(string $rawToken): string
{
    $rawToken = strtolower(trim($rawToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        throw new RuntimeException('Recovery-Link ist ungültig oder unvollständig.');
    }
    return $rawToken;
}

function recovery_validate_token(string $rawToken): array
{
    $tokenHash = hash('sha256', recovery_normalize_token($rawToken));
    $stmt = db()->prepare(
        "SELECT rt.user_id, u.display_name, pm.email
         FROM user_recovery_tokens rt
         INNER JOIN users u ON u.id=rt.user_id AND u.active=1
         INNER JOIN pilots_master pm ON pm.id=u.pilot_master_id AND pm.is_active=1
         WHERE rt.token_hash=:token_hash AND rt.used_at IS NULL AND rt.expires_at>=NOW()
         LIMIT 1"
    );
    $stmt->execute(['token_hash'=>$tokenHash]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if ($row===false) {
        throw new RuntimeException('Recovery-Link ist ungültig, abgelaufen oder wurde bereits verwendet.');
    }
    return ['id'=>(int)$row['user_id'],'display_name'=>(string)$row['display_name'],'email'=>(string)$row['email']];
}

function recovery_consume_token(string $rawToken): array
{
    $tokenHash=hash('sha256',recovery_normalize_token($rawToken));
    $pdo=db();
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare(
            "SELECT rt.id AS token_id,rt.user_id,u.display_name,pm.email
             FROM user_recovery_tokens rt
             INNER JOIN users u ON u.id=rt.user_id AND u.active=1
             INNER JOIN pilots_master pm ON pm.id=u.pilot_master_id AND pm.is_active=1
             WHERE rt.token_hash=:token_hash AND rt.used_at IS NULL AND rt.expires_at>=NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['token_hash'=>$tokenHash]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($row===false) {
            throw new RuntimeException('Recovery-Link ist ungültig, abgelaufen oder wurde bereits verwendet.');
        }
        $consume=$pdo->prepare('UPDATE user_recovery_tokens SET used_at=NOW() WHERE id=:id AND used_at IS NULL');
        $consume->execute(['id'=>(int)$row['token_id']]);
        if ($consume->rowCount()!==1) throw new RuntimeException('Recovery-Link wurde bereits verwendet.');
        $invalidate=$pdo->prepare('UPDATE user_recovery_tokens SET used_at=NOW() WHERE user_id=:user_id AND used_at IS NULL');
        $invalidate->execute(['user_id'=>(int)$row['user_id']]);
        $pdo->commit();
        return ['id'=>(int)$row['user_id'],'display_name'=>(string)$row['display_name'],'email'=>(string)$row['email']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function recovery_start_passkey_replacement(int $userId,bool $revokeExisting): void
{
    session_start_if_needed();
    $_SESSION['passkey_recovery']=[
        'user_id'=>$userId,
        'revoke_existing'=>$revokeExisting,
        'confirmed_at'=>time(),
    ];
}

function recovery_finalize_after_passkey_registration(int $newPasskeyId): int
{
    session_start_if_needed();
    $marker=$_SESSION['passkey_recovery']??null;
    if (!is_array($marker)) return 0;

    $confirmedAt=(int)($marker['confirmed_at']??0);
    if ($confirmedAt<=0 || time()-$confirmedAt>RECOVERY_SESSION_MAX_AGE_SECONDS) {
        unset($_SESSION['passkey_recovery']);
        return 0;
    }

    $user=auth_require_login();
    $userId=(int)$user['id'];
    if ((int)($marker['user_id']??0)!==$userId) {
        unset($_SESSION['passkey_recovery']);
        throw new RuntimeException('Recovery-Sitzung gehört zu einem anderen Benutzer.');
    }
    if (($marker['revoke_existing']??false)!==true) {
        unset($_SESSION['passkey_recovery']);
        return 0;
    }

    $pdo=db();
    $pdo->beginTransaction();
    try {
        $newKey=$pdo->prepare(
            'SELECT id FROM user_passkeys
             WHERE id=:id AND user_id=:user_id AND revoked_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $newKey->execute(['id'=>$newPasskeyId,'user_id'=>$userId]);
        if ($newKey->fetchColumn()===false) {
            throw new RuntimeException('Der neu registrierte Passkey konnte nicht bestätigt werden.');
        }
        $revoke=$pdo->prepare(
            'UPDATE user_passkeys SET revoked_at=NOW()
             WHERE user_id=:user_id AND id<>:new_passkey_id AND revoked_at IS NULL'
        );
        $revoke->execute(['user_id'=>$userId,'new_passkey_id'=>$newPasskeyId]);
        $count=$revoke->rowCount();
        $pdo->commit();
        unset($_SESSION['passkey_recovery']);
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
