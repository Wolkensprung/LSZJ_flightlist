<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/MasterData/TextNormalizer.php';

use LSZJ\MasterData\TextNormalizer;
header('Content-Type: application/json; charset=utf-8');
$q = trim((string)($_GET['q'] ?? ''));
$all = filter_var($_GET['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
$pdo = db();
$sql = "SELECT vf_user_no,vf_member_no,display_name,email,mobile,priority_group,is_primary
        FROM pilots_master
        WHERE is_active=1 AND is_selectable=1";
if (!$all) $sql .= " AND is_primary=1";
$sql .= " AND (display_name LIKE ? OR search_name LIKE ? OR vf_user_no LIKE ?)
          ORDER BY is_primary DESC, FIELD(priority_group,'flying_member','student','gvvc','other'), display_name
          LIMIT 30";
$stmt=$pdo->prepare($sql);
$stmt->execute(['%'.$q.'%','%'.TextNormalizer::searchKey($q).'%','%'.$q.'%']);
echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
