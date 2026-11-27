<?php
declare(strict_types=1);
namespace BetterPlots\World;
final class Selection{
    public static function bounds(array $a,array $b):array{
        if(($a['world']??null)!==($b['world']??null)){throw new \InvalidArgumentException('Beide Positionen müssen in derselben Welt liegen.');}
        return [min($a['x'],$b['x']),min($a['y'],$b['y']),min($a['z'],$b['z']),max($a['x'],$b['x']),max($a['y'],$b['y']),max($a['z'],$b['z'])];
    }
    public static function contains(?array $bounds,int $x,int $y,int $z):bool{
        return $bounds===null||($x>=$bounds[0]&&$y>=$bounds[1]&&$z>=$bounds[2]&&$x<=$bounds[3]&&$y<=$bounds[4]&&$z<=$bounds[5]);
    }
}
