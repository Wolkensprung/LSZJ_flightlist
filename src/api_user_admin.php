<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/user_admin.php';

header('Content-Type: application/json; charset=utf-8');

function user_admin_json(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$user=auth_user();
if($user===null)user_admin_json(['ok'=>false,'error'=>'Anmeldung erforderlich.'],401);
if(!has_role('ADMIN'))user_admin_json(['ok'=>false,'error'=>'Administratorrolle erforderlich.'],403);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'search');
        if($action==='search'){
            user_admin_json(['ok'=>true,'items'=>user_admin_search((string)($_GET['q']??''))]);
        }
        if($action==='get'){
            $id=(int)($_GET['id']??0);
            $detail=user_admin_detail($id);
            if($detail===null)user_admin_json(['ok'=>false,'error'=>'Benutzer nicht gefunden.'],404);
            user_admin_json(['ok'=>true,'user'=>$detail]);
        }
        user_admin_json(['ok'=>false,'error'=>'Unbekannte Aktion.'],400);
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input))user_admin_json(['ok'=>false,'error'=>'Ungültige JSON-Daten.'],400);
        if(!csrf_validate(isset($input['csrf_token'])?(string)$input['csrf_token']:null)){
            user_admin_json(['ok'=>false,'error'=>'Ungültige oder abgelaufene Anfrage.'],419);
        }
        if(($input['action']??'')!=='save')user_admin_json(['ok'=>false,'error'=>'Unbekannte Aktion.'],400);
        $saved=user_admin_save((int)($input['user_id']??0),(bool)($input['active']??false),(array)($input['roles']??[]));
        user_admin_json(['ok'=>true,'user'=>$saved,'message'=>'Benutzer und Rollen wurden gespeichert.']);
    }

    user_admin_json(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
}catch(Throwable $e){
    user_admin_json(['ok'=>false,'error'=>$e->getMessage()],400);
}
