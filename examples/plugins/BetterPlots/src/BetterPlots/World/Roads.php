<?php
declare(strict_types=1);
namespace BetterPlots\World;
use BetterPlots\Main;
use BetterPlots\Domain\Topology;
use pocketmine\event\Listener;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\world\World;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\item\StringToItemParser;

final class Roads implements Listener, \pocketmine\world\ChunkLoader{
    private array $state=[],$pending=[],$done=[],$decoded=[],$cursor=[];
    private int $ticks=0;
    private array $progress=[],$noticeTick=[];
    private int $work=0;
    public function __construct(private Main $p){}
    public function rebuilding(string $world):bool{return (bool)($this->p->repo->get('bp/road-template/'.$world)['rebuilding']??false);}
    public function status(string $world):string{
        $t=$this->p->repo->get('bp/road-template/'.$world);
        if($t===null){return \BetterPlots\Text::defer('Keine Strassenvorlage aktiv: {world}',['world'=>$world]);}
        if($t['paused']??false){return \BetterPlots\Text::defer('Umbau pausiert, Welt freigegeben: {error}. Erneut starten: /p roadretry {world}',['error'=>\BetterPlots\Text::ref(self::errorText($t)),'world'=>$world]);}
        $snapshot=isset($t['style'])?null:$this->p->repo->get('bp/snapshot/'.$t['snapshot']);
        $geometry=isset($t['style'])?\BetterPlots\Text::defer(' | Stil: {style}',['style'=>\BetterPlots\Text::ref(RoadDesign::STYLES[$t['style']]??$t['style'])]):($snapshot===null?'':\BetterPlots\Text::defer(' | Vorlage: {world} | Auswahl {selection} | Boden {from} -> {to}',['world'=>$snapshot['world']??'?','selection'=>implode(',', $snapshot['selection']),'from'=>$snapshot['base-height']??'?','to'=>$this->p->settings($world)['preset']['height']??64]));
        return \BetterPlots\Text::defer('{state}{world} | {done} Chunks fertig | {pending} vorgemerkt{search}{geometry}',['state'=>\BetterPlots\Text::ref($this->rebuilding($world)?'Umbau laeuft: ':'Strassenvorlage aktiv: '),'world'=>$world,'done'=>count($this->done[$world]??[]),'pending'=>count($this->pending[$world]??[]),'search'=>\BetterPlots\Text::ref(($this->state[$world]['finished']??false)?' | Weltsuche abgeschlossen':' | Weltsuche laeuft'),'geometry'=>\BetterPlots\Text::ref($geometry)]);
    }
    private static function errorText(array $task):string{
        if(is_array($task['error_locale']??null)){
            try{return \BetterPlots\Text::import($task['error_locale']);}catch(\InvalidArgumentException){}
        }
        $text=(string)($task['error']??'Abgebrochen');

        if(preg_match('/^Kein Fortschritt: Chunk (-?[0-9]+:-?[0-9]+) ist durch einen anderen Auftrag gesperrt\.$/D',$text,$m)){
            return \BetterPlots\Text::defer('Kein Fortschritt: Chunk {v1} ist durch einen anderen Auftrag gesperrt.',['v1'=>$m[1]]);
        }
        return $text;
    }
    public function cancel(string $world,string $reason='Durch Admin abgebrochen'):void{
        $t=$this->p->repo->get('bp/road-template/'.$world)??throw new \RuntimeException('Kein Strassenauftrag fuer diese Welt.');
        $w=$this->p->getServer()->getWorldManager()->getWorldByName($world);
        if($w!==null){foreach($this->cursor[$world]??[] as $key=>$offset){[$x,$z]=array_map('intval',explode(':',$key));$w->unregisterChunkLoader($this,$x,$z);}}
        unset($this->state[$world],$this->pending[$world],$this->cursor[$world]);
        $t['rebuilding']=false;$t['paused']=true;$t['error']=$reason;$t['error_locale']=\BetterPlots\Text::export($reason);
        $this->p->plots->commit(['bp/road-template/'.$world=>$t],function(bool $ok)use($world,$reason):void{
            if($ok){foreach($this->p->getServer()->getOnlinePlayers() as $player){$this->p->say($player,\BetterPlots\Text::defer('Strassenumbau pausiert: {v1} | {v2} | Welt freigegeben. Bereits gesetzte Strassen bleiben erhalten. /p roadretry {v3}',['v1'=>$world,'v2'=>$reason,'v3'=>$world]));}}
        });
    }
    public function retry(string $world,\pocketmine\command\CommandSender $sender):void{
        $t=$this->p->repo->get('bp/road-template/'.$world)??throw new \RuntimeException('Keine Vorlage fuer diese Welt.');
        if(isset($t['style'])){$this->activateStyle($world,$t['style'],$sender);return;}
        $this->activate($world,$t['snapshot'],$sender);
    }
    public function activate(string $world,string $snapshot,\pocketmine\command\CommandSender $sender):void{
        if($this->rebuilding($world)){throw new \RuntimeException('Diese Plotwelt wird bereits umgebaut.');}
        $wm=$this->p->getServer()->getWorldManager();
        $wm->loadWorld($world);$target=$wm->getWorldByName($world);
        $fallback=$wm->getDefaultWorld();
        if($target===null||$fallback===null||$target===$fallback){throw new \RuntimeException('Fuer den Umbau muss eine andere Hauptwelt als sicherer Wartebereich eingerichtet sein.');}
        foreach([...$this->p->repo->all('bp/job/'),...$this->p->repo->all('bp/capture/')] as $job){if($job['world']===$world){throw new \RuntimeException('Zuerst die laufende Speicherung/Bearbeitung abschliessen lassen.');}}
        $record=$this->p->repo->get('bp/snapshot/'.$snapshot)??throw new \RuntimeException('Vorlage fehlt.');
        if(($record['road-layout']??0)<2){throw new \RuntimeException('Alte Strassenvorlage: bitte mit /p1, /p2 und /p schematic create NEUERNAME neu aufnehmen.');}
        $g=$this->p->grid($world);$source=$record['source-grid']??null;
        if($source!==null&&($source['size']!==$g->size||$source['road']!==$g->road)){throw new \RuntimeException('Vorlage und Zielwelt brauchen dieselbe Plot- und Strassenbreite.');}
        $previous=$this->p->repo->get('bp/road-template/'.$world);
        $old=$previous===null?null:$this->p->repo->get('bp/snapshot/'.$previous['snapshot']);
        $clearLow=min($record['selection'][1]-$record['base-height'],$previous['clear-low']??PHP_INT_MAX,$old===null?PHP_INT_MAX:$old['selection'][1]-$old['base-height']);
        $clearHigh=max($record['selection'][4]-$record['base-height'],$previous['clear-high']??PHP_INT_MIN,$old===null?PHP_INT_MIN:$old['selection'][4]-$old['base-height']);
        $target->save(true);
        $this->p->plots->commit(['bp/road-template/'.$world=>['snapshot'=>$snapshot,'revision'=>bin2hex(random_bytes(8)),'rebuilding'=>true,'clear-low'=>$clearLow,'clear-high'=>$clearHigh]],function(bool $ok)use($sender,$world):void{
            $this->progress[$world]=$this->ticks;
            $this->p->say($sender,$ok?\BetterPlots\Text::defer('Strassenumbau gestartet: {v1}. Bitte bis zur Fertigmeldung warten.',['v1'=>$world]):'Strassenumbau konnte nicht gespeichert werden.');
        });
    }

