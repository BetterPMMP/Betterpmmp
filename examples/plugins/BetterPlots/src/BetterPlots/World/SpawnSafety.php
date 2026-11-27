<?php
declare(strict_types=1);
namespace BetterPlots\World;
use BetterPlots\Main;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\block\BlockTypeIds;

final class SpawnSafety{
    private array $seen=[],$active=[];
    private int $ticks=0;
    public function __construct(private Main $p){}
    public function rescuing(Player $player):bool{return isset($this->active[spl_object_id($player)]);}
    public static function safeY(int $start,int $min,int $max,\Closure $safe):?int{
        $start=max($min+1,min($max-2,$start));
        for($y=$start;$y<$max-1;++$y){if($safe($y)){return $y;}}
        for($y=$start-1;$y>$min;--$y){if($safe($y)){return $y;}}
        return null;
    }
    public function tick():void{
        if(++$this->ticks%10!==0){return;}$online=[];
        foreach($this->p->getServer()->getOnlinePlayers() as $player){
            $id=spl_object_id($player);$online[$id]=true;$world=$player->getWorld();$worldId=$world->getId();
            if(($this->seen[$id]??null)===$worldId){continue;}
            if(!$this->p->managed($world->getFolderName())){$this->seen[$id]=$worldId;continue;}
            $pos=$player->getPosition();$x=$pos->getFloorX();$z=$pos->getFloorZ();$y=$pos->getFloorY();
            $chunk=$world->getChunk($x>>4,$z>>4);if($chunk===null||!$chunk->isPopulated()){continue;}
            $this->seen[$id]=$worldId;
            if($y>$world->getMinY()&&$y+1<$world->getMaxY()&&!$world->getBlockAt($x,$y,$z)->isFullCube()&&!$world->getBlockAt($x,$y+1,$z)->isFullCube()){continue;}
            $safe=self::safeY($y,$world->getMinY(),$world->getMaxY(),static function(int $at)use($world,$x,$z):bool{
                $floor=$world->getBlockAt($x,$at-1,$z);
                return $floor->isFullCube()&&!$floor instanceof \pocketmine\block\Magma&&$world->getBlockAt($x,$at,$z)->getTypeId()===BlockTypeIds::AIR&&$world->getBlockAt($x,$at+1,$z)->getTypeId()===BlockTypeIds::AIR;
            });
            if($safe===null){$this->p->say($player,'Kein freier Spawnplatz ueber dieser Position gefunden. Bitte einen Admin um Teleport bitten.');continue;}
            $this->active[$id]=true;
            try{$player->teleport(new Position($x+0.5,$safe,$z+0.5,$world));}finally{unset($this->active[$id]);}
        }
        $this->seen=array_intersect_key($this->seen,$online);
    }
}
