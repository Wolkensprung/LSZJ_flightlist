<?php
declare(strict_types=1);

require_once __DIR__ . '/qr_login_consume.php';
require_once __DIR__ . '/helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_response(['ok' => false, 'error' => 'Ungültige JSON-Daten.'], 400);
    }

    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);

    $user = qr_login_consume((string)($input['token'] ?? ''));

    json_response([
        'ok' => true,
        'user' => $user,
        'redirect' => 'dashboard.php',
    ]);
} catch (Throwable $e) {
    error_log('QR login consume: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