    public function activateStyle(string $world,string $style,\pocketmine\command\CommandSender $sender):void{
        if($this->rebuilding($world)){throw new \RuntimeException('Diese Plotwelt wird bereits umgebaut.');}
        $wm=$this->p->getServer()->getWorldManager();$wm->loadWorld($world);$target=$wm->getWorldByName($world);$fallback=$wm->getDefaultWorld();
        if($target===null||$fallback===null||$target===$fallback){throw new \RuntimeException('Fuer den Umbau muss eine andere Hauptwelt als sicherer Wartebereich eingerichtet sein.');}
        RoadDesign::check($style,$this->p->grid($world));
        foreach([...$this->p->repo->all('bp/job/'),...$this->p->repo->all('bp/capture/')] as $job){if($job['world']===$world){throw new \RuntimeException('Zuerst die laufende Speicherung/Bearbeitung abschliessen lassen.');}}
        $previous=$this->p->repo->get('bp/road-template/'.$world);
        $old=$previous===null||isset($previous['style'])?null:$this->p->repo->get('bp/snapshot/'.$previous['snapshot']);
        $clearHigh=max(RoadDesign::TOP,$previous['clear-high']??0,$old===null?0:$old['selection'][4]-$old['base-height']);
        $target->save(true);
        $this->p->plots->commit(['bp/road-template/'.$world=>['style'=>$style,'snapshot'=>'','revision'=>bin2hex(random_bytes(8)),'rebuilding'=>true,'clear-low'=>0,'clear-high'=>$clearHigh]],function(bool $ok)use($sender,$world):void{
            $this->progress[$world]=$this->ticks;
            $this->p->say($sender,$ok?\BetterPlots\Text::defer('Strassenumbau gestartet: {v1}. Bitte bis zur Fertigmeldung warten.',['v1'=>$world]):'Strassenumbau konnte nicht gespeichert werden.');
        });
    }
    public function teleport(\pocketmine\event\entity\EntityTeleportEvent $event):void{
        if($event->getEntity() instanceof \pocketmine\player\Player&&$this->rebuilding($event->getTo()->getWorld()->getFolderName())){
            $event->cancel();$this->p->say($event->getEntity(),'Diese Plotwelt wird gerade umgebaut. Bitte warten.');
        }
    }
    private function evacuate(World $world):bool{
        $fallback=$this->p->getServer()->getWorldManager()->getDefaultWorld();
        if($fallback===null||$fallback===$world){return false;}
        foreach($world->getPlayers() as $player){
            if(!$this->p->teleportPlayer($player,$fallback->getSafeSpawn())){return false;}
            $this->p->say($player,'Die Plotstrassen werden erneuert. Nach der Fertigmeldung kannst du zurueck.');
        }
        return $world->getPlayers()===[];
    }
    private function finish(World $world,array $template):void{
        $name=$world->getFolderName();

        unset($this->state[$name]['scan']);
        $world->save(true);$wm=$this->p->getServer()->getWorldManager();
        if(!$wm->unloadWorld($world)){throw new \RuntimeException('Plotwelt konnte nach dem Umbau nicht entladen werden.');}
        if(!$wm->loadWorld($name)){throw new \RuntimeException('Plotwelt konnte nach dem Umbau nicht geladen werden.');}
        $template['rebuilding']=false;
        $this->p->plots->commit(['bp/road-template/'.$name=>$template],function(bool $ok)use($name):void{
            if($ok){foreach($this->p->getServer()->getOnlinePlayers() as $player){$this->p->say($player,\BetterPlots\Text::defer('Strassen fertig: {v1} wurde neu geladen und ist wieder betretbar.',['v1'=>$name]));}}
        });
    }
    public function load(ChunkLoadEvent $e):void{
        $w=$e->getWorld();if(!$this->p->ready()||!$this->p->managed($w->getFolderName())){return;}
        $this->pending[$w->getFolderName()][$e->getChunkX().':'.$e->getChunkZ()]=[$e->getChunkX(),$e->getChunkZ()];
    }
    public function populated(\pocketmine\event\world\ChunkPopulateEvent $e):void{
        $name=$e->getWorld()->getFolderName();if(!$this->p->managed($name)){return;}

        for($dx=-1;$dx<=1;++$dx){for($dz=-1;$dz<=1;++$dz){
            $x=$e->getChunkX()+$dx;$z=$e->getChunkZ()+$dz;$key=$x.':'.$z;
            unset($this->done[$name][$key]);

            if(isset($this->cursor[$name][$key])){$this->cursor[$name][$key]=0;}
            $this->pending[$name][$key]=[$x,$z];
        }}
    }
    public function close():void{
        foreach($this->p->getServer()->getWorldManager()->getWorlds() as $w){foreach($this->cursor[$w->getFolderName()]??[] as $key=>$offset){[$x,$z]=array_map('intval',explode(':',$key));$w->unregisterChunkLoader($this,$x,$z);}}
    }
    public function tick():void{
        if(!$this->p->ready()||$this->p->repo->busy()){return;}
        ++$this->ticks;
        $budget=max(1.0,min(12.0,(float)$this->p->getConfig()->getNested('queue.road-budget-ms',6.0)));
        $deadline=hrtime(true)+(int)($budget*1000000);
        for($batch=0;$batch<32&&hrtime(true)<$deadline;++$batch){
            $before=$this->work;$this->step($deadline);
            if($this->p->repo->busy()||$before===$this->work){break;}
        }
    }
    private function step(int $deadline):void{
        $wm=$this->p->getServer()->getWorldManager();
        foreach($this->p->repo->all('bp/road-template/') as $key=>$record){
            if(!($record['rebuilding']??false)){continue;}$world=substr($key,strlen('bp/road-template/'));
            if($wm->getWorldByName($world)===null){try{if(!$wm->loadWorld($world)){throw new \RuntimeException('Welt konnte nicht wieder geladen werden.');}}catch(\Throwable $e){$this->cancel($world,$e->getMessage());return;}}
        }
        $worlds=$wm->getWorlds();
        usort($worlds,fn(World $a,World $b)=>(int)$this->rebuilding($b->getFolderName())<=>(int)$this->rebuilding($a->getFolderName()));
        foreach($worlds as $w){
            $name=$w->getFolderName();if(!$this->p->managed($name)){continue;}
            try{
            $template=$this->p->repo->get('bp/road-template/'.$name);if($template['paused']??false){continue;}
            if($template!==null&&!isset($template['style'])&&($this->p->repo->get('bp/snapshot/'.$template['snapshot'])['road-layout']??0)<2){$this->cancel($name,'Alte Vorlage bitte mit /p1, /p2 und create unter neuem Namen aufnehmen');return;}if($template===null&&($this->p->settings($name)['legacy-preserve']??false)){continue;}$revision=$template['revision']??'slabs-052';
            if(($template['rebuilding']??false)&&!$this->evacuate($w)){if($this->ticks-($this->progress[$name]??=$this->ticks)>200){throw new \RuntimeException('Spieler konnte nicht in die Hauptwelt versetzt werden.');}continue;}
            if(!isset($this->state[$name])||$this->state[$name]['revision']!==$revision){
                $fullScan=(bool)($template['rebuilding']??false);
                $this->state[$name]=['revision'=>$revision,'scan'=>$fullScan?$w->getProvider()->getAllChunks(false):null,'finished'=>!$fullScan];$this->done[$name]=[];$this->decoded=[];$this->cursor[$name]=[];
                foreach($w->getLoadedChunks() as $hash=>$chunk){World::getXZ($hash,$x,$z);$this->pending[$name][$x.':'.$z]=[$x,$z];}
            }
            $state=&$this->state[$name];

            if(!$state['finished']&&count($this->pending[$name]??[])<32){if($state['scan']->valid()){$xy=$state['scan']->key();$this->pending[$name][$xy[0].':'.$xy[1]]=$xy;$state['scan']->next();++$this->work;}else{$state['finished']=true;++$this->work;}}
            if(($this->pending[$name]??[])===[]){if($state['finished']&&($template['rebuilding']??false)){$this->finish($w,$template);return;}continue;}
            $key=array_key_first($this->pending[$name]);[$cx,$cz]=$this->pending[$name][$key];unset($this->pending[$name][$key]);++$this->work;
            if(isset($this->done[$name][$key])){continue;}
            $before=$this->cursor[$name][$key]??0;
            $complete=false;
            do{
                $position=$this->cursor[$name][$key]??0;
                $complete=$this->apply($w,$cx,$cz,$template);

                if(!$complete&&($this->cursor[$name][$key]??0)===$position){break;}
            }while(!$complete&&hrtime(true)<$deadline);
            if($complete||($this->cursor[$name][$key]??0)!==$before){$this->progress[$name]=$this->ticks;}
            elseif($this->ticks-($this->progress[$name]??=$this->ticks)>1200){throw new \RuntimeException(\BetterPlots\Text::defer('Kein Fortschritt: Chunk {v1} ist durch einen anderen Auftrag gesperrt.',['v1'=>$key]));}
            if(($template['rebuilding']??false)&&$this->ticks%200===0&&($this->noticeTick[$name]??-1)!==$this->ticks){$this->noticeTick[$name]=$this->ticks;foreach($this->p->getServer()->getOnlinePlayers() as $player){$this->p->say($player,$this->status($name));}}
            if($complete){$this->done[$name][$key]=true;$w->unregisterChunkLoader($this,$cx,$cz);}else{$this->pending[$name][$key]=[$cx,$cz];}

            break;
            }catch(\Throwable $error){
                if($template!==null){$this->cancel($name,$error->getMessage());return;}
                throw $error;
            }finally{unset($state);}
        }
    }
    public function refresh(string $world):void{unset($this->state[$world]);}
    private function apply(World $w,int $cx,int $cz,?array $template):bool{
        $name=$w->getFolderName();
        $chunk=$w->loadChunk($cx,$cz);if($chunk===null){return true;}

        if($w->isChunkLocked($cx,$cz)){return false;}

        if(!$chunk->isPopulated()){return true;}
        $w->registerChunkLoader($this,$cx,$cz,false);
        $this->cursor[$name][$cx.':'.$cz]??=0;
        $g=$this->p->grid($name);$height=(int)($this->p->settings($name)['preset']['height']??64);
        $style=$template['style']??null;
        $snapshot=$template===null||$style!==null?null:$this->p->repo->get('bp/snapshot/'.$template['snapshot']);
        if($template!==null&&$style===null&&$snapshot===null){throw new \RuntimeException('Aktive Straßenvorlage fehlt.');}
        $parser=StringToItemParser::getInstance();$blocks=[];
        $slab=StringToItemParser::getInstance()->parse('stone_slab')->getBlock();
        $ck=$cx.':'.$cz;$start=$this->cursor[$name][$ck]??0;$end=min(256,$start+4);
        for($column=$start;$column<$end;++$column){$x=$cx*16+($column%16);$z=$cz*16+intdiv($column,16);
            if($this->p->edits->locked($name,$x,$z)){$this->cursor[$name][$ck]=$column;return false;}
            [,,$gx,$gz]=$g->cell($x,$z);$road=$gx>=$g->size||$gz>=$g->size;
            $pk=$this->p->plots->at($name,$g,$x,$z);$plot=$pk===null?null:$this->p->repo->get($pk);

            $rim=!$road&&min($gx,$gz,$g->size-1-$gx,$g->size-1-$gz)<4;
            if($style!==null&&$road&&$plot===null){

                for($dy=0;$dy<=max(RoadDesign::TOP,(int)($template['clear-high']??0));++$dy){
                    $y=$height+$dy;if($y>=$w->getMaxY()){break;}
                    $nameAt=$dy<=RoadDesign::TOP?(RoadDesign::block($style,$g,$x,$dy,$z)??'air'):'air';
                    $block=$blocks[$nameAt]??=($parser->parse($nameAt)?->getBlock()??VanillaBlocks::AIR());
                    if($w->getBlockAt($x,$y,$z)->getStateId()!==$block->getStateId()){$w->setBlockAt($x,$y,$z,$block,false);}
                }
                continue;
            }
            $layout2=($snapshot['road-layout']??0)>=2;
            $overhang=!$road&&min($gx,$gz,$g->size-1-$gx,$g->size-1-$gz)<4;
            if(($road||($layout2&&($rim||$overhang)))&&$plot===null&&$snapshot!==null){
                $b=$snapshot['selection'];$dy=$height-(int)$snapshot['base-height'];
                [$sx,,$sz]=RoadPattern::source($b,$x+($snapshot['source-grid']['offsetX']??$g->offsetX)-$g->offsetX,$height,$z+($snapshot['source-grid']['offsetZ']??$g->offsetZ)-$g->offsetZ,$dy);
                for($y=max($w->getMinY(),$height+($template['clear-low']??($b[1]-$snapshot['base-height'])));$y<=min($w->getMaxY()-1,$height+($template['clear-high']??($b[4]-$snapshot['base-height'])));++$y){
                    if($y<$b[1]+$dy||$y>$b[4]+$dy){if($y>$height){$air=VanillaBlocks::AIR();if($w->getBlockAt($x,$y,$z)->getStateId()!==$air->getStateId()){$w->setBlockAt($x,$y,$z,$air,false);}}continue;}
                    if(!$road&&!$rim&&$y<=$height+1){continue;}$sy=$y-$dy;$part=($sx>>4).';'.($sz>>4);$cache=$snapshot['snapshot'].'/'.$part;
                    if(!isset($this->decoded[$cache])){$record=$this->p->repo->get('bp/snapshot-chunk/'.$cache)??throw new \RuntimeException('Straßen-Chunk fehlt.');$this->decoded[$cache]=['data'=>SnapshotCodec::expand($record['rle'],256*($record['maxY']-$record['minY'])),'palette'=>array_map(static fn($v)=>SnapshotCodec::runtime($v,true),$record['palette']),'min'=>$record['minY'],'tiles'=>$record['tiles']??[]];}
                    $r=$this->decoded[$cache];$i=unpack('V',$r['data'],(($sy-$r['min'])*256+($sz&15)*16+($sx&15))*4)[1];if($i===0){continue;}
                    $block=RuntimeBlockStateRegistry::getInstance()->fromStateId($r['palette'][$i-1]);
                    if($w->getBlockAt($x,$y,$z)->getStateId()!==$block->getStateId()){$w->setBlockAt($x,$y,$z,$block,false);}
                    if(isset($r['tiles'][$sx.';'.$sy.';'.$sz])){$nbt=(new \pocketmine\nbt\LittleEndianNbtSerializer())->read(base64_decode($r['tiles'][$sx.';'.$sy.';'.$sz],true))->mustGetCompoundTag();$nbt->setInt('x',$x)->setInt('y',$y)->setInt('z',$z);$nbt->removeTag('pairx','pairz','pairlead');$tile=$w->getTileAt($x,$y,$z);if($tile!==null){$tile->readSaveData($nbt);if($tile instanceof \pocketmine\block\tile\Spawnable){$tile->setDirty();}}}
                }
            }
            $original=!($this->p->settings($name)['legacy-preserve']??false)&&!$road&&($gx===0||$gz===0||$gx===$g->size-1||$gz===$g->size-1);
            if($original&&$snapshot===null){
                $edge=$plot===null||!Topology::contains($g,$plot['cells'],$x-1,$z)||!Topology::contains($g,$plot['cells'],$x+1,$z)||!Topology::contains($g,$plot['cells'],$x,$z-1)||!Topology::contains($g,$plot['cells'],$x,$z+1);
                if(!$edge){continue;}$block=$plot===null||$this->p->flag($plot,$name,'border',true)?$slab:VanillaBlocks::AIR();
                $current=$w->getBlockAt($x,$height+1,$z);if(in_array($current->getTypeId(),[\pocketmine\block\BlockTypeIds::AIR,$slab->getTypeId(),VanillaBlocks::STONE_BRICKS()->getTypeId()],true)&&$current->getStateId()!==$block->getStateId()){$w->setBlockAt($x,$height+1,$z,$block,false);}
            }
        }
        if($end<256){$this->cursor[$name][$ck]=$end;return false;}unset($this->cursor[$name][$ck]);return true;
    }
}
