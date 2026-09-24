<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/recovery_mail.php';

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
  }

  $input=json_decode(file_get_contents('php://input'),true);
  if(!is_array($input)) $input=$_POST;

  $email=trim((string)($input['email'] ?? ''));
  if($email==='') {
    json_response(['ok'=>false,'error'=>'Mailadresse fehlt.'],400);
  }

  $user=recovery_find_user_by_email($email);

  if($user===null){
    json_response(['ok'=>true,'message'=>'Falls die Mailadresse bekannt ist, wurde ein Recovery-Token erstellt.']);
  }

/*  $token=recovery_create_token((int)$user['id']);

  json_response([
    'ok'=>true,
    'message'=>'Recovery-Token erstellt (Iteration 1, noch ohne Mailversand).',
    'user'=>[
      'id'=>(int)$user['id'],
      'display_name'=>(string)$user['display_name'],
    ],
    'token'=>$token['token']
  ]); */

  $token = recovery_create_token(
    (int)$user['id']
);

send_recovery_mail(
    (string)$user['email'],
    (string)$user['display_name'],
    (string)$token['token']
);

json_response([
    'ok' => true,
    'message' =>
        'Falls die Mailadresse bekannt ist, wurde ein Recovery-Link versendet.'
]);

} catch(Throwable $e){
  error_log('Recovery request: '.$e->getMessage());
  json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}
