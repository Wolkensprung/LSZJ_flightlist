<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;
use LSZJ\MasterData\VereinsfliegerCsvImporter;use PDO;use RuntimeException;
final class MemberSyncService
{
    public function __construct(private PDO $pdo,private RestClient $client){}
    public function preview():array
    {
        $a=MemberAdapter::adapt($this->client->listUsers());$rows=$a['rows'];
        if($rows===[])throw new RuntimeException('VF lieferte keine importierbaren Personen. Es wurden keine Daten geändert.');
        $current=[];foreach($this->pdo->query('SELECT vf_user_no,source_hash,is_active FROM pilots_master')->fetchAll(PDO::FETCH_ASSOC) as $r)$current[(string)$r['vf_user_no']]=$r;
        $new=0;$changed=0;$unchanged=0;$seen=[];
        foreach($rows as $r){$uid=$r['Benutzernummer'];$seen[$uid]=true;$hash=$this->hash($r);if(!isset($current[$uid]))$new++;elseif((int)$current[$uid]['is_active']===1&&hash_equals((string)$current[$uid]['source_hash'],$hash))$unchanged++;else$changed++;}
        $deactivate=0;foreach($current as $uid=>$r)if((int)$r['is_active']===1&&!isset($seen[$uid]))$deactivate++;
        return ['ok'=>true,'rows_received'=>count($rows),'new'=>$new,'changed'=>$changed,'unchanged'=>$unchanged,'would_deactivate'=>$deactivate,'adapter_warnings'=>$a['warnings'],'sample'=>array_slice($rows,0,10)];
    }
    public function execute(bool $confirmed):array
    {
        if(!$confirmed)throw new RuntimeException('Die Vollständigkeit der VF-Liste muss bestätigt werden.');
        $a=MemberAdapter::adapt($this->client->listUsers());if($a['rows']===[])throw new RuntimeException('VF lieferte keine importierbaren Personen. Es wurden keine Daten geändert.');
        $path=tempnam(sys_get_temp_dir(),'vf-members-');if($path===false)throw new RuntimeException('Temporäre Importdatei konnte nicht erstellt werden.');
        try{$out=fopen($path,'wb');fputcsv($out,['MitgliedsNr','Name','Mailadresse','Mobil (privat)','Mitgliedsstatus','Kostenstufe','Benutzernummer'],';','"','\\');foreach($a['rows'] as $row)fputcsv($out,array_values($row),';','"','\\');fclose($out);$result=(new VereinsfliegerCsvImporter($this->pdo))->importMembers($path,'vereinsflieger-rest-user-list.csv');$result['adapter_warnings']=$a['warnings'];$result['source']='vereinsflieger-rest-api';return $result;}finally{if(is_file($path))unlink($path);}
    }
    private function hash(array $r):string{return hash('sha256',implode('|',[$r['Benutzernummer'],$r['MitgliedsNr'],$r['Name'],$r['Mailadresse'],$r['Mobil (privat)'],$r['Mitgliedsstatus'],$r['Kostenstufe']]));}
}
