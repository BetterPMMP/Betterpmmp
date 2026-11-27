<?php
declare(strict_types=1);
namespace BetterPlots\Storage;
use BetterPlots\Service\Plots;
use BetterPlots\Domain\Topology;

final class LegacyConverter{
    public static function identity(string $name,?string $uuid=null):string{
        if($name==='*'){return '*';}
        if($uuid!==null&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$uuid)){return strtolower($uuid);}
        if(trim($name)===''){throw new \RuntimeException('Legacy-Spieler ohne Identitaet.');}
        return 'legacy-name:'.strtolower($name);
    }
    public static function convert(string $kind,array $data):array{
        $plots=[];$links=[];$worlds=array_fill_keys($data['world-names']??[],true);
        $ensure=static function(string $world,int $x,int $z)use(&$plots,&$worlds):string{
            if($world===''||strpbrk($world,"/\\\0")!==false){throw new \RuntimeException('Ungueltiger Weltname.');}
            $key=$world.'/'.$x.';'.$z;$worlds[$world]=true;
            if(!isset($plots[$key])){$plots[$key]=Plots::create($world,[$x.';'.$z],'pending','');$plots[$key]['owners']=[];}
            return $key;
        };
        if($kind==='MyPlot'){
            foreach($data['plotsV2'] as $row){$r=array_change_key_case($row);$key=$ensure((string)$r['level'],(int)$r['x'],(int)$r['z']);$p=&$plots[$key];
                $p['ownerName']=(string)($r['owner']??'');if($p['ownerName']!==''){$p['owners']=[self::identity($p['ownerName'])];}
                foreach(['helpers'=>'members','denied'=>'denied'] as $from=>$to){$list=$r[$from]??[];if(is_string($list)){$list=explode(',',$list);}$p[$to]=array_values(array_map(self::identity(...),array_filter($list,static fn($s)=>trim((string)$s)!=='')));}
                $p['alias']=(string)($r['name']??'');$p['flags']['pvp']=(bool)($r['pvp']??false);$p['legacy-data']=$row;unset($p);
            }
            foreach($data['mergedPlotsV2']??[] as $r){$links[]=[$ensure($r['level'],(int)$r['originX'],(int)$r['originZ']),$ensure($r['level'],(int)$r['mergedX'],(int)$r['mergedZ'])];}
        }else{
            $players=[];foreach($data['playerData'] as $r){$players[(string)$r['playerID']]=$r;}
            foreach($data['worlds'] as $r){$worlds[$r['worldName']]=true;}
            foreach($data['plotPlayers'] as $r){$key=$ensure($r['worldName'],(int)$r['x'],(int)$r['z']);$who=$players[(string)$r['playerID']]??throw new \RuntimeException('CPlot-Spielerreferenz fehlt.');
                $role=match($r['state']){'state_owner'=>'owners','state_helper'=>'members','state_trusted'=>'trusted','state_denied'=>'denied',default=>throw new \RuntimeException('Unbekannte CPlot-Rolle.')};
                $plots[$key][$role][]=self::identity((string)$who['playerName'],$who['playerUUID']);if($role==='owners'){$plots[$key]['ownerName']=(string)$who['playerName'];}
            }
            foreach($data['mergePlots']??[] as $r){$links[]=[$ensure($r['worldName'],(int)$r['originX'],(int)$r['originZ']),$ensure($r['worldName'],(int)$r['mergeX'],(int)$r['mergeZ'])];}
            foreach(['plotAliases','plotFlags','plotRates'] as $table){foreach($data[$table]??[] as $r){$key=$ensure($r['worldName'],(int)$r['x'],(int)$r['z']);$plots[$key]['legacy-data'][$table][]=$r;if($table==='plotAliases'){$plots[$key]['alias']=(string)$r['alias'];}
                if($table==='plotFlags'&&in_array($r['ID'],['pvp','redstone','fly','animal-spawn','mob-spawn','border'],true)){$v=strtolower((string)$r['value']);if(in_array($v,['true','false','1','0'],true)){$plots[$key]['flags'][$r['ID']]=in_array($v,['true','1'],true);}}
            }}
        }
        $parent=[];$root=static function(string $k)use(&$parent):string{while(isset($parent[$k])){$k=$parent[$k];}return $k;};
        foreach($links as [$a,$b]){$a=$root($a);$b=$root($b);if($a!==$b){$parent[$b]=$a;}}
        $groups=[];foreach($plots as $key=>$p){$r=$root($key);if(!isset($groups[$r])){$groups[$r]=$plots[$r];$groups[$r]['cells']=[];}
            $g=&$groups[$r];$g['cells']=array_values(array_unique(array_merge($g['cells'],$p['cells'])));

            $g['legacy-cells'][$key]=$p;unset($g);
        }
        foreach($groups as $key=>$p){if(!Topology::connected($p['cells'])){throw new \RuntimeException(\BetterPlots\Text::defer('Nicht zusammenhaengende Legacy-Merge-Gruppe: {v1}',['v1'=>$key]));}if($p['owners']===[]){unset($groups[$key]);}}
        return ['plots'=>$groups,'worlds'=>array_keys($worlds)];
    }
}
