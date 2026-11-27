<?php
declare(strict_types=1);
namespace BetterPlots\World;

use BetterPlots\Domain\{Grid,Topology};

final class EditSelection{

    public static function unlinkColumn(Grid $grid,array $cells,array $after,int $x,int $z,array $bounds):?array{
        $column=self::column($grid,$cells,$x,$z,'unlink',$bounds);
        if($column===null){return null;}
        [,,$wasEdge,$road,$original]=$column;
        foreach($after as $group){
            if(!Topology::contains($grid,$group,$x,$z)){continue;}
            if($road||$wasEdge){return null;}
            $edge=!Topology::contains($grid,$group,$x-1,$z)||!Topology::contains($grid,$group,$x+1,$z)||!Topology::contains($grid,$group,$x,$z-1)||!Topology::contains($grid,$group,$x,$z+1);
            return $original&&$edge?$column:null;
        }
        return $road?$column:null;
    }

    public static function mergeColumn(Grid $grid,array $cells,array $before,int $x,int $z,array $bounds):?array{
        $column=self::column($grid,$cells,$x,$z,'merge',$bounds);
        if($column===null){return null;}
        [$x,$z,$edge,$road,$original]=$column;
        foreach($before as $group){
            if(!Topology::contains($grid,$group,$x,$z)){continue;}
            if($road||$edge){return null;}
            $wasEdge=!Topology::contains($grid,$group,$x-1,$z)||!Topology::contains($grid,$group,$x+1,$z)||!Topology::contains($grid,$group,$x,$z-1)||!Topology::contains($grid,$group,$x,$z+1);
            return $wasEdge?$column:null;
        }
        return $road?$column:null;
    }

    public static function column(Grid $grid,array $cells,int $x,int $z,string $mode,array $bounds):?array{
        if($mode==='road'&&($x<$bounds[0]||$x>$bounds[2]||$z<$bounds[1]||$z>$bounds[3])){return null;}
        if($mode!=='road'&&!Topology::contains($grid,$cells,$x,$z)){return null;}
        [,,$lx,$lz]=$grid->cell($x,$z);
        $road=$lx>=$grid->size||$lz>=$grid->size;
        if($mode==='road'&&!$road){return null;}
        $original=!$road&&($lx===0||$lz===0||$lx===$grid->size-1||$lz===$grid->size-1);
        if(in_array($mode,['merge','unlink'],true)&&!$road&&!$original){return null;}
        if($mode==='middle'&&($x!==intdiv($bounds[0]+$bounds[2],2)||$z!==intdiv($bounds[1]+$bounds[3],2))){return null;}
        $edge=!Topology::contains($grid,$cells,$x-1,$z)||!Topology::contains($grid,$cells,$x+1,$z)||!Topology::contains($grid,$cells,$x,$z-1)||!Topology::contains($grid,$cells,$x,$z+1);
        if(in_array($mode,['wall','border','outline'],true)&&!$edge){return null;}
        return [$x,$z,$edge,$road,$original];
    }

    public static function heights(string $mode,int $minimum,int $maximum,int $height):array{
        [$low,$high]=match($mode){
            'road','floor'=>[$height,$height+1],
            'border','outline'=>[$height+1,$height+2],
            'wall'=>[$minimum+1,$height+1],
            'main'=>[$minimum+1,$height],
            'air'=>[$height+1,$maximum],
            'refill'=>[$minimum,$height+1],
            'merge'=>[$minimum,$maximum],
            default=>[$minimum,$maximum]
        };
        return [max($minimum,min($maximum,$low)),max($minimum,min($maximum,$high))];
    }
}
