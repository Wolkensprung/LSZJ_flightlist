<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Cache-Control: no-store');

try {
    $pdo = db();
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $id = trim((string)($_GET['id'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));

        if ($id !== '') {
            $stmt = $pdo->prepare(
                'SELECT id,last_name,first_name,name,email,phone,company_name,street,postal_code,city,country,notes
                 FROM external_contacts
                 WHERE id=? AND is_active=1'
            );
            $stmt->execute([$id]);
            json_response([
                'ok' => true,
                'item' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null,
            ]);
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare(
                'SELECT id,last_name,first_name,name,email,phone
                 FROM external_contacts
                 WHERE is_active=1
                   AND (name LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR email LIKE ? OR phone LIKE ?)
                 ORDER BY name
                 LIMIT 25'
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            json_response([
                'ok' => true,
                'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        json_response(['ok' => true, 'items' => []]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        json_response(['ok' => false, 'error' => 'Leere Anfrage.'], 400);
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        json_response(['ok' => false, 'error' => 'Ungültige JSON-Daten.'], 400);
    }

    if (!is_array($data) || array_is_list($data)) {
        json_response(['ok' => false, 'error' => 'JSON-Objekt erwartet.'], 400);
    }

    if (($data['action'] ?? '') !== 'create') {
        json_response(['ok' => false, 'error' => 'Ungültige Aktion.'], 400);
    }

    $lastName = trim((string)($data['last_name'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if ($lastName === '' || $firstName === '' || $email === '' || $phone === '') {
        json_response([
            'ok' => false,
            'error' => 'Nachname, Vorname, Mailadresse und Telefon sind Pflichtfelder.',
        ], 400);
    }

    // Praxisnahe Syntaxpruefung passend zum Client: genau ein @, Domain mit Punkt, keine Whitespaces.
    if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/D', $email)) {
        json_response(['ok' => false, 'error' => 'Ungültige Mailadresse.'], 400);
    }

    $name = $lastName . ', ' . $firstName;
    $stmt = $pdo->prepare(
        'INSERT INTO external_contacts
            (last_name,first_name,name,email,phone,is_active)
         VALUES (?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
            last_name=VALUES(last_name),
            first_name=VALUES(first_name),
            name=VALUES(name),
            phone=VALUES(phone),
            is_active=1,
            vf_exported_at=NULL,
            vf_linked_at=NULL,
            vf_user_no=NULL,
            id=LAST_INSERT_ID(id)'
    );
    $stmt->execute([$lastName, $firstName, $name, $email, $phone]);

    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) {
        throw new RuntimeException('Kontakt wurde gespeichert, aber die Kontakt-ID fehlt.');
    }

    json_response([
        'ok' => true,
        'id' => $id,
        'name' => $name,
    ], 201);
} catch (PDOException $error) {
    error_log('api_external_contacts database error: ' . $error->getMessage());

    $message = 'Datenbankfehler beim Speichern des externen Kontakts.';
    if (
        str_contains($error->getMessage(), 'Unknown column')
        && (
            str_contains($error->getMessage(), 'vf_exported_at')
            || str_contains($error->getMessage(), 'vf_linked_at')
            || str_contains($error->getMessage(), 'vf_user_no')
        )
    ) {
        $message = 'Datenbankmigration für externe Kontakte fehlt.';
    }

    json_response(['ok' => false, 'error' => $message], 500);
} catch (Throwable $error) {
    error_log('api_external_contacts error: ' . $error->getMessage());
    json_response([
        'ok' => false,
        'error' => 'Externer Kontakt konnte nicht gespeichert werden.',
    ], 500);
}
