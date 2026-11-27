<?php
declare(strict_types=1);
namespace BetterPlots\World;

use BetterPlots\Domain\Grid;

final class RoadSelection{

    public static function bounds(array $a,array $b,Grid $grid,int $ground):array{
        $bounds=Selection::bounds($a,$b);
        $bounds[1]=min($bounds[1],$ground-1);

        foreach([[0,3,$grid->offsetX],[2,5,$grid->offsetZ]] as [$lo,$hi,$offset]){
            $phase=$offset+intdiv($grid->size,2);
            $start=$phase+Grid::floorDiv($bounds[$lo]-$phase,$grid->period)*$grid->period;
            $end=$phase+(Grid::floorDiv($bounds[$hi]-$phase,$grid->period)+1)*$grid->period-1;
            $bounds[$lo]=$start;$bounds[$hi]=$end;
        }
        return $bounds;
    }
}
