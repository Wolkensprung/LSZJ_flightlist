<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
$q=trim((string)($_GET['q']??''));
$context=strtolower(trim((string)($_GET['context']??'')));
if($q===''){echo json_encode([],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$sectorFilter = match($context){
    'glider' => 'AND pm.can_fly_glider = 1',
    'motor' => 'AND pm.can_fly_motor = 1',
    default => '',
};
$loginFilter = $context==='login' ? 'AND u.id IS NOT NULL' : '';
$sql="SELECT * FROM (
 SELECT u.id user_id,pm.id source_id,'pilot' source_type,pm.display_name,pm.vf_user_no,pm.vf_member_no,pm.email,pm.priority_group,pm.membership_status,pm.sectors_json,NULL external_contact_id
 FROM pilots_master pm LEFT JOIN users u ON u.pilot_master_id=pm.id AND u.active=1
 WHERE pm.is_active=1 AND pm.is_selectable=1 {$sectorFilter} {$loginFilter}
 AND (pm.display_name LIKE :p1 OR pm.search_name LIKE :p2 OR pm.vf_user_no LIKE :p3 OR pm.vf_member_no LIKE :p4)
 UNION ALL
 SELECT u.id,ec.id,'external_contact',COALESCE(NULLIF(ec.name,''),CONCAT_WS(', ',NULLIF(ec.last_name,''),NULLIF(ec.first_name,''))),NULL,NULL,ec.email,'external_contact','Sonstiger','[]',ec.id
 FROM external_contacts ec LEFT JOIN users u ON u.external_contact_id=ec.id AND u.active=1
 WHERE ec.is_active=1 ".($context==='login'?'AND u.id IS NOT NULL ':'')."AND (ec.name LIKE :e1 OR ec.last_name LIKE :e2 OR ec.first_name LIKE :e3 OR ec.email LIKE :e4)
) r ORDER BY display_name LIMIT 30";
$like='%'.$q.'%';$s=db()->prepare($sql);$s->execute(['p1'=>$like,'p2'=>$like,'p3'=>$like,'p4'=>$like,'e1'=>$like,'e2'=>$like,'e3'=>$like,'e4'=>$like]);
echo json_encode(['ok'=>true,'items'=>$s->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
