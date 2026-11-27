<?php
declare(strict_types=1);
namespace BetterPlots\World;

use BetterPlots\Domain\Grid;

final class RoadDesign{
    public const STYLES=['allee'=>'City-Allee mit Brücken'];
    public const MIN_ROAD=9;

    public const BRIDGE=7;

    public const TOP=self::BRIDGE+3;

    public static function valid(string $style):bool{return isset(self::STYLES[$style]);}
    public static function check(string $style,Grid $g):void{
        if(!self::valid($style)){throw new \InvalidArgumentException('Unbekannter Straßenstil. Verfügbar: '.implode(', ',array_keys(self::STYLES)));}
        if($g->road<self::MIN_ROAD){throw new \InvalidArgumentException('Der Stil braucht mindestens '.self::MIN_ROAD.' Blöcke Straßenbreite (empfohlen 13–17). Neue Welt z. B.: /p setup Stadt 32 15 64');}
    }

    private static function local(Grid $g,int $x,int $z):?array{
        [,,$gx,$gz]=$g->cell($x,$z);$rx=$gx>=$g->size;$rz=$gz>=$g->size;
        if(!$rx&&!$rz){return null;}
        if($rx&&$rz){return ['cross',$gx-$g->size,$gz-$g->size];}
        return $rx?['x',$gx-$g->size,$gz]:['z',$gz-$g->size,$gx];
    }
    private static function deck(Grid $g,int $x,int $z):bool{
        $l=self::local($g,$x,$z);if($l===null){return false;}$c=intdiv($g->road-1,2);
        return $l[0]==='cross'?abs($l[1]-$c)<=2||abs($l[2]-$c)<=2:abs($l[1]-$c)<=2;
    }
    private static function hash(int $x,int $z):int{return (($x*73856093)^($z*19349663))&0x7fffffff;}

    public static function block(string $style,Grid $g,int $x,int $dy,int $z):?string{
        $l=self::local($g,$x,$z);if($l===null){return null;}
        [$kind,$a,$along]=$l;$r=$g->road;$c=intdiv($r-1,2);$B=self::BRIDGE;
        $deck=self::deck($g,$x,$z);
        if($kind==='cross'){
            $b=$along;$pillar=in_array($a,[$c-2,$c+2],true)&&in_array($b,[$c-2,$c+2],true);
            if($dy===0){
                if($a===0||$b===0||$a===$r-1||$b===$r-1){return 'stone_bricks';}

                if(($a===$c&&abs($b-$c)<=1)||($b===$c&&abs($a-$c)<=1)){return 'glowstone';}
                return $pillar?'stone_bricks':((($a+$b)%4===0)?'glowstone':'dark_oak_planks');
            }
            if($pillar&&$dy<$B){return $dy===$B-2?'glowstone':($dy===1?'stone_bricks':'dark_oak_log');}
            return self::upper($g,$x,$z,$dy,$deck,$a===$c&&$b===$c);
        }
        $pillarRow=$a===$c-2||$a===$c+2;$pillar=$pillarRow&&$along%8===4;
        if($dy===0){
            if($a===0||$a===$r-1){return 'stone_bricks';}
            if($pillarRow){return 'stone_bricks';}
            if(abs($a-$c)<=1){return 'grass';}

            $mid=$a<$c?intdiv($c-2,2):intdiv($r-1+$c+2+1,2);
            return $a===$mid&&$along%3===1?'glowstone':'dark_oak_planks';
        }
        if($pillar&&$dy<$B){return $dy===$B-2?'glowstone':($dy===1?'stone_bricks':'dark_oak_log');}
        if($pillarRow&&$dy<$B){

            $d=(($along-4)%8+8)%8;$span=min($d,8-$d);
            $depth=[0=>0,1=>3,2=>2,3=>1,4=>0][$span];
            if($dy>=$B-$depth){return 'dark_oak_planks';}
            return 'air';
        }
        if($dy===1&&abs($a-$c)<=1&&!$pillarRow){
            $h=self::hash($x,$z)%20;return match(true){$h<9=>'tall_grass',$h===9=>'poppy',$h===10=>'dandelion',$h===11=>'cornflower',$h===12=>'oxeye_daisy',default=>'air'};
        }
        return self::upper($g,$x,$z,$dy,$deck,false);
    }

    private static function upper(Grid $g,int $x,int $z,int $dy,bool $deck,bool $centre):string{
        $B=self::BRIDGE;if(!$deck){return 'air';}
        if($dy===$B){return $centre||self::hash($x,$z)%17===0?'shroomlight':'dark_oak_planks';}
        if($dy===$B+1){
            foreach([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dz]){if(!self::deck($g,$x+$dx,$z+$dz)){return 'dark_oak_fence';}}
            return 'air';
        }
        return 'air';
    }
}
