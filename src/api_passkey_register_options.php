<?php
declare(strict_types=1);
require_once __DIR__ . '/passkey.php';
require_once __DIR__ . '/helpers.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
    $user = auth_require_login();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);
    $options = passkey_creation_options($user);
    passkey_store_creation_challenge($options, (int)$user['id']);
    header('Content-Type: application/json; charset=utf-8');
    echo passkey_json_serialize($options);
} catch (Throwable $e) {
    error_log('Passkey registration options: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
