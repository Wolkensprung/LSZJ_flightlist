<?php
declare(strict_types=1);

require_once __DIR__ . '/passkey.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/recovery_complete.php';

try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
    auth_require_login();
    $input=json_decode(file_get_contents('php://input'),true);
    if (!is_array($input)) json_response(['ok'=>false,'error'=>'Ungültige JSON-Daten.'],400);
    csrf_require_valid(isset($input['csrf_token'])?(string)$input['csrf_token']:null);
    $credential=$input['credential']??null;
    if (!is_array($credential)) json_response(['ok'=>false,'error'=>'Credential fehlt.'],400);

    $newPasskeyId=passkey_finish_registration(
        json_encode($credential,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        (string)($input['device_name']??'')
    );
    $revokedCount=recovery_finalize_after_passkey_registration($newPasskeyId);
    $message='Passkey wurde registriert.';
    if ($revokedCount>0) {
        $message.=' '.$revokedCount.' bisherige Passkey'.($revokedCount===1?' wurde':'s wurden').' widerrufen.';
    }
    json_response(['ok'=>true,'id'=>$newPasskeyId,'revoked_count'=>$revokedCount,'message'=>$message]);
} catch (Throwable $e) {
    error_log('Passkey registration finish: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
