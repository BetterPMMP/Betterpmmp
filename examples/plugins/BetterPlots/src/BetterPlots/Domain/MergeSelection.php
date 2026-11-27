<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

final class MergeSelection{
    public static function facing(float $yaw):string{
        return ['s','w','n','e'][((int)floor((fmod(fmod($yaw,360)+360,360)+45)/90))%4];
    }
    public static function neighbour(string $cell,string $direction):string{
        $p=PlotId::parse($cell);[$dx,$dz]=match($direction){'n'=>[0,-1],'e'=>[1,0],'s'=>[0,1],'w'=>[-1,0],default=>throw new \InvalidArgumentException('Richtung: n/e/s/w oder Blickrichtung.')};
        return ($p->x+$dx).';'.($p->z+$dz);
    }

    public static function split(array $cells,string $from,string $to):array{
        if(!in_array($from,$cells,true)||!in_array($to,$cells,true)){throw new \RuntimeException('In dieser Richtung gibt es keine verbundene Plotseite.');}
        $set=array_fill_keys($cells,true);$seen=[$from=>true];$queue=[$from];
        for($i=0;isset($queue[$i]);$i++){
            foreach(['n','e','s','w'] as $d){$next=self::neighbour($queue[$i],$d);
                if(($queue[$i]===$from&&$next===$to)||($queue[$i]===$to&&$next===$from)){continue;}
                if(isset($set[$next])&&!isset($seen[$next])){$seen[$next]=true;$queue[]=$next;}
            }
        }
        if(isset($seen[$to])){throw new \RuntimeException('Diese Plots sind auch ueber andere Seiten verbunden. Eine einzelne Seite trennt diese Gruppe nicht; es wurde nichts veraendert.');}
        return [$queue,array_values(array_diff($cells,$queue))];
    }

    public static function nearby(array $plots,string $start,string $owner,string $world):array{
        $index=[];foreach($plots as $key=>$plot){if($plot['world']===$world&&in_array($owner,$plot['owners'],true)){foreach($plot['cells'] as $cell){$index[$cell]=$key;}}}
        if(!isset($plots[$start])||!in_array($owner,$plots[$start]['owners'],true)){throw new \RuntimeException('Das Ausgangsplot gehoert nicht diesem Spieler.');}
        $keys=[$start=>true];$queue=[$start];
        for($i=0;isset($queue[$i]);$i++){foreach($plots[$queue[$i]]['cells'] as $cell){foreach(['n','e','s','w'] as $d){$key=$index[self::neighbour($cell,$d)]??null;if($key!==null&&!isset($keys[$key])){$keys[$key]=true;$queue[]=$key;}}}}
        return $queue;
    }
}
