<?php
declare(strict_types=1);
function vf_api_config():array
{
    $app=function_exists('app_config')?app_config():[];$file=is_array($app['vereinsflieger_api']??null)?$app['vereinsflieger_api']:[];
    $env=static function(string $name,mixed $default=''):mixed{$value=getenv($name);return $value===false?$default:$value;};
    return ['base_url'=>$env('VF_API_BASE_URL',$file['base_url']??'https://www.vereinsflieger.de/interface/rest'),'username'=>$env('VF_API_USERNAME',$file['username']??''),'password'=>$env('VF_API_PASSWORD',$file['password']??''),'appkey'=>$env('VF_API_APPKEY',$file['appkey']??''),'cid'=>$env('VF_API_CID',$file['cid']??''),'auth_secret'=>$env('VF_API_AUTH_SECRET',$file['auth_secret']??''),'connect_timeout'=>10,'timeout'=>30];
}
