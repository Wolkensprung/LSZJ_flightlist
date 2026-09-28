<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

const QR_LOGIN_DEFAULT_TTL_SECONDS = 120;
const QR_LOGIN_MIN_TTL_SECONDS = 30;
const QR_LOGIN_MAX_TTL_SECONDS = 600;

function qr_login_config(): array
{
    $config = app_config();
    $qr = $config['qr_login'] ?? [];

    if (!is_array($qr)) {
        throw new RuntimeException('QR-Login-Konfiguration ist ungültig.');
    }

    $baseUrl = rtrim((string)($qr['base_url'] ?? ''), '/');
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('QR-Login base_url fehlt oder ist ungültig.');
    }

    $ttl = (int)($qr['ttl_seconds'] ?? QR_LOGIN_DEFAULT_TTL_SECONDS);
    if ($ttl < QR_LOGIN_MIN_TTL_SECONDS || $ttl > QR_LOGIN_MAX_TTL_SECONDS) {
        throw new RuntimeException('QR-Login ttl_seconds muss zwischen 30 und 600 Sekunden liegen.');
    }

    return [
        'base_url' => $baseUrl,
        'ttl_seconds' => $ttl,
    ];
}

function qr_login_assert_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $required = [
        'id', 'token_hash', 'user_id', 'device_type', 'status',
        'created_at', 'expires_at', 'approved_at', 'consumed_at',
    ];

    $stmt = db()->query('SHOW COLUMNS FROM qr_login_sessions');
    $actual = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $missing = array_values(array_diff($required, $actual));

    if ($missing !== []) {
        throw new RuntimeException(
            'qr_login_sessions hat noch nicht das erwartete V2-Schema. Fehlende Spalten: '
            . implode(', ', $missing)
        );
    }

    $checked = true;
}

function qr_login_new_token(): string
{
    return bin2hex(random_bytes(32));
}

function qr_login_token_hash(string $rawToken): string
{
    $rawToken = strtolower(trim($rawToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        throw new RuntimeException('Ungültiger QR-Login-Token.');
    }
    return hash('sha256', $rawToken);
}

/**
 * @return array{id:int,token:string,approve_url:string,status:string,expires_at:string,ttl_seconds:int}
 */
function qr_login_create_session(): array
{
    qr_login_assert_schema();
    $config = qr_login_config();
    $rawToken = qr_login_new_token();
    $tokenHash = qr_login_token_hash($rawToken);
    $ttl = (int)$config['ttl_seconds'];

    $stmt = db()->prepare(
        "INSERT INTO qr_login_sessions
            (token_hash, user_id, device_type, status, expires_at)
         VALUES
            (:token_hash, NULL, 'C_BUERO', 'pending', DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
    );
    $stmt->bindValue(':token_hash', $tokenHash);
    $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
    $stmt->execute();

    $id = (int)db()->lastInsertId();

    $read = db()->prepare(
        'SELECT status, expires_at FROM qr_login_sessions WHERE id = :id LIMIT 1'
    );
    $read->execute(['id' => $id]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('QR-Login-Session konnte nicht gelesen werden.');
    }

    return [
        'id' => $id,
        'token' => $rawToken,
        'approve_url' => $config['base_url'] . '/qr_approve.php?token=' . rawurlencode($rawToken),
        'status' => (string)$row['status'],
        'expires_at' => (string)$row['expires_at'],
        'ttl_seconds' => $ttl,
    ];
}

/**
 * @return array{status:string,expires_at:string,approved_at:?string,consumed_at:?string}
 */
function qr_login_get_status(string $rawToken): array
{
    qr_login_assert_schema();
    $tokenHash = qr_login_token_hash($rawToken);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, status, expires_at, approved_at, consumed_at
             FROM qr_login_sessions
             WHERE token_hash = :token_hash
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('QR-Login-Session ist unbekannt.');
        }

        if (
            (string)$row['status'] === 'pending'
            && strtotime((string)$row['expires_at']) < time()
        ) {
            $expire = $pdo->prepare(
                "UPDATE qr_login_sessions
                 SET status = 'expired'
                 WHERE id = :id AND status = 'pending'"
            );
            $expire->execute(['id' => (int)$row['id']]);
            $row['status'] = 'expired';
        }

        $pdo->commit();

        return [
            'status' => (string)$row['status'],
            'expires_at' => (string)$row['expires_at'],
            'approved_at' => $row['approved_at'] === null ? null : (string)$row['approved_at'],
            'consumed_at' => $row['consumed_at'] === null ? null : (string)$row['consumed_at'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
