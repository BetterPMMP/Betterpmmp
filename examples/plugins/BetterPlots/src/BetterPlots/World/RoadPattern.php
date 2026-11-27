<?php
declare(strict_types=1);
namespace BetterPlots\World;
final class RoadPattern{
    public static function source(array $bounds,int $x,int $y,int $z,int $dy=0):array{
        $w=$bounds[3]-$bounds[0]+1;$d=$bounds[5]-$bounds[2]+1;
        return [$bounds[0]+(($x-$bounds[0])%$w+$w)%$w,$y-$dy,$bounds[2]+(($z-$bounds[2])%$d+$d)%$d];
    }
}
