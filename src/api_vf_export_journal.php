<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';require_once __DIR__.'/helpers.php';require_once __DIR__.'/api_authenticated_actor.php';require_once __DIR__.'/Vereinsflieger/FlightExportJournalService.php';
use LSZJ\Vereinsflieger\FlightExportJournalService;
try{api_authenticated_actor(['ADMIN']);if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Methode nicht erlaubt.'],405);$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;csrf_require_valid(isset($in['csrf_token'])?(string)$in['csrf_token']:null);$s=new FlightExportJournalService(db());$a=(string)($in['action']??'list');$from=(string)($in['from']??date('Y-m-d'));$to=(string)($in['to']??date('Y-m-d'));json_response($a==='runs'?$s->runs($from,$to):$s->list($from,$to,(string)($in['status']??'all')));}catch(Throwable $e){json_response(['ok'=>false,'error'=>$e->getMessage()],422);}
