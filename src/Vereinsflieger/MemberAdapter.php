<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;
final class MemberAdapter
{
    public static function adapt(array $rows): array
    {
        $out=[];$warnings=[];
        foreach($rows as $index=>$raw){
            $r=[];foreach($raw as $k=>$v)$r[strtolower((string)$k)]=$v;
            $uid=self::value($r,['uid','userno','user_no','benutzernummer']);
            $last=self::value($r,['lastname','last_name','nachname']);
            $first=self::value($r,['firstname','first_name','vorname']);
            $name=self::value($r,['name','displayname','display_name']);
            if($name==='')$name=trim($last.($last!==''&&$first!==''?', ':'').$first);
            if($uid===''||$name===''){$warnings[]='VF-Datensatz '.($index+1).': Benutzernummer oder Name fehlt';continue;}
            $out[]=['MitgliedsNr'=>self::value($r,['memberid','memberno','member_no','mitgliedsnr']),'Name'=>$name,'Mailadresse'=>self::value($r,['email','mail','mailaddress','mailadresse']),'Mobil (privat)'=>self::value($r,['mobile','mobilenumber','mobilephone','mobil']),'Mitgliedsstatus'=>self::value($r,['memberstatus','membershipstatus','member_status','mitgliedsstatus']),'Kostenstufe'=>self::value($r,['costlevel','cost_level','kostenstufe']),'Benutzernummer'=>$uid];
        }
        return ['rows'=>$out,'warnings'=>$warnings];
    }
    private static function value(array $row,array $keys):string{foreach($keys as $key)if(isset($row[$key])&&!is_array($row[$key]))return trim((string)$row[$key]);return '';}
}
