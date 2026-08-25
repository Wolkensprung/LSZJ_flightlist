<?php
declare(strict_types=1);

require_once __DIR__ . '/permissions.php';

const DUTY_OFFICER_LOCK = 'lszj_active_duty_officer';

function duty_officer_active(): ?array
{
    $sql = "SELECT s.id, s.user_id, s.start_time, u.display_name
            FROM duty_officer_shifts s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.end_time IS NULL
            ORDER BY s.start_time DESC
            LIMIT 1";
    $row = db()->query($sql)->fetch();
    return $row === false ? null : $row;
}

function duty_officer_acquire_lock(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $stmt->execute(['lock_name' => DUTY_OFFICER_LOCK]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Flugdienstleiter-Aktion ist momentan gesperrt.');
    }
}

function duty_officer_release_lock(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $stmt->execute(['lock_name' => DUTY_OFFICER_LOCK]);
}

function duty_officer_start(): int
{
    require_role('DUTY_OFFICER');
    $user = auth_require_login();
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active !== null) {
            throw new RuntimeException('Es ist bereits ein Flugdienstleiter aktiv.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO duty_officer_shifts (user_id, start_time) VALUES (:user_id, NOW())'
        );
        $stmt->execute(['user_id' => (int)$user['id']]);
        return (int)$pdo->lastInsertId();
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_handover(int $newUserId, ?string $reason = null): int
{
    require_role('DUTY_OFFICER');
    $current = auth_require_login();
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active === null || (int)$active['user_id'] !== (int)$current['id']) {
            throw new RuntimeException('Nur der aktive Flugdienstleiter kann den Dienst übergeben.');
        }
        $newRoles = auth_load_roles($newUserId);
        if (!in_array('DUTY_OFFICER', $newRoles, true) && !in_array('ADMIN', $newRoles, true)) {
            throw new RuntimeException('Der Zielbenutzer hat keine Flugdienstleiter-Berechtigung.');
        }

        $pdo->beginTransaction();
        try {
            $close = $pdo->prepare(
                'UPDATE duty_officer_shifts
                 SET end_time = NOW(), handover_to = :new_user_id, handover_reason = :reason
                 WHERE id = :id AND end_time IS NULL'
            );
            $close->execute([
                'new_user_id' => $newUserId,
                'reason' => $reason,
                'id' => $active['id'],
            ]);
            if ($close->rowCount() !== 1) {
                throw new RuntimeException('Die Flugdienstleiter-Schicht wurde zwischenzeitlich geändert.');
            }
            $open = $pdo->prepare(
                'INSERT INTO duty_officer_shifts (user_id, start_time) VALUES (:user_id, NOW())'
            );
            $open->execute(['user_id' => $newUserId]);
            $newShiftId = (int)$pdo->lastInsertId();
            $pdo->commit();
            return $newShiftId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_end(?string $reason = null): void
{
    require_role('DUTY_OFFICER');
    $current = auth_require_login();
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active === null || (int)$active['user_id'] !== (int)$current['id']) {
            throw new RuntimeException('Nur der aktive Flugdienstleiter kann den Dienst beenden.');
        }
        $stmt = $pdo->prepare(
            'UPDATE duty_officer_shifts
             SET end_time = NOW(), handover_reason = :reason
             WHERE id = :id AND end_time IS NULL'
        );
        $stmt->execute(['reason' => $reason, 'id' => $active['id']]);
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_is_current_user(): bool
{
    $user = auth_user();
    $active = duty_officer_active();
    return $user !== null && $active !== null && (int)$user['id'] === (int)$active['user_id'];
}
