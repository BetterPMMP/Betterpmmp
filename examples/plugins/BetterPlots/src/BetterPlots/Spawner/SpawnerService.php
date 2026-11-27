<?php
declare(strict_types=1);
namespace BetterPlots\Spawner;
use BetterPlots\Main;
use BetterPlots\Protection\PlotSpawnerRules;
use pocketmine\event\Listener;
use pocketmine\world\Position;
use pocketmine\entity\{Entity,EntityFactory,Living};
use pocketmine\nbt\tag\{CompoundTag,ListTag,DoubleTag,FloatTag};
final class SpawnerService implements Listener{
    private array $pending=[];
    public function defer(Entity $entity,\Closure $validate):void{$this->pending[$entity->getId()]=[$entity,$validate];}
    private int $ticks=0;
    private array $status=[];
    public function __construct(private Main $plugin){PlotSpawnerTile::$service=$this;}
    public function managed(Position $p):bool{return $this->plugin->managed($p->getWorld()->getFolderName());}
    private function key(Position $p):string{return $p->getWorld()->getFolderName().':'.$p->getFloorX().':'.$p->getFloorY().':'.$p->getFloorZ();}
    public function convert(\pocketmine\block\tile\Tile $tile):void{
        if(!$tile instanceof \pocketmine\block\tile\MonsterSpawner||$tile instanceof PlotSpawnerTile||$tile->isClosed()||!$this->managed($tile->getPosition())){return;}
        $pos=$tile->getPosition();$world=$pos->getWorld();$data=$tile->saveNBT();$replacement=new PlotSpawnerTile($world,$pos);$replacement->readSaveData($data);
        $tile->close();$world->addTile($replacement);$world->scheduleDelayedBlockUpdate($pos,1);
    }
    public function chunk(\pocketmine\event\world\ChunkLoadEvent $e):void{foreach($e->getChunk()->getTiles() as $tile){$this->convert($tile);}}
    /** @priority MONITOR */
    public function place(\pocketmine\event\block\BlockPlaceEvent $e):void{
        if($e->isCancelled()){return;}
        $positions=[];foreach($e->getTransaction()->getBlocks() as [$x,$y,$z,$block]){if($block instanceof \pocketmine\block\MonsterSpawner){$positions[]=clone $block->getPosition();}}
        if($positions!==[]){$this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function()use($positions):void{foreach($positions as $pos){if($pos->isValid()&&($tile=$pos->getWorld()->getTile($pos))!==null){$this->convert($tile);}}}),1);}
    }
    public function scan():void{
        foreach($this->plugin->getServer()->getWorldManager()->getWorlds() as $world){if(!$this->plugin->managed($world->getFolderName())){continue;}foreach($world->getLoadedChunks() as $chunk){foreach($chunk->getTiles() as $tile){$this->convert($tile);}}}
    }
    public function tick():void{
        if($this->plugin->ready()){foreach($this->pending as $id=>[$entity,$validate]){unset($this->pending[$id]);if(!$entity->isClosed()&&!$entity->isFlaggedForDespawn()){$validate();}}}
        if(++$this->ticks%20===0){$this->scan();}
    }
    public function active(PlotSpawnerTile $tile):bool{
        $p=$tile->getPosition();$key=$this->key($p);
        if(!$this->plugin->ready()){$this->status[$key]='Plotdaten laden';return false;}
        if(!PlotSpawnerRules::allows($this->plugin,$p,$p)){$this->status[$key]='Flag aus, kein eigenes Plot oder Plot wird bearbeitet';return false;}
        if(Registration::id($tile->getEntityTypeId())===null){$this->status[$key]='Kein unterstuetzter Mobtyp; Spawn-Ei am Spawner benutzen';return false;}
        foreach($p->getWorld()->getPlayers() as $player){if(!$player->isSpectator()&&$player->getPosition()->distanceSquared($p)<=$tile->limits['player']**2){return true;}}
        $this->status[$key]='Kein Spieler nah genug';return false;
    }
    public function spawn(PlotSpawnerTile $tile):void{
        $source=$tile->getPosition();$world=$source->getWorld();$key=$this->key($source);$type=$tile->getEntityTypeId();$id=Registration::id($type);if($id===null||!$this->active($tile)){return;}
        $water=in_array($type,['minecraft:cod','minecraft:salmon','minecraft:pufferfish','minecraft:tropicalfish','minecraft:squid','minecraft:glow_squid','minecraft:dolphin','minecraft:guardian','minecraft:elder_guardian','minecraft:tadpole','minecraft:axolotl','minecraft:nautilus'],true);
        $created=0;$reason='Kein freier Spawnplatz auf demselben Plot';
        for($n=0;$n<$tile->limits['count'];$n++){
            if(!PlotSpawnerRules::capacity($this->plugin,$source)){$reason='Plot-Entitylimit erreicht';break;}
            $near=0;foreach($world->getEntities() as $entity){if($entity instanceof Living&&!$entity->isClosed()&&!$entity->isFlaggedForDespawn()&&$entity->isAlive()&&$entity::getNetworkTypeId()===$type&&$entity->getPosition()->distanceSquared($source)<=($tile->limits['range']*2)**2){++$near;}}
            if($near>=$tile->limits['nearby']){$reason='Lokales Moblimit erreicht';break;}
            for($attempt=0;$attempt<16;$attempt++){
                $x=$source->getFloorX()+random_int(-$tile->limits['range'],$tile->limits['range']);$y=$source->getFloorY()+random_int(-1,1);$z=$source->getFloorZ()+random_int(-$tile->limits['range'],$tile->limits['range']);
                if(!$world->isChunkLoaded($x>>4,$z>>4)||!$world->isInWorld($x,$y-1,$z)||!$world->isInWorld($x,$y+2,$z)){continue;}
                $target=new Position($x+0.5,$y,$z+0.5,$world);if(!PlotSpawnerRules::allows($this->plugin,$source,$target)){continue;}
                if(($world->getBlockAt($x,$y,$z) instanceof \pocketmine\block\Water)!==$water){continue;}
                if(!$water&&!$world->getBlockAt($x,$y-1,$z)->isSolid()){continue;}
                for($dy=0;$dy<3;$dy++){if($world->getBlockAt($x,$y+$dy,$z)->isSolid()){continue 2;}}
                $origin=CompoundTag::create()->setString('world',$world->getFolderName())->setInt('x',$source->getFloorX())->setInt('y',$source->getFloorY())->setInt('z',$source->getFloorZ());
                $nbt=CompoundTag::create()->setString(EntityFactory::TAG_IDENTIFIER,$id)->setTag('SplashGames:PlotSpawner',$origin)->setTag(Entity::TAG_POS,new ListTag([new DoubleTag($target->x),new DoubleTag($target->y),new DoubleTag($target->z)]))->setTag(Entity::TAG_MOTION,new ListTag([new DoubleTag(0),new DoubleTag(0),new DoubleTag(0)]))->setTag(Entity::TAG_ROTATION,new ListTag([new FloatTag(random_int(0,359)),new FloatTag(0)]));
                $entity=EntityFactory::getInstance()->createFromData($world,$nbt);
                if($entity instanceof Living&&method_exists($entity,'getSplashSpawnerOrigin')&&$entity->getSplashSpawnerOrigin()!==null){$entity->spawnToAll();++$created;break;}
                $entity?->flagForDespawn();$reason='Mob konnte nicht sicher erstellt werden';
            }
        }
        $this->status[$key]=$created>0?\BetterPlots\Text::defer('{count} Mob(s) erzeugt; Wartezeit bis zur naechsten Runde',['count'=>$created]):$reason;
    }
    public function report(Position $at):string{
        $nearest=null;$distance=25*25;foreach($at->getWorld()->getLoadedChunks() as $chunk){foreach($chunk->getTiles() as $tile){if(!$tile instanceof \pocketmine\block\tile\MonsterSpawner){continue;}$d=$tile->getPosition()->distanceSquared($at);if($d<$distance){$distance=$d;$nearest=$tile;}}}
        if($nearest===null){return 'Kein geladener Mobspawner innerhalb von 25 Bloecken.';}
        $p=$nearest->getPosition();$plot=$this->plugin->getPlotAt($p);return \BetterPlots\Text::defer('Spawner {position} | Steuerung: {control} | Typ: {type} | Flag: {flag} | {status}',['position'=>$this->key($p),'control'=>\BetterPlots\Text::ref($nearest instanceof PlotSpawnerTile?'SplashGames':'noch nativ'),'type'=>$nearest->getEntityTypeId(),'flag'=>\BetterPlots\Text::ref($this->plugin->flag($plot,$p->getWorld()->getFolderName(),'mob-spawner',false)?'an':'aus'),'status'=>\BetterPlots\Text::ref($this->status[$this->key($p)]??'wartet')]);
    }
}
