<?php
declare(strict_types=1);
namespace BetterPlots\Storage;
require_once __DIR__.'/LocalizedException.php';

final class LegacyReader{
    public static function read(array $source):array{
        $kind=$source['kind'];$format=$source['format'];$path=$source['path'];
        if(!is_file($path)){throw new LocalizedException('Legacy-Datenbank fehlt: {v1}',['v1'=>$path]);}
        if(in_array($format,['json','yaml'],true)){
            if(filesize($path)>67108864){throw new \RuntimeException('Legacy-Datei zu gross.');}
            $data=$format==='json'?json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR):yaml_parse_file($path);
            if(!is_array($data)||!isset($data['plots'])){throw new \RuntimeException('Unbekanntes MyPlot-Dateiformat.');}
            $merges=[];
            foreach($data['merges']??[] as $origin=>$members){$a=explode(';',$origin);if(count($a)!==3){throw new \RuntimeException('Ungueltige Merge-ID.');}foreach($members as $id){$b=explode(';',$id);if(count($b)!==3||$a[0]!==$b[0]){throw new \RuntimeException('Ungueltige Merge-ID.');}$merges[]=['level'=>$a[0],'originX'=>$a[1],'originZ'=>$a[2],'mergedX'=>$b[1],'mergedZ'=>$b[2]];}}
            return ['plotsV2'=>array_values($data['plots']),'mergedPlotsV2'=>$merges];
        }
        if($format!=='sqlite'){throw new \RuntimeException('Automatische Migration unterstuetzt derzeit SQLite, JSON und YAML.');}
        $db=new \SQLite3($path,SQLITE3_OPEN_READONLY);$db->enableExceptions(true);$db->busyTimeout(3000);
        $tables=$kind==='CPlot'?['worlds','playerData','plotPlayers','mergePlots','plotAliases','plotFlags','plotRates']:['plotsV2','mergedPlotsV2','plots','mergedPlots'];$out=[];
        $db->exec('BEGIN');
        try{foreach($tables as $table){$exists=$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='".$table."'");
            if(!$exists){if(in_array($table,['worlds','playerData','plotPlayers'],true)){throw new LocalizedException('Legacy-Tabelle fehlt: {v1}',['v1'=>$table]);}continue;}
            $rows=$db->query('SELECT * FROM '.$table);$out[$table]=[];
            while(($row=$rows->fetchArray(SQLITE3_ASSOC))!==false){$out[$table][]=$row;if(count($out[$table])>100000){throw new \RuntimeException('Legacy-Tabelle zu gross fuer automatischen Import.');}}
        }}finally{$db->exec('ROLLBACK');$db->close();}
        return $kind==='MyPlot'?self::myPlotTables($out):$out;
    }
    public static function myPlotTables(array $tables):array{
        if(array_key_exists('plotsV2',$tables)){return ['plotsV2'=>$tables['plotsV2'],'mergedPlotsV2'=>$tables['mergedPlotsV2']??[]];}
        if(!array_key_exists('plots',$tables)){throw new \RuntimeException('MyPlot-Tabelle plots oder plotsV2 fehlt.');}
        $rows=$tables['plots'];$byId=[];$merges=[];
        foreach($rows as $row){$r=array_change_key_case($row);foreach(['level','x','z','owner'] as $field){if(!array_key_exists($field,$r)){throw new LocalizedException('Altes MyPlot-Format: Spalte {v1} fehlt.',['v1'=>$field]);}}if(isset($r['id'])){$byId[(string)$r['id']]=$r;}}
        foreach($tables['mergedPlots']??[] as $row){$r=array_change_key_case($row);
            if(isset($r['originid'],$r['mergedid'])){
                $a=$byId[(string)$r['originid']]??throw new \RuntimeException('MyPlot-Merge: Ursprungsplot fehlt.');$b=$byId[(string)$r['mergedid']]??throw new \RuntimeException('MyPlot-Merge: Teilplot fehlt.');
                if($a['level']!==$b['level']){throw new \RuntimeException('MyPlot-Merge verbindet verschiedene Welten.');}
                $merges[]=['level'=>$a['level'],'originX'=>$a['x'],'originZ'=>$a['z'],'mergedX'=>$b['x'],'mergedZ'=>$b['z']];
            }else{throw new \RuntimeException('Unbekanntes altes MyPlot-Mergeformat.');}
        }
        return ['plotsV2'=>$rows,'mergedPlotsV2'=>$merges];
    }
}
