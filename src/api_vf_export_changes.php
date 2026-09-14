<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/api_authenticated_actor.php';
foreach (['FlightPayloadBuilder','FlightPersonResolver','FlightPayloadComparator','FlightSpecialCaseGuard','FlightExportChangeService'] as $class) {
    require_once __DIR__.'/Vereinsflieger/'.$class.'.php';
}
use LSZJ\Vereinsflieger\{FlightExportChangeService,FlightPayloadComparator,FlightPersonResolver,FlightSpecialCaseGuard};
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
    $actor=api_authenticated_actor(['ADMIN']);
    $input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_is_list($input)) json_response(['ok'=>false,'error'=>'JSON-Objekt erwartet.'],400);
    csrf_require_valid(isset($input['csrf_token'])?(string)$input['csrf_token']:null);
    $pdo=db();
    $service=new FlightExportChangeService($pdo,new FlightPersonResolver($pdo),new FlightPayloadComparator(),new FlightSpecialCaseGuard());
    $action=(string)($input['action']??'list');
    if ($action==='list') {
        $items=$service->listCandidates((string)($input['from']??date('Y-m-01')),(string)($input['to']??date('Y-m-d')),(string)($input['status']??'all'),($input['changed_only']??false)===true,(int)($input['limit']??200));
        json_response(['ok'=>true,'items'=>$items]);
    }
    if ($action==='check_one') {
        json_response(['ok'=>true,'result'=>$service->checkOne(valid_id($input['accounting_entry_id']??null),(int)$actor['id'])]);
    }
    if ($action==='check_many') {
        $ids=$input['accounting_entry_ids']??null;
        if(!is_array($ids)) json_response(['ok'=>false,'error'=>'accounting_entry_ids muss eine Liste sein.'],422);
        json_response($service->checkMany($ids,(int)$actor['id'],100));
    }
    if ($action==='details') {
        json_response(['ok'=>true,'result'=>$service->latestCheck(valid_id($input['accounting_entry_id']??null))]);
    }
    json_response(['ok'=>false,'error'=>'Unbekannte Aktion.'],400);
} catch (Throwable $e) {
    error_log('D1 change list: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>substr($e->getMessage(),0,1000)],422);
}
function valid_id(mixed $v):int {if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<=0)json_response(['ok'=>false,'error'=>'Ungueltige Buchungs-ID.'],422);return (int)$v;}
