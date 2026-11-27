<?php
declare(strict_types=1);
namespace BetterPlots\World;
use BetterPlots\Main;
use pocketmine\entity\{Animal,Location};
use pocketmine\block\BlockTypeIds;

final class PlotAnimals{
    private int $tick=0;
    public function __construct(private Main $p){}
    public function tick():void{
        if(++$this->tick%400!==0||!$this->p->ready()||!class_exists(Animal::class)){return;}
        $visited=[];
        foreach($this->p->getServer()->getOnlinePlayers() as $player){
            $key=$this->p->keyAt($player->getPosition());if($key===null||isset($visited[$key])){continue;}$visited[$key]=true;
            $plot=$this->p->repo->get($key);$w=$player->getWorld();$name=$w->getFolderName();
            if(!$this->p->flag($plot,$name,'animal-spawn',false)){continue;}
            $cap=max(0,min(6,(int)$this->p->flag($plot,$name,'entity-cap',128)));$count=0;
            foreach($w->getEntities() as $e){if($e instanceof Animal&&$this->p->keyAt($e->getPosition())===$key){++$count;}}
            if($count>=$cap){continue;}
            for($attempt=0;$attempt<8;++$attempt){$x=$player->getPosition()->getFloorX()+mt_rand(-16,16);$z=$player->getPosition()->getFloorZ()+mt_rand(-16,16);
                if(!$w->isChunkLoaded($x>>4,$z>>4)||$this->p->plots->at($name,$this->p->grid($name),$x,$z)!==$key){continue;}
                $y=$w->getHighestBlockAt($x,$z);if($y===null||$y+2>=$w->getMaxY()||$w->getBlockAt($x,$y,$z)->getTypeId()!==BlockTypeIds::GRASS){continue;}
                if($w->getBlockAt($x,$y+1,$z)->getTypeId()!==BlockTypeIds::AIR||$w->getBlockAt($x,$y+2,$z)->getTypeId()!==BlockTypeIds::AIR){continue;}
                $classes=[\pocketmine\entity\Cow::class,\pocketmine\entity\Sheep::class,\pocketmine\entity\Pig::class,\pocketmine\entity\Chicken::class];$class=$classes[array_rand($classes)];
                if(class_exists($class)){$animal=new $class(new Location($x+0.5,$y+1,$z+0.5,$w,mt_rand(0,359),0));$animal->spawnToAll();}break;
            }

            break;
        }
    }
}
