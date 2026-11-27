<?php
declare(strict_types=1);
namespace BetterPlots\World;

use BetterPlots\Main;
use BetterPlots\Domain\{PlotId,Topology};
use BetterPlots\Generator\PlotGenerator;
use pocketmine\event\Listener;
use pocketmine\event\world\WorldSaveEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\{ChunkLoader,World};
use pocketmine\world\format\Chunk;
use pocketmine\block\{RuntimeBlockStateRegistry,VanillaBlocks};

final class EditQueue implements ChunkLoader{
    private ?string $active=null;
    private ?array $job=null;
    private int $cx=0;private int $cz=0;private int $offset=0;
    private bool $waiting=false;private bool $awaitSave=false;private bool $finishing=false;
    private ?PlotGenerator $generator=null;

    private array $held=[];
    private array $decoded=[];
    private array $savedChunks=[];
    private array $columns=[];
    private int $planned=0;
    public function __construct(private Main $plugin){}
    public function locked(string $world,int $x,int $z):bool{
        foreach([...$this->plugin->repo->all('bp/job/'),...$this->plugin->repo->all('bp/capture/'),...$this->plugin->repo->all('bp/reservation/')] as $job){
            if($job['world']===$world&&$x>=$job['bounds'][0]&&$x<=$job['bounds'][2]&&$z>=$job['bounds'][1]&&$z<=$job['bounds'][3]){return true;}
        }return false;
    }

