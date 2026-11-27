<?php
declare(strict_types=1);
namespace BetterPlots\Spawner;
use pocketmine\nbt\tag\CompoundTag;
trait SpawnerOrigin{
    private ?CompoundTag $splashSpawnerOrigin=null;
    public function initEntity(CompoundTag $nbt):void{
        parent::initEntity($nbt);$origin=$nbt->getCompoundTag('SplashGames:PlotSpawner');$this->splashSpawnerOrigin=$origin===null?null:clone $origin;
        if($origin!==null&&method_exists($this,'setPersistent')){$this->setPersistent();}
    }
    public function getSplashSpawnerOrigin():?CompoundTag{return $this->splashSpawnerOrigin===null?null:clone $this->splashSpawnerOrigin;}
    public function saveNBT():CompoundTag{
        $nbt=parent::saveNBT();if($this->splashSpawnerOrigin!==null){$nbt->setTag('SplashGames:PlotSpawner',clone $this->splashSpawnerOrigin);}return $nbt;
    }
}
