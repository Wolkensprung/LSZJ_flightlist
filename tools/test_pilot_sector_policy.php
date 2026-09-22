<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Vereinsflieger/PilotSectorPolicy.php';
use LSZJ\Vereinsflieger\PilotSectorPolicy as P;
$tests=[[['Segelflug'],1,0],[['Motorsegler'],1,1],[['Motorflug'],0,1],[[],1,1],[['Segelflug','Motorflug'],1,1]];
foreach($tests as [$in,$g,$m]){$r=P::capabilities($in);if($r['can_fly_glider']!==$g||$r['can_fly_motor']!==$m){fwrite(STDERR,'FAIL '.json_encode($in).PHP_EOL);exit(1);}}
echo "PASS PilotSectorPolicy\n";
