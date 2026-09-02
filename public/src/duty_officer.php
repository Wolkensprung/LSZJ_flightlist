<?php
declare(strict_types=1);

require_once __DIR__ . '/permissions.php';

const DUTY_OFFICER_LOCK = 'lszj_active_duty_officer';
const DUTY_OFFICER_ALLOWED_PRIORITY_GROUPS = ['flying_member', 'student', 'gvvc'];

function duty_officer_user_is_eligible(int $userId): bool
{
    $sql = "SELECT 1
            FROM users u
            INNER JOIN pilots_master pm ON pm.id = u.pilot_master_id
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.id = :user_id
              AND u.active = 1
              AND pm.is_active = 1
              AND pm.is_selectable = 1
              AND pm.priority_group IN ('flying_member', 'student', 'gvvc')
              AND r.code IN ('DUTY_OFFICER', 'ADMIN')
              AND (ur.valid_from IS NULL OR ur.valid_from <= NOW())
              AND (ur.valid_until IS NULL OR ur.valid_until >= NOW())
            LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchColumn() !== false;
}

function duty_officer_assert_eligible(int $userId): void
{
    if (!duty_officer_user_is_eligible($userId)) {
        throw new RuntimeException('Flugdienstleiter können nur aktive fliegende Mitglieder, Flugschüler oder GVVC-Mitglieder mit entsprechender Rolle sein.');
    }
}

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
    $user = auth_require_login();
    duty_officer_assert_eligible((int)$user['id']);
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        if (duty_officer_active() !== null) {
            throw new RuntimeException('Es ist bereits ein Flugdienstleiter aktiv.');
        }
        $stmt = $pdo->prepare('INSERT INTO duty_officer_shifts (user_id, start_time) VALUES (:user_id, NOW())');
        $stmt->execute(['user_id' => (int)$user['id']]);
        return (int)$pdo->lastInsertId();
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_handover(int $newUserId, ?string $reason = null): int
{
    $current = auth_require_login();
    duty_officer_assert_eligible((int)$current['id']);
    duty_officer_assert_eligible($newUserId);
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active === null || (int)$active['user_id'] !== (int)$current['id']) {
            throw new RuntimeException('Nur der aktive Flugdienstleiter kann den Dienst übergeben.');
        }
        $pdo->beginTransaction();
        try {
            $close = $pdo->prepare(
                'UPDATE duty_officer_shifts
                 SET end_time = NOW(), ended_by = :ended_by,
                     handover_to = :new_user_id, handover_reason = :reason
                 WHERE id = :id AND end_time IS NULL'
            );
            $close->execute([
                'ended_by' => (int)$current['id'],
                'new_user_id' => $newUserId,
                'reason' => $reason,
                'id' => $active['id'],
            ]);
            if ($close->rowCount() !== 1) {
                throw new RuntimeException('Die Flugdienstleiter-Schicht wurde zwischenzeitlich geändert.');
            }
            $open = $pdo->prepare('INSERT INTO duty_officer_shifts (user_id, start_time) VALUES (:user_id, NOW())');
            $open->execute(['user_id' => $newUserId]);
            $newShiftId = (int)$pdo->lastInsertId();
            $pdo->commit();
            return $newShiftId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_end(?string $reason = null): void
{
    $current = auth_require_login();
    duty_officer_assert_eligible((int)$current['id']);
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active === null || (int)$active['user_id'] !== (int)$current['id']) {
            throw new RuntimeException('Nur der aktive Flugdienstleiter kann den Dienst beenden.');
        }
        $stmt = $pdo->prepare(
            'UPDATE duty_officer_shifts
             SET end_time = NOW(), ended_by = :ended_by, handover_reason = :reason
             WHERE id = :id AND end_time IS NULL'
        );
        $stmt->execute(['ended_by' => (int)$current['id'], 'reason' => $reason, 'id' => $active['id']]);
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_admin_end(string $reason): void
{
    $admin = auth_require_login();
    if (!has_role('ADMIN')) {
        throw new RuntimeException('Administratorrolle erforderlich.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Für die administrative Beendigung ist eine Begründung erforderlich.');
    }
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $active = duty_officer_active();
        if ($active === null) {
            throw new RuntimeException('Es ist kein Flugdienstleiter aktiv.');
        }
        $stmt = $pdo->prepare(
            "UPDATE duty_officer_shifts
             SET end_time = NOW(), ended_by = :ended_by,
                 handover_reason = CONCAT('Administrativ beendet: ', :reason)
             WHERE id = :id AND end_time IS NULL"
        );
        $stmt->execute([
            'ended_by' => (int)$admin['id'],
            'reason' => $reason,
            'id' => $active['id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Die Flugdienstleiter-Schicht wurde zwischenzeitlich geändert.');
        }
    } finally {
        duty_officer_release_lock($pdo);
    }
}

function duty_officer_close_stale_at_midnight(): int
{
    $pdo = db();
    duty_officer_acquire_lock($pdo);
    try {
        $stmt = $pdo->prepare(
            "UPDATE duty_officer_shifts
             SET end_time = CURDATE(), ended_by = NULL,
                 handover_reason = 'Automatisch beendet: Tageswechsel um Mitternacht'
             WHERE end_time IS NULL
               AND start_time < CURDATE()"
        );
        $stmt->execute();
        return $stmt->rowCount();
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
