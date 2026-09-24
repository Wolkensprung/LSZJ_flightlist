<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function recovery_find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        "SELECT u.id,u.display_name,pm.email
         FROM users u
         INNER JOIN pilots_master pm ON pm.id=u.pilot_master_id
         WHERE u.active=1
           AND pm.is_active=1
           AND LOWER(pm.email)=LOWER(:email)
         LIMIT 1"
    );
    $stmt->execute(['email'=>$email]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}

function recovery_create_token(int $userId): array
{
    $raw=bin2hex(random_bytes(32));
    $hash=hash('sha256',$raw);

    $stmt=db()->prepare(
        'INSERT INTO user_recovery_tokens (user_id,token_hash,expires_at) VALUES (:user_id,:token_hash,DATE_ADD(NOW(),INTERVAL 1 DAY))'
    );
    $stmt->execute([
      'user_id'=>$userId,
      'token_hash'=>$hash,
    ]);

    return [
      'token'=>$raw,
      'token_hash'=>$hash,
      'id'=>(int)db()->lastInsertId(),
    ];
}
