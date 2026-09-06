<?php
declare(strict_types=1);
require_once __DIR__ . '/passkey.php';
require_once __DIR__ . '/helpers.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
    auth_require_login();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) json_response(['ok'=>false,'error'=>'Ungültige JSON-Daten.'],400);
    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);
    $credential = $input['credential'] ?? null;
    if (!is_array($credential)) json_response(['ok'=>false,'error'=>'Credential fehlt.'],400);
    $id = passkey_finish_registration(
        json_encode($credential, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        (string)($input['device_name'] ?? '')
    );
    json_response(['ok'=>true,'id'=>$id,'message'=>'Passkey wurde registriert.']);
} catch (Throwable $e) {
    error_log('Passkey registration finish: '.$e->getMessage());

json_response([
    'ok' => false,
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
],422);
    
    json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
