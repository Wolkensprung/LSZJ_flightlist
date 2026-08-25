<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function auth_load_roles(int $userId): array
{
    $sql = "SELECT r.code
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND (ur.valid_from IS NULL OR ur.valid_from <= NOW())
              AND (ur.valid_until IS NULL OR ur.valid_until >= NOW())
            ORDER BY r.code";
    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    return array_values(array_unique(array_column($stmt->fetchAll(), 'code')));
}

function auth_get_user_by_id(int $userId): ?array
{
    $sql = "SELECT u.id, u.pilot_master_id, u.external_contact_id,
                   u.display_name, u.active, u.last_login,
                   pm.vf_user_no, pm.vf_member_no,
                   COALESCE(pm.email, ec.email) AS email,
                   pm.cost_level, pm.priority_group
            FROM users u
            LEFT JOIN pilots_master pm ON pm.id = u.pilot_master_id
            LEFT JOIN external_contacts ec ON ec.id = u.external_contact_id
            WHERE u.id = :user_id AND u.active = 1
            LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}

function auth_login(int $userId, string $deviceType): array
{
    $deviceType = session_normalize_device_type($deviceType);
    $user = auth_get_user_by_id($userId);
    if ($user === null) {
        throw new RuntimeException('Benutzer ist nicht vorhanden oder inaktiv.');
    }

    session_start_if_needed();
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'user_id' => (int)$user['id'],
        'display_name' => (string)$user['display_name'],
        'roles' => auth_load_roles((int)$user['id']),
        'device_type' => $deviceType,
        'authenticated_at' => time(),
        'last_activity' => time(),
    ];

    $stmt = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    return auth_user() ?? $user;
}

function auth_logout(): void
{
    session_destroy_current();
}

function auth_user(): ?array
{
    session_start_if_needed();
    if (!session_auth_is_valid()) {
        return null;
    }
    $userId = (int)($_SESSION['auth']['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    $user = auth_get_user_by_id($userId);
    if ($user === null) {
        session_destroy_current();
        return null;
    }
    $user['roles'] = $_SESSION['auth']['roles'] ?? [];
    $user['device_type'] = $_SESSION['auth']['device_type'] ?? null;
    return $user;
}

function auth_require_login(): array
{
    $user = auth_user();
    if ($user === null) {
        http_response_code(401);
        exit('Anmeldung erforderlich.');
    }
    return $user;
}

function auth_refresh_roles(): array
{
    $user = auth_require_login();
    $roles = auth_load_roles((int)$user['id']);
    $_SESSION['auth']['roles'] = $roles;
    return $roles;
}

/*
 * Erstellt einen einmal verwendbaren QR-Token für einen bereits bekannten User.
 * In der DB wird nur SHA-256 des Tokens gespeichert.
 */
function auth_create_qr_token(int $userId, string $deviceType, int $ttlSeconds = 120): string
{
    $deviceType = session_normalize_device_type($deviceType);
    if ($ttlSeconds < 30 || $ttlSeconds > 900) {
        throw new InvalidArgumentException('QR-Gültigkeit muss zwischen 30 und 900 Sekunden liegen.');
    }
    if (auth_get_user_by_id($userId) === null) {
        throw new RuntimeException('Benutzer ist nicht vorhanden oder inaktiv.');
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $sql = "INSERT INTO qr_login_sessions
                (token, user_id, device_type, expires_at)
            VALUES
                (:token, :user_id, :device_type, DATE_ADD(NOW(), INTERVAL :ttl SECOND))";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':token', $tokenHash);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':device_type', $deviceType);
    $stmt->bindValue(':ttl', $ttlSeconds, PDO::PARAM_INT);
    $stmt->execute();
    return $rawToken;
}

function auth_login_by_qr_token(string $rawToken): array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        throw new RuntimeException('Ungültiger QR-Token.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tokenHash = hash('sha256', strtolower($rawToken));
        $sql = "SELECT id, user_id, device_type
                FROM qr_login_sessions
                WHERE token = :token
                  AND used_at IS NULL
                  AND expires_at >= NOW()
                LIMIT 1
                FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['token' => $tokenHash]);
        $tokenRow = $stmt->fetch();
        if ($tokenRow === false) {
            throw new RuntimeException('QR-Code ist ungültig, abgelaufen oder bereits verwendet.');
        }

        $update = $pdo->prepare(
            'UPDATE qr_login_sessions SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $update->execute(['id' => $tokenRow['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('QR-Code wurde bereits verwendet.');
        }
        $pdo->commit();
        return auth_login((int)$tokenRow['user_id'], (string)$tokenRow['device_type']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
