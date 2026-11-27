<?php
declare(strict_types=1);
namespace BetterPlots\Protection;
use BetterPlots\Main;
use pocketmine\world\Position;
final class PlotSpawnerRules{
    public static function capacity(Main $plugin,Position $position,?\pocketmine\entity\Entity $candidate=null):bool{
        $world=$position->getWorld();$name=$world->getFolderName();if(!$plugin->managed($name)){return true;}
        $cap=max(0,(int)$plugin->flag($plugin->getPlotAt($position),$name,'entity-cap',128));$count=0;$key=$plugin->keyAt($position);
        foreach($world->getEntities() as $entity){
            if($entity instanceof \pocketmine\player\Player || $entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity->isAlive()){continue;}

            if($candidate!==null && $entity->getId()>=$candidate->getId()){continue;}
            if($plugin->keyAt($entity->getPosition())===$key){++$count;}
        }
        return $count<$cap;
    }
    public static function allows(Main $plugin,Position $source,Position $target):bool{
        $world=$source->getWorld()->getFolderName();$targetWorld=$target->getWorld()->getFolderName();
        if(!$plugin->managed($world)&&!$plugin->managed($targetWorld)){return true;}
        if($world!==$targetWorld||!$plugin->ready()){return false;}
        $key=$plugin->keyAt($source);if($key===null||$plugin->keyAt($target)!==$key){return false;}
        $plot=$plugin->getPlotAt($source);if($plot===null||($plot['owners']??[])===[]){return false;}
        if(!$plugin->flag($plot,$world,'mob-spawner',false)){return false;}
        foreach([$source,$target] as $pos){if($plugin->edits->locked($world,$pos->getFloorX(),$pos->getFloorZ())){return false;}}
        return true;
    }
}
