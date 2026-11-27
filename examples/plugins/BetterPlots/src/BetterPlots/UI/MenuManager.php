<?php
declare(strict_types=1);
namespace BetterPlots\UI;

use BetterPlots\Main;
use pocketmine\event\Listener;
use pocketmine\event\inventory\{InventoryCloseEvent,InventoryTransactionEvent};
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\block\{VanillaBlocks,Air};
use pocketmine\item\StringToItemParser;
use pocketmine\scheduler\ClosureTask;
use pocketmine\network\mcpe\protocol\{UpdateBlockPacket,BlockActorDataPacket};
use pocketmine\network\mcpe\protocol\types\{BlockPosition,CacheableNbt};
use pocketmine\nbt\tag\CompoundTag;

final class MenuManager implements Listener{
    private array $config=[];
    private array $sessions=[];

    private const OP_PAGES=['backups','social','admin'];
    private function opView(Player $p):bool{return $this->plugin->getServer()->isOp($p->getName());}
    public function __construct(private Main $plugin){$this->reload();}
    public function reload():void{
        $next=(new Config($this->plugin->getDataFolder().'menus.yml',Config::YAML))->getAll();
        if(!is_array($next['pages']??null)||!isset($next['pages']['main'])){throw new \InvalidArgumentException('menus.yml benötigt pages.main.');}
        $next['pages']['settings']=FlagMenu::definition($this->plugin->flags->all());$next['pages']['flags']=FlagMenu::definition($this->plugin->flags->all());foreach($next['pages'] as &$page){$page['entries']=array_values(array_filter($page['entries']??[],static fn(array $e):bool=>!preg_match('/drohn|drone/iu',(string)($e['label']??''))));foreach($page['entries'] as &$e){if(($e['command'][0]??'')==='unlink'){$e['command'][0]='unmerge';}}unset($e);}unset($page);$this->config=$next;
    }
    public function open(Player $p,string $mode='',string $page='main',int $offset=0):void{
        if(!$p->isConnected()){return;}
        if($page==='admin'&&!$this->plugin->admin($p)){$this->plugin->say($p,'Keine Adminrechte.');return;}
        if(in_array($page,self::OP_PAGES,true)&&!$this->opView($p)){$this->plugin->say($p,'Dieser Bereich ist nur für Admins mit OP-Rechten.');return;}
        if(!$this->plugin->ready()){$this->plugin->say($p,$this->plugin->loadingMessage());return;}
        if(!$this->plugin->getConfig()->getNested('ui.enabled',true)){$this->plugin->say($p,'Menüs sind deaktiviert. /p help');return;}
        $mode=$mode?:((string)$this->plugin->getConfig()->getNested('ui.default','form'));
        if(!in_array($mode,['form','chest'],true)){throw new \InvalidArgumentException('Menümodus: form oder chest.');}
        $definition=$this->config['pages'][$page]??null;
        if(in_array($page,['rank-border','rank-wall'],true)){
            $kind=substr($page,5);$definition=['title'=>$kind==='border'?'Citybuild | Raender':'Citybuild | Waende','description'=>'Freischaltungen gelten fuer deinen aktiven globalen Rang.','entries'=>[]];
            foreach(\BetterPlots\Domain\PlotStyles::STYLES[$kind] as $block=>$data){
                $allowed=\BetterPlots\Domain\RankPrivileges::allowed($p,$this->plugin->admin($p),$kind,$block);
                $rank=\BetterPlots\Domain\PlotStyles::rankName($data[1]);
                $definition['entries'][]=['label'=>\BetterPlots\Text::defer('{color}{material}
§7Ab {rank}{state}',['color'=>$allowed?'§a':'§c','material'=>\BetterPlots\Text::ref($data[0]),'rank'=>\BetterPlots\Text::ref($rank),'state'=>\BetterPlots\Text::ref($allowed?' | Frei':' | Gesperrt')]),'command'=>['set',$kind,$block],'icon'=>$block==='air'?'barrier':$block];
            }
        }
        if($definition===null){throw new \InvalidArgumentException('Menüseite fehlt.');}

        foreach(str_starts_with($page,'rank-')?[]:($definition['entries']??[]) as $index=>$entry){
            $cmd=$entry['command']??[];
            if(in_array($cmd[0]??'',['merge','unlink','unmerge'],true)){
                $definition['entries'][$index]['command']=[$cmd[0]==='merge'?'merge':'unmerge'];
                $definition['entries'][$index]['label']=$cmd[0]==='merge'?'§aPlots verbinden §7(nur OP)':'§eNachbarseite in Blickrichtung trennen';
                if($cmd[0]==='merge'){$definition['entries'][$index]['permission']='plots.admin.*';}
                $definition['entries'][$index]['fields']=[];
                $definition['entries'][$index]['plot-context']=false;
            }

            if(($cmd[0]??'')==='set'&&in_array($cmd[1]??'',['wall','border','outline'],true)){
                $kind=$cmd[1]==='wall'?'wall':'border';
                $definition['entries'][$index]=['label'=>$kind==='wall'?'§bWand gestalten':'§bRand gestalten','page'=>'rank-'.$kind,'permission'=>'plots.set','icon'=>$kind==='wall'?'dirt':'stone_slab'];
            }
        }

        if($page==='admin'){foreach(\BetterPlots\World\RoadDesign::STYLES as $style=>$label){$definition['entries'][]=['label'=>\BetterPlots\Text::defer('§6Straßenstil: {style}
§7Alle Straßen dieser Welt umbauen',['style'=>\BetterPlots\Text::ref($label)]),'command'=>['roadstyle',$style],'plot-context'=>false,'permission'=>'plots.admin.*','icon'=>'dark_oak_planks'];}}
        $definition=Locale::definition($p,$definition);
        if($page==='backups'){
            $actor=$this->plugin->actor($p);
            foreach([['Position 1 setzen',['schematic','pos1']],['Position 2 setzen',['schematic','pos2']],['Strassenvorlage speichern',['schematic','create']]] as [$label,$cmd]){$entry=['label'=>Locale::text($p,$label),'command'=>$cmd,'plot-context'=>false,'permission'=>'plots.admin.*','icon'=>'stone_slab'];if($cmd[1]==='create'){$entry['fields']=[['label'=>Locale::text($p,'Vorlagenname')]];}$definition['entries'][]=$entry;}
            foreach($this->plugin->repo->all('bp/snapshot-index/'.$actor.'/') as $index){
                $snapshot=$this->plugin->repo->get('bp/snapshot/'.$index['snapshot']);
                if($snapshot===null||!($snapshot['complete']??false)||($snapshot['owner']??null)!==$actor){continue;}
                $definition['entries'][]=['label'=>\BetterPlots\Text::renderDeferred($p,\BetterPlots\Text::defer('§f{name}
§7{kind} · {date}',['name'=>$snapshot['name'],'kind'=>($snapshot['kind']??'')==='road'?\BetterPlots\Text::ref('Strassenvorlage'):\BetterPlots\Text::ref(\BetterPlots\Text::defer('{count} Plot(s)',['count'=>count($snapshot['cells'])])),'date'=>date('d.m. H:i',$snapshot['created'])])),'snapshot'=>$snapshot['name'],'permission'=>'plots.schematic','icon'=>'grass'];
            }
        }
        $entries=[];
        foreach($definition['entries']??[] as $entry){
            if(!($entry['enabled']??true)){continue;}
            if(($entry['command'][0]??'')==='buy'||(($entry['command'][0]??'')==='flag'&&($entry['command'][2]??'')==='price')){continue;}
            if(in_array($entry['page']??'',self::OP_PAGES,true)&&!$this->opView($p)){continue;}
            $permission=$entry['permission']??(isset($entry['command'][0])?'plots.'.$entry['command'][0]:'plots.use');
            if(!$this->plugin->admin($p)&&!$p->hasPermission($permission)){continue;}
            $entries[]=$entry;
        }
        $total=count($entries);$offset=max(0,min($offset,max(0,$total-1)));$visible=array_slice($entries,$offset,21);
        if($page!=='main'){$visible[]=['label'=>Locale::text($p,'§7« Hauptmenü'),'page'=>'main','icon'=>'compass'];}
        if($offset>0){$visible[]=['label'=>Locale::text($p,'§e« Zurück'),'page'=>$page,'offset'=>max(0,$offset-21),'icon'=>'paper'];}
        if($offset+21<$total){$visible[]=['label'=>Locale::text($p,'§eWeiter »'),'page'=>$page,'offset'=>$offset+21,'icon'=>'paper'];}
        if($page==='main'){$visible[]=['label'=>$mode==='form'?Locale::text($p,'§bTruhenansicht'):Locale::text($p,'§bFormularansicht'),'page'=>'main','mode'=>$mode==='form'?'chest':'form','icon'=>'chest'];}
        $title=(string)($definition['title']??'BetterPlots');
        $plot=$this->plugin->getPlotAt($p->getPosition());
        $content=(string)($definition['description']??'');
        $content.="\n\n".($plot===null?Locale::text($p,'§7Straße oder freies Grundstück'):'§a'.$plot['world'].' · '.$plot['cells'][0].' §7| '.$plot['ownerName']);
        if($this->plugin->hasBuildBypass($p)){$content.=Locale::text($p,"\n§cBau-Bypass aktiv");}
        if($this->plugin->edits->locked($p->getWorld()->getFolderName(),$p->getPosition()->getFloorX(),$p->getPosition()->getFloorZ())){$content.=Locale::text($p,"\n§eBearbeitung läuft – kurz warten.");}
        $content.="\n".Locale::text($p,$this->plugin->edits->status($p->getWorld()->getFolderName(),$p->getPosition()->getFloorX(),$p->getPosition()->getFloorZ()));
        $select=function(Player $p,int $i)use($visible,$mode):void{$this->select($p,$visible[$i],$mode);};
        if($mode==='chest'&&$this->plugin->getConfig()->getNested('ui.chest-enabled',true)&&$this->chest($p,$title,$visible,$select)){return;}
        $this->close($p);
        $p->sendForm(new PlotForm($title,$content,array_column($visible,'label'),$select));
    }
    private function select(Player $p,array $entry,string $mode):void{
        try{
            if(isset($entry['page'])){$this->open($p,$entry['mode']??$mode,$entry['page'],(int)($entry['offset']??0));return;}
            $command=$entry['command']??[];
            if(!isset($entry['snapshot'])&&(!is_array($command)||$command===[])){throw new \InvalidArgumentException('Menüaktion hat keinen Befehl.');}

            $prefix=[];$world=$p->getWorld()->getFolderName();
            if(($entry['plot-context']??true)&&$this->plugin->managed($world)){
                $plot=$this->plugin->getPlotAt($p->getPosition());
                $id=$plot['cells'][0]??$this->plugin->grid($world)->locate($p->getPosition()->getFloorX(),$p->getPosition()->getFloorZ())?->key();
                if($id!==null){$prefix=[$world.';'.$id];}
            }
            if(isset($entry['snapshot'])){$this->snapshotActions($p,(string)$entry['snapshot'],$prefix,$mode);return;}
            $run=function(Player $p,array $args)use($prefix,$command):void{$this->plugin->executePlot($p,[...$prefix,...$command,...$args]);};
            $fields=$entry['fields']??[];
            if(($command[0]??'')==='flag'&&($command[1]??'')==='set'){
                $name=$command[2];$current=$this->plugin->flag($plot??null,$world,$name,['redstone'=>true,'pvp'=>false,'mob-spawner'=>false,'mob-combat'=>'all','fly'=>false][$name]??null);$fields=FlagMenu::currentFields($fields,$current);
            }
            if(($command[0]??'')==='flag'&&($command[1]??'')==='set'&&($command[2]??'')==='mob-combat'){
                $this->close($p);
                $p->sendForm(new PlotForm('Mobs angreifen',\BetterPlots\Text::defer('Aktuell: {mode}
Spieler-PvP bleibt separat.',['mode'=>\BetterPlots\Text::ref(($current??'all')==='owner'?'Nur Plot-Besitzer':'Alle')]),['Alle','Nur Plot-Besitzer'],function(Player $p,int $i)use($run):void{if($i===0||$i===1){$run($p,[$i===0?'all':'owner']);}}));return;
            }
            foreach($fields as &$field){if(($field['source']??'')==='flags'){$field['options']=array_keys(array_filter($this->plugin->flags->all(),static fn(string $type):bool=>$type!=='Intern'));}}unset($field);
            if($fields===[]){$run($p,[]);}else{$p->sendForm(new InputForm((string)$entry['label'],$fields,$run,$p));}
        }catch(\Throwable $e){$this->plugin->say($p,$e->getMessage());}
    }
    private function snapshotActions(Player $p,string $name,array $prefix,string $mode):void{
        $id=$this->plugin->repo->get('bp/snapshot-index/'.$this->plugin->actor($p).'/'.$name)['snapshot']??null;
        $snapshot=$id===null?null:$this->plugin->repo->get('bp/snapshot/'.$id);
        if($snapshot===null){$this->plugin->say($p,'Dieses Bauwerk wurde entfernt. Bitte Liste aktualisieren.');return;}
        if(($snapshot['kind']??'')==='road'){
            $target=$p->getWorld()->getFolderName();$labels=[\BetterPlots\Text::renderDeferred($p,\BetterPlots\Text::defer('Strassen in {world} ersetzen',['world'=>$target])),Locale::text($p,'Vorlage exportieren'),Locale::text($p,'Vorlage loeschen'),Locale::text($p,'Zurueck')];
            $select=function(Player $p,int $i)use($name,$target,$mode,$id):void{if($i===3){$this->open($p,$mode,'backups');return;}if(($this->plugin->repo->get('bp/snapshot-index/'.$this->plugin->actor($p).'/'.$name)['snapshot']??null)!==$id){return;}$this->plugin->executePlot($p,$i===0?['load',$target,$name]:['schematic',$i===1?'export':'delete',$name]);};
            if($mode==='chest'&&$this->chest($p,$name,array_map(static fn($label)=>['label'=>$label,'icon'=>'stone_slab'],$labels),$select)){return;}
            $this->close($p);$p->sendForm(new PlotForm($name,Locale::text($p,'Strassenvorlage: nur oeffentliche Strassen; Plotbauten und Merge-Flaechen bleiben bestehen.'),$labels,$select));return;
        }
        $labels=array_map(fn(string $label)=>Locale::text($p,$label),['§aAuf ausgewähltem Plot einsetzen','§bAls .bplot-Datei exportieren','§cGespeichertes Bauwerk löschen','§7Zurück zur Liste']);
        $select=function(Player $p,int $i)use($name,$prefix,$mode,$id):void{
            if($i===3){$this->open($p,$mode,'backups');return;}
            if(($this->plugin->repo->get('bp/snapshot-index/'.$this->plugin->actor($p).'/'.$name)['snapshot']??null)!==$id){$this->plugin->say($p,'Auswahl veraltet. Bitte Liste erneut öffnen.');return;}
            $this->plugin->executePlot($p,[...$prefix,'schematic',['paste','export','delete'][$i],$name]);
        };
        if($mode==='chest'){
            $entries=array_map(static fn(string $label):array=>['label'=>$label,'icon'=>'paper'],$labels);
            if($this->chest($p,'§b'.$name,$entries,$select)){return;}
        }
        $this->close($p);
        $p->sendForm(new PlotForm('§l§b'.$name,\BetterPlots\Text::renderDeferred($p,\BetterPlots\Text::defer('§7Quelle: {world} | {kind}
§7Ziel: {target}

§eEinsetzen ersetzt das gesamte Ziel einschließlich Luft.
§7Quell- und Zielgruppe benötigen dieselbe Form und Plotgröße.',['world'=>$snapshot['world'],'kind'=>($snapshot['kind']??'')==='road'?\BetterPlots\Text::ref('Strassenvorlage'):\BetterPlots\Text::ref(\BetterPlots\Text::defer('{count} Plot(s)',['count'=>count($snapshot['cells'])])),'target'=>$prefix[0]??\BetterPlots\Text::ref('aktuelles Plot / gesetztes Target')])),$labels,$select));
    }
    private function sendBlock(Player $p,Position $pos,int $state):void{
        $runtime=$p->getNetworkSession()->getTypeConverter()->getBlockTranslator()->internalIdToNetworkId($state);
        $p->getNetworkSession()->sendDataPacket(UpdateBlockPacket::create(BlockPosition::fromVector3($pos),$runtime,UpdateBlockPacket::FLAG_ALL,0));
    }
    private function chest(Player $p,string $title,array $entries,\Closure $select):bool{
        $this->close($p);$world=$p->getWorld();$x=$p->getPosition()->getFloorX();$z=$p->getPosition()->getFloorZ();$pos=null;
        for($dy=2;$dy<=4;++$dy){$y=$p->getPosition()->getFloorY()+$dy;if($y<$world->getMaxY()&&$world->getBlockAt($x,$y,$z) instanceof Air){$pos=new Position($x,$y,$z,$world);break;}}
        if($pos===null){return false;}
        $inventory=new MenuInventory($pos);
        foreach($entries as $slot=>$entry){$item=StringToItemParser::getInstance()->parse($entry['icon']??'paper')??VanillaBlocks::STONE()->asItem();$item->setCustomName((string)$entry['label']);$item->setLore([Locale::text($p,(string)($entry['hint']??'Antippen zum Auswählen'))]);$inventory->setItem($slot,$item);}
        $key=$p->getUniqueId()->toString();$this->sessions[$key]=['player'=>$p,'inventory'=>$inventory,'select'=>$select,'count'=>count($entries),'pending'=>true];
        $this->sendBlock($p,$pos,VanillaBlocks::CHEST()->getStateId());
        $tag=CompoundTag::create()->setString('id','Chest')->setInt('x',$x)->setInt('y',$pos->getFloorY())->setInt('z',$z)->setString('CustomName',$title);
        $p->getNetworkSession()->sendDataPacket(BlockActorDataPacket::create(BlockPosition::fromVector3($pos),new CacheableNbt($tag)));
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function()use($p,$key,$inventory):void{
            if(!$p->isConnected()||($this->sessions[$key]['inventory']??null)!==$inventory){return;}
            $this->sessions[$key]['pending']=false;
            if(!$p->setCurrentWindow($inventory)){$this->close($p);$this->open($p,'form');}
        }),2);return true;
    }
    /**
     * @priority HIGHEST
     * @handleCancelled
     */
    public function transaction(InventoryTransactionEvent $e):void{
        $p=$e->getTransaction()->getSource();$key=$p->getUniqueId()->toString();$session=$this->sessions[$key]??null;
        foreach($e->getTransaction()->getActions() as $action){
            if(!$action instanceof SlotChangeAction||!$action->getInventory() instanceof MenuInventory){continue;}
            $e->cancel();
            if($session===null||$session['pending']||$session['inventory']!==$action->getInventory()||$action->getSlot()>=$session['count']){continue;}
            $this->sessions[$key]['pending']=true;$slot=$action->getSlot();$select=$session['select'];
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function()use($p,$key,$session,$slot,$select):void{
                if(!$p->isConnected()||($this->sessions[$key]['inventory']??null)!==$session['inventory']){return;}
                $this->close($p);$select($p,$slot);
            }),1);break;
        }
    }
    public function closed(InventoryCloseEvent $e):void{
        if($e->getInventory() instanceof MenuInventory){$this->restore($e->getPlayer(),$e->getInventory());}
    }
    private function restore(Player $p,MenuInventory $inventory):void{
        $key=$p->getUniqueId()->toString();if(($this->sessions[$key]['inventory']??null)!==$inventory){return;}unset($this->sessions[$key]);
        $pos=$inventory->getHolder();
        if($p->isConnected()&&$p->getWorld()===$pos->getWorld()&&$pos->getWorld()->isChunkLoaded($pos->getFloorX()>>4,$pos->getFloorZ()>>4)){$this->sendBlock($p,$pos,$pos->getWorld()->getBlockAt($pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ())->getStateId());}
    }
    public function close(Player $p):void{
        $inventory=$this->sessions[$p->getUniqueId()->toString()]['inventory']??null;
        if($inventory!==null){if($p->getCurrentWindow()===$inventory){$p->removeCurrentWindow();}$this->restore($p,$inventory);}
    }
    public function quit(PlayerQuitEvent $e):void{$this->close($e->getPlayer());$this->plugin->forgetBypass($e->getPlayer());}
    public function teleport(EntityTeleportEvent $e):void{if($e->getEntity() instanceof Player){$this->close($e->getEntity());}}
    public function closeAll():void{foreach($this->sessions as $session){$this->close($session['player']);}}
}
