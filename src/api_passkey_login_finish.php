<?php
declare(strict_types=1);

require_once __DIR__ . '/passkey.php';
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
    $credential = $input['credential'] ?? null;
    if (!is_array($credential)) {
        json_response(['ok' => false, 'error' => 'Credential fehlt.'], 400);
    }
    $user = passkey_finish_login(
        json_encode($credential, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
    );
    json_response([
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'display_name' => (string)$user['display_name'],
        ],
        'redirect' => 'dashboard.php',
    ]);
} catch (Throwable $e) {
    error_log('Passkey login finish: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
