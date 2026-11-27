<?php
declare(strict_types=1);
namespace BetterPlots\Spawner;
use pocketmine\entity\{EntityFactory,EntityDataHelper,Living};
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
final class Registration{
    private static array $ids=[];
    public static function register():void{
        if(self::$ids!==[]){return;}
        foreach(require __DIR__.'/catalog.php' as $network=>[$native,$adapter,$save]){
            if(!class_exists($native)){continue;}
            EntityFactory::getInstance()->register($adapter,static function(World $world,CompoundTag $nbt)use($adapter):Living{return new $adapter(EntityDataHelper::parseLocation($nbt,$world),$nbt);},[$save]);
            self::$ids[$network]=$save;
        }
        \pocketmine\block\tile\TileFactory::getInstance()->register(PlotSpawnerTile::class,['SplashGamesPlotSpawner']);
    }
    public static function id(string $network):?string{return self::$ids[$network]??null;}
}
