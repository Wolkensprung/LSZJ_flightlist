<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $actor=api_authenticated_actor(['ADMIN']);
    $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $stmt=$pdo->query("SELECT id,last_name,first_name,name,email,phone,is_active,vf_exported_at,vf_linked_at,vf_user_no,CASE WHEN vf_linked_at IS NOT NULL THEN 'linked' WHEN vf_exported_at IS NOT NULL THEN 'downloaded' ELSE 'pending' END AS vf_status FROM external_contacts WHERE is_active=1 ORDER BY CASE WHEN vf_linked_at IS NOT NULL THEN 3 WHEN vf_exported_at IS NOT NULL THEN 2 ELSE 1 END,last_name,first_name,id");
        json_response(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
    csrf_require_valid(isset($input['csrf_token'])?(string)$input['csrf_token']:null);
    $id=(int)($input['id']??0);$action=(string)($input['action']??'');
    if($id<=0)json_response(['ok'=>false,'error'=>'Kontakt-ID fehlt.'],400);
    if($action==='mark_linked'){
        $vfUserNo=trim((string)($input['vf_user_no']??''));
        if($vfUserNo==='')json_response(['ok'=>false,'error'=>'VF-Benutzernummer fehlt.'],400);
        $stmt=$pdo->prepare("UPDATE external_contacts SET vf_user_no=?,vf_linked_at=NOW(),vf_exported_at=COALESCE(vf_exported_at,NOW()) WHERE id=? AND is_active=1");$stmt->execute([$vfUserNo,$id]);
    }elseif($action==='reset_pending'){
        $stmt=$pdo->prepare("UPDATE external_contacts SET vf_exported_at=NULL,vf_linked_at=NULL,vf_user_no=NULL WHERE id=? AND is_active=1");$stmt->execute([$id]);
    }else json_response(['ok'=>false,'error'=>'Unbekannte Aktion.'],400);
    if($stmt->rowCount()!==1)json_response(['ok'=>false,'error'=>'Kontakt nicht gefunden oder unverändert.'],404);
    json_response(['ok'=>true,'id'=>$id,'action'=>$action,'actor'=>$actor['display_name']]);
}catch(PDOException $e){
    $m=$e->getMessage();
    if(str_contains($m,'Unknown column')&&(str_contains($m,'vf_linked_at')||str_contains($m,'vf_user_no')))json_response(['ok'=>false,'error'=>'Datenbankmigration für Paket A fehlt. Bitte database/20260829_package_a_external_contacts.sql ausführen.'],500);
    error_log('VF external contacts database error: '.$m);json_response(['ok'=>false,'error'=>'Datenbankfehler beim Laden der VF-Kontakte.'],500);
}catch(Throwable $e){error_log('VF external contacts error: '.$e->getMessage());json_response(['ok'=>false,'error'=>'VF-Kontakte konnten nicht verarbeitet werden.'],500);}
