<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/MasterData/CallsignNormalizer.php';

use LSZJ\MasterData\CallsignNormalizer;

header('Content-Type: application/json; charset=utf-8');
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode([]);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT callsign, competition_code, model_designation, aircraft_type, is_club_aircraft
     FROM aircraft_master
     WHERE is_active = 1
       AND (callsign LIKE ? OR competition_code LIKE ? OR model_designation LIKE ? OR search_key LIKE ?)
     ORDER BY is_club_aircraft DESC, callsign
     LIMIT 30"
);
$like = '%' . $q . '%';
$stmt->execute([$like, $like, $like, '%' . CallsignNormalizer::searchKey($q) . '%']);

echo json_encode(['ok'=>true, 'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
