<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';require_once __DIR__.'/helpers.php';require_once __DIR__.'/api_authenticated_actor.php';
foreach(['FlightPayloadBuilder','FlightPersonResolver','FlightPayloadComparator','FlightSpecialCaseGuard','ExportedFlightCorrectionService'] as $class){require_once __DIR__.'/Vereinsflieger/'.$class.'.php';}
use LSZJ\Vereinsflieger\{ExportedFlightCorrectionService,FlightPayloadComparator,FlightPersonResolver,FlightSpecialCaseGuard};
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);
 $actor=api_authenticated_actor(['ADMIN']);$raw=file_get_contents('php://input');$in=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);
 if(!is_array($in)||array_is_list($in))json_response(['ok'=>false,'error'=>'JSON-Objekt erwartet.'],400);
 csrf_require_valid(isset($in['csrf_token'])?(string)$in['csrf_token']:null);
 $id=filter_var($in['accounting_entry_id']??null,FILTER_VALIDATE_INT);if($id===false||(int)$id<=0)json_response(['ok'=>false,'error'=>'Ungueltige Buchungs-ID.'],422);
 $pdo=db();$service=new ExportedFlightCorrectionService($pdo,new FlightPersonResolver($pdo),new FlightPayloadComparator(),new FlightSpecialCaseGuard());$action=(string)($in['action']??'');
 if($action==='load')json_response(['ok'=>true,'result'=>$service->loadForCorrection((int)$id,$actor)]);
 if($action!=='update')json_response(['ok'=>false,'error'=>'Unbekannte Aktion.'],400);
 $changes=$in['changes']??null;if(!is_array($changes)||array_is_list($changes))json_response(['ok'=>false,'error'=>'changes muss ein JSON-Objekt sein.'],422);
 $version=filter_var($in['expected_row_version']??null,FILTER_VALIDATE_INT);if($version===false||(int)$version<0)json_response(['ok'=>false,'error'=>'Ungueltige Zeilenversion.'],422);
 /* Nur diese authentifizierte ADMIN-Verbindung darf den DB-Trigger passieren. */
 $pdo->exec('SET @lszj_allow_exported_flight_update = 1');
 try {
   $result=$service->correct((int)$id,$actor,$changes,(int)$version,(string)($in['change_reason']??''),($in['confirm_sync_warning']??false)===true);
 } finally {
   $pdo->exec('SET @lszj_allow_exported_flight_update = 0');
 }
 json_response(['ok'=>true,'result'=>$result]);
} catch(Throwable $e){error_log('D1 correction API: '.$e->getMessage());$m=substr($e->getMessage(),0,1000);$status=str_contains($m,'zwischenzeitlich')?409:(str_contains($m,'Administrator')?403:422);json_response(['ok'=>false,'error'=>$m],$status);}
