<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = trim((string)($_GET['id'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    if ($id !== '') {
        $stmt = $pdo->prepare('SELECT id,last_name,first_name,name,email,phone,company_name,street,postal_code,city,country,notes FROM external_contacts WHERE id=? AND is_active=1');
        $stmt->execute([$id]);
        echo json_encode(['ok'=>true,'item'=>$stmt->fetch(PDO::FETCH_ASSOC) ?: null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare('SELECT id,last_name,first_name,name,email,phone FROM external_contacts WHERE is_active=1 AND (name LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR email LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 25');
        $stmt->execute([$like,$like,$like,$like,$like]);
        echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok'=>true,'items'=>[]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($data['action'] ?? '') !== 'create') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Ungültige Aktion']);
        exit;
    }

    $lastName = trim((string)($data['last_name'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if ($lastName === '' || $firstName === '' || $email === '' || $phone === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Nachname, Vorname, Mail und Telefon sind Pflichtfelder']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Ungültige Mailadresse']);
        exit;
    }

    $name = $lastName . ', ' . $firstName;
    $stmt = $pdo->prepare(
        'INSERT INTO external_contacts (last_name,first_name,name,email,phone,is_active)
         VALUES (?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           last_name=VALUES(last_name), first_name=VALUES(first_name), name=VALUES(name),
           phone=VALUES(phone), is_active=1, id=LAST_INSERT_ID(id)'
    );
    $stmt->execute([$lastName,$firstName,$name,$email,$phone]);

    echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'name'=>$name], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
