<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Nur Kommandozeile.\n");exit(2);}
require_once dirname(__DIR__).'/src/db.php';
require_once dirname(__DIR__).'/src/vf_api_bootstrap.php';
use LSZJ\Vereinsflieger\MemberSyncService;
use LSZJ\Vereinsflieger\RestClient;
try{
 $service=new MemberSyncService(db(),new RestClient(vf_api_config()));
 $preview=$service->preview();
 if(($preview['import_allowed']??false)!==true)throw new RuntimeException('Import laut Vorschau gesperrt.');
 $result=$service->execute(true);
 echo json_encode(['ok'=>true,'preview'=>$preview,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
 exit(0);
}catch(Throwable $e){fwrite(STDERR,date('c').' VF-Personenimport fehlgeschlagen: '.$e->getMessage().PHP_EOL);exit(1);}
