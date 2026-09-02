<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const USER_ADMIN_ROLE_CODES = ['PILOT', 'DUTY_OFFICER', 'ADMIN'];
const USER_ADMIN_FLYING_GROUPS = ['flying_member', 'student', 'gvvc'];

function user_admin_get_user(int $userId, bool $forUpdate = false): ?array
{
    $sql = "SELECT u.id, u.pilot_master_id, u.external_contact_id, u.display_name,
                   u.active, u.last_login, u.created_at, u.updated_at,
                   pm.vf_user_no, pm.vf_member_no, pm.email AS member_email,
                   pm.mobile, pm.cost_level, pm.priority_group,
                   pm.is_active AS member_active, pm.is_selectable,
                   ec.email AS external_email, ec.phone AS external_phone,
                   ec.is_active AS external_active
            FROM users u
            LEFT JOIN pilots_master pm ON pm.id = u.pilot_master_id
            LEFT JOIN external_contacts ec ON ec.id = u.external_contact_id
            WHERE u.id = :user_id
            LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt=db()->prepare($sql);
    $stmt->execute(['user_id'=>$userId]);
    $row=$stmt->fetch();
    return $row===false?null:$row;
}

function user_admin_get_roles(int $userId): array
{
    $stmt=db()->prepare(
        "SELECT r.code, r.name, ur.valid_from, ur.valid_until, IF(ur.id IS NULL,0,1) AS assigned
         FROM roles r
         LEFT JOIN user_roles ur ON ur.role_id=r.id AND ur.user_id=:user_id
         WHERE r.code IN ('PILOT','DUTY_OFFICER','ADMIN')
         ORDER BY FIELD(r.code,'PILOT','DUTY_OFFICER','ADMIN')"
    );
    $stmt->execute(['user_id'=>$userId]);
    return $stmt->fetchAll();
}

function user_admin_detail(int $userId): ?array
{
    $user=user_admin_get_user($userId);
    if($user===null)return null;
    $user['email']=$user['member_email'] ?: $user['external_email'];
    $user['phone']=$user['mobile'] ?: $user['external_phone'];
    $user['source']=$user['pilot_master_id']!==null?'vereinsflieger':'external_contact';
    $user['roles']=user_admin_get_roles($userId);
    return $user;
}

function user_admin_search(string $query, int $limit=25): array
{
    $query=trim($query);
    if($query==='')return [];
    $limit=max(1,min(50,$limit));
    $like='%'.$query.'%';
    $sql="SELECT u.id, u.display_name, u.active,
                 CASE WHEN u.pilot_master_id IS NOT NULL THEN 'vereinsflieger' ELSE 'external_contact' END AS source,
                 pm.vf_user_no, pm.vf_member_no, pm.cost_level, pm.priority_group,
                 COALESCE(pm.email,ec.email) AS email
          FROM users u
          LEFT JOIN pilots_master pm ON pm.id=u.pilot_master_id
          LEFT JOIN external_contacts ec ON ec.id=u.external_contact_id
          WHERE u.display_name LIKE :q1
             OR pm.vf_user_no LIKE :q2
             OR pm.vf_member_no LIKE :q3
             OR pm.email LIKE :q4
             OR ec.email LIKE :q5
          ORDER BY u.active DESC, u.display_name
          LIMIT {$limit}";
    $stmt=db()->prepare($sql);
    $stmt->execute(['q1'=>$like,'q2'=>$like,'q3'=>$like,'q4'=>$like,'q5'=>$like]);
    return $stmt->fetchAll();
}

function user_admin_normalize_datetime(mixed $value): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    $dt=DateTimeImmutable::createFromFormat('Y-m-d\\TH:i',$value)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$value);
    if(!$dt)throw new RuntimeException('Ungültiges Datum oder ungültige Uhrzeit.');
    return $dt->format('Y-m-d H:i:s');
}

function user_admin_role_allowed(array $user,string $roleCode): bool
{
    if($roleCode==='ADMIN')return true;
    if($roleCode==='DUTY_OFFICER'){
        return $user['pilot_master_id']!==null
            && (int)$user['member_active']===1
            && (int)$user['is_selectable']===1
            && in_array((string)$user['priority_group'],USER_ADMIN_FLYING_GROUPS,true);
    }
    if($roleCode==='PILOT'){
        $memberAllowed=$user['pilot_master_id']!==null
            && (int)$user['member_active']===1
            && (int)$user['is_selectable']===1
            && in_array((string)$user['priority_group'],USER_ADMIN_FLYING_GROUPS,true);
        $externalAllowed=$user['external_contact_id']!==null && (int)$user['external_active']===1;
        return $memberAllowed||$externalAllowed;
    }
    return false;
}