    public function definition(string $world,array $cells,string $mode,?string $block=null,array $finish=[]):array{
        $g=$this->plugin->grid($world);$xs=$zs=[];
        foreach($cells as $cell){$b=$g->bounds(PlotId::parse($cell));$xs[]=$b['minX'];$xs[]=$b['maxX'];$zs[]=$b['minZ'];$zs[]=$b['maxZ'];}
        $bounds=[min($xs),min($zs),max($xs),max($zs)];
        if($mode==='road'){$bounds=[max(-2147483648,$bounds[0]-$g->road),max(-2147483648,$bounds[1]-$g->road),min(2147483647,$bounds[2]+$g->road),min(2147483647,$bounds[3]+$g->road)];}
        $chunks=((($bounds[2]>>4)-($bounds[0]>>4))+1)*((($bounds[3]>>4)-($bounds[1]>>4))+1);
        if($chunks>(int)$this->plugin->getConfig()->getNested('queue.max-chunks',256)){throw new \InvalidArgumentException('Job überschreitet das konfigurierte Chunk-Limit.');}
        foreach([...$this->plugin->repo->all('bp/job/'),...$this->plugin->repo->all('bp/capture/'),...$this->plugin->repo->all('bp/reservation/')] as $job){if($job['world']===$world&&$bounds[0]<=$job['bounds'][2]&&$bounds[2]>=$job['bounds'][0]&&$bounds[1]<=$job['bounds'][3]&&$bounds[3]>=$job['bounds'][1]){throw new \RuntimeException('Diese Region wird bereits bearbeitet.');}}
        $job=['world'=>$world,'cells'=>$cells,'mode'=>$mode,'block'=>$block,'bounds'=>$bounds,'finish'=>$finish,'created'=>time()];
        if($mode==='merge'){$job['before-groups']=$this->mergeGroups($world,$cells);}
        return $job;
    }
    private function mergeGroups(string $world,array $cells):array{
        $groups=[];foreach($cells as $cell){$key=$this->plugin->plots->cell($world,$cell);if($key!==null){$plot=$this->plugin->repo->get($key);if($plot!==null){$groups[$key]=$plot['cells'];}}}
        if($groups===[]){throw new \RuntimeException('Merge-Ausgangsgruppen fehlen; Bearbeitung angehalten.');}
        return array_values($groups);
    }
    public function tick():void{
        if(!$this->plugin->ready()||$this->finishing){return;}
        if($this->active===null){
            $jobs=$this->plugin->repo->all('bp/job/');if($jobs===[]){return;}$this->active=array_key_first($jobs);$this->job=$jobs[$this->active];
            if($this->job['mode']==='merge'&&!isset($this->job['before-groups'])){$this->job['before-groups']=$this->mergeGroups($this->job['world'],$this->job['cells']);}
            $this->cx=$this->job['bounds'][0]>>4;$this->cz=$this->job['bounds'][1]>>4;$this->offset=0;$this->waiting=false;$this->awaitSave=false;
            $this->generator=new PlotGenerator(0,json_encode($this->plugin->settings($this->job['world'])['preset']??[],JSON_THROW_ON_ERROR));
        }
        $job=$this->job;if($job===null||$this->waiting){return;}
        if($this->awaitSave){$this->flush();return;}
        $world=$this->plugin->getServer()->getWorldManager()->getWorldByName($job['world']);
        if($world===null){$this->plugin->getServer()->getWorldManager()->loadWorld($job['world']);return;}
        $heldKey=$this->cx.':'.$this->cz;
        if(!isset($this->held[$heldKey])){
            $cx=$this->cx;$cz=$this->cz;$this->waiting=true;
            $world->registerChunkLoader($this,$cx,$cz,false);$this->held[$heldKey]=[$world,$cx,$cz];
            $world->requestChunkPopulation($cx,$cz,$this)->onCompletion(function(Chunk $chunk):void{$this->waiting=false;},function():void{$this->waiting=false;$this->plugin->getLogger()->error('Chunk konnte nicht geladen werden; Job bleibt erhalten.');});return;
        }
        if($world->getChunk($this->cx,$this->cz)===null){$world->unregisterChunkLoader($this,$this->cx,$this->cz);unset($this->held[$heldKey]);return;}
        if($world->isChunkLocked($this->cx,$this->cz)){return;}
        $g=$this->plugin->grid($job['world']);$height=(int)($this->plugin->settings($job['world'])['preset']['height']??64);
        $terrainMin=$world->getMinY();[$minY,$maxY]=EditSelection::heights($job['mode'],$terrainMin,$world->getMaxY(),$height);
        $end=hrtime(true)+(int)(max(0.1,min(10,(float)$this->plugin->getConfig()->getNested('queue.tick-budget-ms',2)))*1000000);
        $block=$job['block']===null?null:\pocketmine\item\StringToItemParser::getInstance()->parse($job['block'])?->getBlock();

        while($this->planned<256&&hrtime(true)<$end){
            $i=$this->planned++;$x=$this->cx*16+($i%16);$z=$this->cz*16+intdiv($i,16);
            $column=$job['mode']==='merge'?EditSelection::mergeColumn($g,$job['cells'],$job['before-groups'],$x,$z,$job['bounds']):($job['mode']==='unlink'&&isset($job['after-groups'])?EditSelection::unlinkColumn($g,$job['cells'],$job['after-groups'],$x,$z,$job['bounds']):EditSelection::column($g,$job['cells'],$x,$z,$job['mode'],$job['bounds']));
            if($column===null){continue;}
            if($job['mode']==='road'&&$this->plugin->plots->at($job['world'],$g,$x,$z)!==null){continue;}
            $this->columns[]=$column;
        }
        if($this->planned<256){return;}
        $step=$job['mode']==='biome'?4:1;
        $levels=(int)ceil(($maxY-$minY)/$step);$volume=count($this->columns)*$levels;
        $chunk=$world->getChunk($this->cx,$this->cz);
        while($this->offset<$volume&&hrtime(true)<$end){
            $i=$this->offset++;[$x,$z,$edge,$road,$originalEdge]=$this->columns[intdiv($i,$levels)];
            $y=$minY+($i%$levels)*$step;
            $mode=$job['mode'];$state=null;
            $tileData=null;
            if($mode==='restore'){
                $sx=$x-$job['dx'];$sz=$z-$job['dz'];$part=($sx>>4).';'.($sz>>4);if(!Selection::contains($job['selection']??null,$sx,$y,$sz)){continue;}
                if(!isset($this->decoded[$part])){
                    $record=$this->plugin->repo->get('bp/snapshot-chunk/'.$job['snapshot'].'/'.$part)??throw new \RuntimeException('Snapshot-Chunk fehlt.');
                    if(count($this->decoded)>=4){$this->decoded=[];}
                    $this->decoded[$part]=['data'=>SnapshotCodec::expand($record['rle'],256*($record['maxY']-$record['minY'])),'palette'=>array_map(SnapshotCodec::runtime(...),$record['palette']),'tiles'=>$record['tiles'],'minY'=>$record['minY'],'maxY'=>$record['maxY']];
                }
                $record=$this->decoded[$part];if($y<$record['minY']||$y>=$record['maxY']){continue;}
                $index=unpack('V', $record['data'],(($y-$record['minY'])*256+($sz&15)*16+($sx&15))*4)[1];
                if($index===0){continue;}$state=$record['palette'][$index-1]??throw new \RuntimeException('Beschädigter Palettenindex.');$tileData=$record['tiles'][$sx.';'.$y.';'.$sz]??null;
            }elseif($mode==='refill'){
                if($y>$height){continue;}$state=$this->generator->terrainState($x,$y,$z,$terrainMin,true,$edge);
            }elseif(in_array($mode,['clear','delete','merge','unlink'],true)){
                if(($mode==='merge'||$mode==='unlink')&&!$road&&!$originalEdge){continue;}
                if(($mode==='merge'||$mode==='unlink')&&$originalEdge&&$y>$height+1){continue;}

                if($mode==='merge'&&$originalEdge&&$y===$height){continue;}
                $individual=$mode==='delete'||$mode==='unlink';
                $state=$this->generator->terrainState($x,$y,$z,$terrainMin,$individual?!$road:true,$individual?$originalEdge:$edge);
            }elseif($mode==='biome'){
                if(($y-$minY)%4===0){$world->setBiomeId($x,$y,$z,(int)$job['block']);}continue;
            }else{
                $selected=match($mode){'road'=>$y===$height,'floor'=>$y===$height,'wall'=>$edge&&$y<=$height&&$y>$terrainMin,'border','outline'=>$edge&&$y===$height+1,'air'=>$y>$height,'main'=>$y>$terrainMin&&$y<$height,'middle'=>$x===intdiv($job['bounds'][0]+$job['bounds'][2],2)&&$z===intdiv($job['bounds'][1]+$job['bounds'][3],2),'all'=>true,default=>false};
                if(!$selected){continue;}$state=($mode==='air'?VanillaBlocks::AIR():$block)?->getStateId();
            }
            if($state!==null){
                $changed=$chunk->getBlockStateId($x&15,$y,$z&15)!==$state;

                if(!$changed&&($mode!=='restore'||($tileData===null&&$chunk->getTile($x&15,$y,$z&15)===null))){continue;}
                $world->getTileAt($x,$y,$z)?->close();
                $world->setBlockAt($x,$y,$z,RuntimeBlockStateRegistry::getInstance()->fromStateId($state),false);
                if($changed){$world->updateAllLight($x,$y,$z);}
                if($tileData!==null){
                    $nbt=(new \pocketmine\nbt\LittleEndianNbtSerializer())->read(base64_decode($tileData,true))->mustGetCompoundTag();
                    $nbt->setInt('x',$x)->setInt('y',$y)->setInt('z',$z);
                    if($nbt->getTag('pairx') instanceof \pocketmine\nbt\tag\IntTag&&$nbt->getTag('pairz') instanceof \pocketmine\nbt\tag\IntTag){
                        $px=$nbt->getInt('pairx')+$job['dx'];$pz=$nbt->getInt('pairz')+$job['dz'];
                        if(Topology::contains($g,$job['cells'],$px,$pz)){$nbt->setInt('pairx',$px)->setInt('pairz',$pz);}
                        else{$nbt->removeTag('pairx','pairz','pairlead');}
                    }
                    $tile=$world->getTileAt($x,$y,$z);if($tile!==null){$tile->readSaveData($nbt);if($tile instanceof \pocketmine\block\tile\Spawnable){$tile->setDirty();}}
                }
            }
        }
        if($this->offset===$volume){
            if($job['mode']==='biome'){foreach($world->getPlayers() as $p){$p->onChunkChanged($this->cx,$this->cz,$world->getChunk($this->cx,$this->cz));}}
            $this->offset=0;$this->columns=[];$this->planned=0;
            if(++$this->cz>($job['bounds'][3]>>4)){$this->cz=$job['bounds'][1]>>4;++$this->cx;}
            if($this->cx>($job['bounds'][2]>>4)){$this->awaitSave=true;$this->plugin->getLogger()->info('Plotjob angewendet; geänderte Chunks werden jetzt gespeichert.');}
        }
    }
    public function status(string $world,int $x,int $z):string{
        if(!$this->locked($world,$x,$z)){return '§aBereit zum Bauen';}
        $job=$this->job;
        if($job===null||$job['world']!==$world||$x<$job['bounds'][0]||$x>$job['bounds'][2]||$z<$job['bounds'][1]||$z>$job['bounds'][3]){return '§eAuftrag wartet / Sicherung läuft';}
        $nx=($job['bounds'][2]>>4)-($job['bounds'][0]>>4)+1;$nz=($job['bounds'][3]>>4)-($job['bounds'][1]>>4)+1;
        $total=$nx*$nz;
        if($this->awaitSave){return \BetterPlots\Text::defer('§eSpeichern: {done}/{total} Chunks',['done'=>count($this->savedChunks),'total'=>$total]);}
        $done=($this->cx-($job['bounds'][0]>>4))*$nz+$this->cz-($job['bounds'][1]>>4);
        return \BetterPlots\Text::defer('§b{mode} §7| {done}/{total} Chunks{state}',['mode'=>$job['mode'],'done'=>$done,'total'=>$total,'state'=>\BetterPlots\Text::ref($this->waiting?' §e· Laden':' §e· Bearbeiten')]);
    }
    private function flush():void{
        if($this->plugin->repo->busy()||$this->active===null||$this->finishing){return;}

        foreach($this->held as $key=>[$world,$cx,$cz]){
            if(isset($this->savedChunks[$key])){continue;}
            $chunk=$world->getChunk($cx,$cz);
            if($chunk===null){throw new \RuntimeException('Bearbeiteter Chunk ist vor dem Speichern verschwunden.');}
            $world->getProvider()->saveChunk($cx,$cz,new \pocketmine\world\format\io\ChunkData(
                $chunk->getSubChunks(),$chunk->isPopulated(),
                array_map(static fn(\pocketmine\entity\Entity $e)=>$e->saveNBT(),array_values(array_filter($world->getChunkEntities($cx,$cz),static fn(\pocketmine\entity\Entity $e):bool=>$e->canSaveWithChunk()))),
                array_map(static fn(\pocketmine\block\tile\Tile $t)=>$t->saveNBT(),array_values($chunk->getTiles()))
            ),$chunk->getTerrainDirtyFlags());
            $chunk->clearTerrainDirtyFlags();$this->savedChunks[$key]=true;return;
        }
        $updates=$this->job['finish'];$updates[$this->active]=null;$this->finishing=true;
        $this->plugin->plots->commit($updates,function(bool $ok):void{
            $this->finishing=false;if(!$ok){return;}
            foreach($this->held as [$world,$cx,$cz]){$world->unregisterChunkLoader($this,$cx,$cz);}
            if($this->job['mode']==='unlink'){$this->plugin->roads->refresh($this->job['world']);}
            $this->plugin->notifyJob($this->job,'Bearbeitung abgeschlossen und gespeichert. Du kannst wieder bauen.');
            $this->held=[];$this->decoded=[];$this->savedChunks=[];$this->job=null;$this->active=null;$this->awaitSave=false;
            $this->plugin->getLogger()->info('Plotjob gespeichert und Region entsperrt.');
        });
    }
    public function close():void{foreach($this->held as [$world,$cx,$cz]){if($world->isLoaded()){$world->unregisterChunkLoader($this,$cx,$cz);}}$this->held=[];}
}
