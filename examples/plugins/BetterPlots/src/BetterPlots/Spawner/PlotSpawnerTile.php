<?php
declare(strict_types=1);
namespace BetterPlots\Spawner;
use pocketmine\nbt\tag\CompoundTag;

final class PlotSpawnerTile extends \pocketmine\block\tile\MonsterSpawner{
    public static ?SpawnerService $service=null;
    private int $wait=20;
    public array $limits=['min'=>200,'max'=>800,'count'=>4,'nearby'=>6,'range'=>4,'player'=>16];
    public function readSaveData(CompoundTag $nbt):void{
        parent::readSaveData($nbt);$this->wait=max(0,$nbt->getShort('Delay',20));
        foreach(['min'=>['MinSpawnDelay',200,20,24000],'max'=>['MaxSpawnDelay',800,20,24000],'count'=>['SpawnCount',4,1,16],'nearby'=>['MaxNearbyEntities',6,1,32],'range'=>['SpawnRange',4,1,8],'player'=>['RequiredPlayerRange',16,1,32]] as $key=>[$tag,$default,$min,$max]){$this->limits[$key]=max($min,min($max,$nbt->getShort($tag,$default)));}
    }
    protected function writeSaveData(CompoundTag $nbt):void{parent::writeSaveData($nbt);$nbt->setShort('Delay',$this->wait);}
    protected function addAdditionalSpawnData(CompoundTag $nbt):void{parent::addAdditionalSpawnData($nbt);$nbt->setString(self::TAG_ID,'MobSpawner');}
    public function setEntityTypeId(string $id):void{parent::setEntityTypeId($id);$this->wait=20;}
    public function tickSpawner():void{
        $service=self::$service;
        if($service===null){return;}
        if(!$service->managed($this->getPosition())){if(method_exists(parent::class,'tickSpawner')){parent::tickSpawner();}return;}
        if(!$service->active($this)){return;}
        $this->wait=max(0,$this->wait-20);if($this->wait>0){return;}
        $service->spawn($this);$this->wait=random_int(min($this->limits['min'],$this->limits['max']),max($this->limits['min'],$this->limits['max']));
    }
}
