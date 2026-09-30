<?php
declare(strict_types=1);
require_once __DIR__ . '/qr_login.php';
require_once __DIR__ . '/auth.php';

function qr_login_get_pending_session(string $rawToken, bool $forUpdate=false): array
{
    qr_login_assert_schema();
    $hash=qr_login_token_hash($rawToken);
    $sql='SELECT id,status,user_id,expires_at FROM qr_login_sessions WHERE token_hash=:token_hash LIMIT 1';
    if($forUpdate){$sql.=' FOR UPDATE';}
    $stmt=db()->prepare($sql);$stmt->execute(['token_hash'=>$hash]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if($row===false)throw new RuntimeException('QR-Login-Session ist unbekannt.');
    if((string)$row['status']!=='pending')throw new RuntimeException('QR-Login-Session ist nicht mehr offen.');
    if(strtotime((string)$row['expires_at'])<time())throw new RuntimeException('QR-Login-Session ist abgelaufen.');
    return $row;
}

function qr_login_approve(string $rawToken,int $userId): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $row=qr_login_get_pending_session($rawToken,true);
        $stmt=$pdo->prepare("UPDATE qr_login_sessions SET user_id=:user_id,status='approved',approved_at=NOW() WHERE id=:id AND status='pending'");
        $stmt->execute(['user_id'=>$userId,'id'=>(int)$row['id']]);
        if($stmt->rowCount()!==1)throw new RuntimeException('QR-Login konnte nicht bestätigt werden.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
