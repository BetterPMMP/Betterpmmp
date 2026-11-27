<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

final class Border{

    public static function contains(Grid $grid,array $cells,int $height,int $x,int $y,int $z,int $minimum=-64):bool{
        if($y<$minimum||$y>$height+1||!Topology::contains($grid,$cells,$x,$z)){return false;}
        foreach([[-1,0],[1,0],[0,-1],[0,1]] as [$dx,$dz]){
            if(!Topology::contains($grid,$cells,$x+$dx,$z+$dz)){return true;}
        }
        return false;
    }
}
