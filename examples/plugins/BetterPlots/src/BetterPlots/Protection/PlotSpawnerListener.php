<?php
declare(strict_types=1);
namespace BetterPlots\Protection;
use BetterPlots\Main;
use pocketmine\event\Listener;
use pocketmine\event\entity\BlockSpawnerSpawnEvent;
final class PlotSpawnerListener implements Listener{
    public function __construct(private Main $plugin){}
    /** @priority HIGHEST */
    public function spawning(BlockSpawnerSpawnEvent $event):void{
        if(!PlotSpawnerRules::allows($this->plugin,$event->getSpawner(),$event->getSpawnPosition())||!PlotSpawnerRules::capacity($this->plugin,$event->getSpawnPosition())){$event->cancel();}
    }
}
