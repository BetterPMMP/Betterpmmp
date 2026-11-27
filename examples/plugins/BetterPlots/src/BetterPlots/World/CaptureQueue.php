<?php
declare(strict_types=1);
namespace BetterPlots\World;
use BetterPlots\Main;
use BetterPlots\Domain\Topology;
use pocketmine\world\{ChunkLoader,World};
use pocketmine\world\format\Chunk;
use pocketmine\nbt\{LittleEndianNbtSerializer,TreeRoot};

final class CaptureQueue implements ChunkLoader{
    private ?string $active=null;private ?array $job=null;
    private int $cx=0;private int $cz=0;private int $offset=0;private bool $waiting=false;
    private array $palette=[];private array $indices=[];private string $rle='';private int $last=-1;private int $run=0;private array $tiles=[];private array $parts=[];
    private ?World $held=null;
    private array $mask=[];
    public function __construct(private Main $plugin){}
    public function tick():void{
        if(!$this->plugin->ready()||$this->plugin->repo->busy()||$this->waiting){return;}
        if($this->active===null){$jobs=$this->plugin->repo->all('bp/capture/');if($jobs===[]){return;}$this->active=array_key_first($jobs);$this->job=$jobs[$this->active];$this->cx=$this->job['bounds'][0]>>4;$this->cz=$this->job['bounds'][1]>>4;$this->parts=[];}
        $job=$this->job;$wm=$this->plugin->getServer()->getWorldManager();$world=$wm->getWorldByName($job['world']);if($world===null){$wm->loadWorld($job['world']);return;}
        if($this->held===null){$this->held=$world;$world->registerChunkLoader($this,$this->cx,$this->cz,false);$this->waiting=true;$world->requestChunkPopulation($this->cx,$this->cz,$this)->onCompletion(function(Chunk $c):void{$this->waiting=false;},function():void{$this->waiting=false;$this->release();});return;}
        $chunk=$world->getChunk($this->cx,$this->cz);if($chunk===null){$this->release();return;}
        $grid=$this->plugin->grid($job['world']);$min=($job['kind']??'')==='road'?$job['selection'][1]:$world->getMinY();$max=($job['kind']??'')==='road'?$job['selection'][4]+1:$world->getMaxY();$volume=256*($max-$min);$end=hrtime(true)+(int)(max(0.1,min(10,(float)$this->plugin->getConfig()->getNested('queue.capture-budget-ms',1.5)))*1000000);
        while($this->offset<$volume&&hrtime(true)<$end){
            $i=$this->offset++;$lx=$i%16;$lz=intdiv($i,16)%16;$y=$min+intdiv($i,256);$x=$this->cx*16+$lx;$z=$this->cz*16+$lz;$index=0;
            if((($job['kind']??'')==='road'||($this->mask[$i%256]??=Topology::contains($grid,$job['cells'],$x,$z)))&&Selection::contains($job['selection']??null,$x,$y,$z)){
                $state=$chunk->getBlockStateId($lx,$y,$lz);
                if(!isset($this->indices[$state])){$this->indices[$state]=count($this->palette)+1;$this->palette[]=SnapshotCodec::state($state);}
                $index=$this->indices[$state];
            }
            if($index===$this->last){++$this->run;}else{if($this->run>0){$this->rle.=pack('V2',$this->last,$this->run);}$this->last=$index;$this->run=1;}
        }
        if($this->offset!==$volume){return;}
        $this->rle.=pack('V2',$this->last,$this->run);
        foreach($chunk->getTiles() as $tile){$pos=$tile->getPosition();if((($job['kind']??'')==='road'||Topology::contains($grid,$job['cells'],$pos->getFloorX(),$pos->getFloorZ()))&&Selection::contains($job['selection']??null,$pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ())){$this->tiles[$pos->getFloorX().';'.$pos->getFloorY().';'.$pos->getFloorZ()]=base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($tile->saveNBT())));}}
        $part=$this->cx.';'.$this->cz;$key='bp/snapshot-chunk/'.$job['snapshot'].'/'.$part;
        $record=['palette'=>$this->palette,'rle'=>base64_encode($this->rle),'tiles'=>$this->tiles,'minY'=>$min,'maxY'=>$max];
        $this->waiting=true;
        $this->plugin->repo->commit([$key=>$record],function(bool $ok)use($part):void{
            $this->waiting=false;if(!$ok){return;}$this->parts[]=$part;$this->release();$this->offset=0;$this->mask=[];$this->palette=$this->indices=$this->tiles=[];$this->rle='';$this->last=-1;$this->run=0;
            if(++$this->cz>($this->job['bounds'][3]>>4)){$this->cz=$this->job['bounds'][1]>>4;++$this->cx;}
            if($this->cx>($this->job['bounds'][2]>>4)){
                $manifest=$this->job;$manifest['parts']=$this->parts;$manifest['format']='BetterPlots/1';$manifest['complete']=true;unset($manifest['after']);
                $updates=$this->job['after']??[];$updates['bp/snapshot/'.$this->job['snapshot']]=$manifest;$updates[$this->active]=null;$this->waiting=true;
                $this->plugin->plots->commit($updates,function(bool $saved):void{$this->waiting=false;if($saved){$this->plugin->notifyJob($this->job,\BetterPlots\Text::defer('Bauwerk gespeichert: {name}. /schematic öffnet die Auswahl.',['name'=>$this->job['name']]));$this->active=null;$this->job=null;$this->plugin->getLogger()->info('Plot-Snapshot vollständig gespeichert.');}});
            }
        });
    }
    private function release():void{if($this->held!==null){$this->held->unregisterChunkLoader($this,$this->cx,$this->cz);$this->held=null;}}
    public function close():void{$this->release();}
}