function user_admin_count_active_admins(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(DISTINCT u.id)
         FROM users u
         JOIN user_roles ur ON ur.user_id=u.id
         JOIN roles r ON r.id=ur.role_id
         WHERE u.active=1 AND r.code='ADMIN'
           AND (ur.valid_from IS NULL OR ur.valid_from<=NOW())
           AND (ur.valid_until IS NULL OR ur.valid_until>=NOW())"
    )->fetchColumn();
}

function user_admin_has_current_admin_role(PDO $pdo,int $userId): bool
{
    $stmt=$pdo->prepare(
        "SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=:user_id AND r.code='ADMIN'
           AND (ur.valid_from IS NULL OR ur.valid_from<=NOW())
           AND (ur.valid_until IS NULL OR ur.valid_until>=NOW()) LIMIT 1"
    );
    $stmt->execute(['user_id'=>$userId]);
    return $stmt->fetchColumn()!==false;
}

function user_admin_save(int $userId,bool $active,array $requestedRoles): array
{
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $user=user_admin_get_user($userId,true);
        if($user===null)throw new RuntimeException('Benutzer wurde nicht gefunden.');

        $roles=[];
        foreach($requestedRoles as $item){
            if(!is_array($item))continue;
            $code=strtoupper(trim((string)($item['code']??'')));
            if(!in_array($code,USER_ADMIN_ROLE_CODES,true))continue;
            $enabled=filter_var($item['enabled']??false,FILTER_VALIDATE_BOOLEAN);
            $from=user_admin_normalize_datetime($item['valid_from']??null);
            $until=user_admin_normalize_datetime($item['valid_until']??null);
            if($from!==null&&$until!==null&&$from>$until){
                throw new RuntimeException("Gültig von liegt bei {$code} nach Gültig bis.");
            }
            if($enabled&&!user_admin_role_allowed($user,$code)){
                throw new RuntimeException("Die Rolle {$code} ist für diese Kostenstufe beziehungsweise Benutzerquelle nicht zulässig.");
            }
            $roles[$code]=['enabled'=>$enabled,'valid_from'=>$from,'valid_until'=>$until];
        }
        foreach(USER_ADMIN_ROLE_CODES as $code){
            $roles[$code]??=['enabled'=>false,'valid_from'=>null,'valid_until'=>null];
        }

        $isCurrentAdmin=user_admin_has_current_admin_role($pdo,$userId);
        $adminSettings=$roles['ADMIN'];
        $now=date('Y-m-d H:i:s');
        $keepsAdmin=$adminSettings['enabled']
            && ($adminSettings['valid_from']===null || $adminSettings['valid_from']<=$now)
            && ($adminSettings['valid_until']===null || $adminSettings['valid_until']>=$now);
        $adminCount=user_admin_count_active_admins($pdo);
        if($isCurrentAdmin&&$adminCount<=1&&(!$active||!$keepsAdmin)){
            throw new RuntimeException('Der letzte aktive Administrator kann nicht deaktiviert oder seiner Administratorrolle beraubt werden.');
        }

        $stmt=$pdo->prepare('UPDATE users SET active=:active WHERE id=:user_id');
        $stmt->execute(['active'=>$active?1:0,'user_id'=>$userId]);

        $roleIdStmt=$pdo->prepare('SELECT id FROM roles WHERE code=:code LIMIT 1');
        $upsert=$pdo->prepare(
            'INSERT INTO user_roles(user_id,role_id,valid_from,valid_until)
             VALUES(:user_id,:role_id,:valid_from,:valid_until)
             ON DUPLICATE KEY UPDATE valid_from=VALUES(valid_from),valid_until=VALUES(valid_until)'
        );
        $delete=$pdo->prepare('DELETE FROM user_roles WHERE user_id=:user_id AND role_id=:role_id');
        foreach($roles as $code=>$settings){
            $roleIdStmt->execute(['code'=>$code]);
            $roleId=$roleIdStmt->fetchColumn();
            if($roleId===false)throw new RuntimeException("Rolle {$code} fehlt in der Datenbank.");
            if($settings['enabled']){
                $upsert->execute(['user_id'=>$userId,'role_id'=>$roleId,'valid_from'=>$settings['valid_from'],'valid_until'=>$settings['valid_until']]);
            }else{
                $delete->execute(['user_id'=>$userId,'role_id'=>$roleId]);
            }
        }
        $pdo->commit();
        return user_admin_detail($userId)??[];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
