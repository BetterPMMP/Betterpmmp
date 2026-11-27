<?php
declare(strict_types=1);
namespace BetterPlots\Service;

use BetterPlots\Domain\{Grid,PlotId,Topology};
use BetterPlots\Storage\Repository;

final class Plots{

    private array $index = [];
    public function __construct(public readonly Repository $repo){}
    public function rebuild(): void{
        $index = [];
        foreach($this->repo->all('bp/plot/') as $key=>$plot){
            if(!is_string($plot['world'] ?? null) || !is_array($plot['cells'] ?? null) || !Topology::connected($plot['cells'])){
                throw new \UnexpectedValueException(\BetterPlots\Text::defer('Ungültiger Plot-Datensatz: {key}',['key'=>$key]));
            }
            foreach($plot['cells'] as $cell){
                $id = $plot['world'].'/'.$cell;
                if(isset($index[$id])){ throw new \UnexpectedValueException('Überlappende Plots.'); }
                $index[$id]=$key;
            }
        }
        $this->index=$index;
    }
    public function cell(string $world, string $id): ?string{ return $this->index[$world.'/'.$id] ?? null; }
    public function at(string $world, Grid $grid, int $x, int $z): ?string{
        [$cx,$cz] = $grid->cell($x,$z);
        $key=$this->cell($world,$cx.';'.$cz);
        $plot=$key === null ? null : $this->repo->get($key);
        return $plot !== null && Topology::contains($grid,$plot['cells'],$x,$z) ? $key : null;
    }

    public static function create(string $world, array $cells, string $owner, string $name): array{
        if(!Topology::connected($cells)){ throw new \InvalidArgumentException('Plotgruppe muss zusammenhängend sein.'); }
        return ['world'=>$world,'cells'=>$cells,'owners'=>[$owner],'ownerName'=>$name,'trusted'=>[],'members'=>[],'denied'=>[],
            'flags'=>[],'alias'=>'','description'=>'','home'=>null,'created'=>time(),'activity'=>time(),'ratings'=>[],'comments'=>[]];
    }
    public function ownedCount(string $uuid, string $world): int{
        $n=0; foreach($this->repo->all('bp/plot/') as $plot){ if($plot['world']===$world && in_array($uuid,$plot['owners'],true)){ $n+=count($plot['cells']); } } return $n;
    }

    public function commit(array $updates, \Closure $done): void{
        $this->repo->commit($updates,function(bool $ok) use($done):void{ if($ok){$this->rebuild();} $done($ok); });
    }
}
