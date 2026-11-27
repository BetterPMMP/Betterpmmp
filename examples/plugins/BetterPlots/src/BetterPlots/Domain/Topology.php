<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

final class Topology{

    public static function connected(array $cells): bool{
        if($cells === [] || count(array_unique($cells)) !== count($cells)){ return false; }
        $remaining = array_fill_keys($cells, true);
        $queue = [$cells[0]]; unset($remaining[$cells[0]]);
        for($i=0; isset($queue[$i]); ++$i){
            $p = PlotId::parse($queue[$i]);
            foreach([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dz]){
                $key = ($p->x+$dx).';'.($p->z+$dz);
                if(isset($remaining[$key])){ unset($remaining[$key]); $queue[]=$key; }
            }
        }
        return $remaining === [];
    }

    public static function contains(Grid $grid, array $cells, int $x, int $z): bool{
        [$cx,$cz,$lx,$lz] = $grid->cell($x,$z);
        $set = array_fill_keys($cells,true);
        if(!isset($set[$cx.';'.$cz])){ return false; }
        if($lx >= $grid->size && !isset($set[($cx+1).';'.$cz])){ return false; }
        if($lz >= $grid->size && !isset($set[$cx.';'.($cz+1)])){ return false; }
        return $lx < $grid->size || $lz < $grid->size || isset($set[($cx+1).';'.($cz+1)]);
    }
}
