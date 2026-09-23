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
        $input = $_POST;
    }
    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);
    $options = passkey_login_options();
    passkey_store_login_challenge($options);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo passkey_json_serialize($options);
} catch (Throwable $e) {
    error_log('Passkey login options: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
