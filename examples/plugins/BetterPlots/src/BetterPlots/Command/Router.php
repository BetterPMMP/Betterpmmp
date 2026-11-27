<?php
declare(strict_types=1);
namespace BetterPlots\Command;

use BetterPlots\Main;
use BetterPlots\Domain\{Grid,PlotId,Topology,Access};
use BetterPlots\Service\Plots;
use BetterPlots\Protection\Guard;
use BetterPlots\Generator\PlotGenerator;
use BetterPlots\UI\PlotForm;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\{Position,WorldCreationOptions};
use pocketmine\math\Vector3;
use pocketmine\scheduler\ClosureTask;
use pocketmine\item\StringToItemParser;

final class Router{

    private array $confirmations=[];

    private array $searching=[];
    private string $confirmationLabel="";
    public function __construct(private Main $p){}
    private function permission(CommandSender $s,string $permission):void{if(!$this->p->admin($s)&&!$s->hasPermission($permission)){throw new \RuntimeException('Keine Berechtigung.');}}
    private function player(CommandSender $s):Player{return $s instanceof Player?$s:throw new \InvalidArgumentException('Dieser Befehl benötigt einen Spieler.');}
    private function integer(?string $value,int $min,int $max):int{$n=filter_var($value,FILTER_VALIDATE_INT);if($n===false||$n<$min||$n>$max){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Ganzzahl {min}..{max} erforderlich.',['min'=>$min,'max'=>$max]));}return $n;}
    private function confirm(CommandSender $s,\Closure $action):void{
        $seconds=max(5,min(120,(int)$this->p->getConfig()->getNested('commands.confirmation-seconds',30)));
        $uuid=$this->p->actor($s);$this->confirmations[$uuid]=[time()+$seconds,$action];
        $this->p->say($s,\BetterPlots\Text::defer('Mit /p confirm innerhalb von {v1} Sekunden bestätigen.',['v1'=>$seconds]));
        if($s instanceof Player&&$this->p->getConfig()->getNested('ui.confirmation-forms',true)){
            $s->sendForm(new \BetterPlots\UI\ConfirmForm(\BetterPlots\Text::t($s,'BetterPlots · Bestätigung'),\BetterPlots\Text::t($s,"Aktion: {action}\n\nDiese Änderung jetzt ausführen?",['action'=>$this->confirmationLabel]),function(Player $p,bool $yes)use($uuid,$action):void{
                if(($this->confirmations[$uuid][1]??null)!==$action){$this->p->say($p,'Diese Bestätigung ist nicht mehr aktuell.');return;}
                if(!$yes){unset($this->confirmations[$uuid]);return;}$this->p->executePlot($p,['confirm']);
            },$s));
        }
    }
    private function owner(CommandSender $s,string $key,bool $merge=false):array{
        $plot=$this->p->repo->get($key)??throw new \InvalidArgumentException('Plot existiert nicht mehr.');
        if($this->p->roads->rebuilding($plot['world'])){throw new \RuntimeException('Strassenumbau laeuft. Bitte warten.');}
        if(!$this->p->admin($s)&&!($merge&&$s->hasPermission('splash.plot.merge.others'))&&!in_array($this->p->actor($s),$plot['owners'],true)){throw new \RuntimeException('Nur der Owner darf diese Änderung ausführen.');}
        foreach($plot['cells'] as $id){$b=$this->p->grid($plot['world'])->bounds(PlotId::parse($id));if($this->p->edits->locked($plot['world'],$b['minX'],$b['minZ'])){throw new \RuntimeException('Plot wird gerade bearbeitet.');}}
        return $plot;
    }
    private function save(CommandSender $s,array $updates):void{
        $queued=false;
        foreach($updates as $key=>&$record){if($record!==null&&(str_starts_with($key,'bp/job/')||str_starts_with($key,'bp/capture/'))){$record['notify']=$this->p->actor($s);$queued=true;}}unset($record);
        $this->p->plots->commit($updates,function(bool $ok)use($s,$queued):void{$this->p->say($s,$ok?($queued?'Auftrag eingereiht. Fortschritt im Plot-Menü; die Region bleibt bis zum Abschluss geschützt.':'Gespeichert.'):'Speichern fehlgeschlagen; Zugriff vorsorglich gesperrt.');});
    }
    private function resolve(CommandSender $s,?string $prefix):array{
        $world=$s instanceof Player?$s->getWorld()->getFolderName():'plots';$id=null;
        if($prefix===null){
            $target=$this->p->repo->get('bp/preferences/'.$this->p->actor($s))['target']??null;
            $selected=is_string($target)?$this->p->repo->get($target):null;
            if($selected!==null){return [$selected['world'],$selected['cells'][0],$target];}
        }
        if($prefix!==null){$parts=explode(';',$prefix);if(count($parts)===3){$world=array_shift($parts);}$id=PlotId::parse(implode(';',$parts))->key();}
        $key=$id!==null?$this->p->plots->cell($world,$id):($s instanceof Player?$this->p->keyAt($s->getPosition()):null);
        return [$world,$id,$key];
    }
    private function go(Player $s,array $plot,bool $middle=false):void{
        if(!Access::enter($plot,$this->p->actor($s),$this->p->admin($s))){throw new \RuntimeException('Du darfst dieses Plot nicht betreten.');}
        $wm=$this->p->getServer()->getWorldManager();$wm->loadWorld($plot['world']);$world=$wm->getWorldByName($plot['world'])??throw new \RuntimeException('Welt nicht verfügbar.');
        $b=$this->p->grid($plot['world'])->bounds(PlotId::parse($plot['cells'][0]));$height=(int)($this->p->settings($plot['world'])['preset']['height']??64);
        $home=$middle?null:$plot['home'];$x=(float)($home[0]??($b['minX']+$b['maxX']+1)/2);$y=(float)($home[1]??$height+2);$z=(float)($home[2]??($b['minZ']+$b['maxZ']+1)/2);
        $world->requestChunkPopulation((int)floor($x)>>4,(int)floor($z)>>4,null)->onCompletion(function()use($s,$world,$x,$y,$z,$plot):void{
            if(!$s->isConnected()){return;}
            $key=$this->p->plots->cell($plot['world'],$plot['cells'][0]);$current=$key===null?null:$this->p->repo->get($key);
            if($current!==null&&Access::enter($current,$this->p->actor($s),$this->p->admin($s))){$this->p->teleportPlayer($s,new Position($x,$y,$z,$world));}
        },function()use($s):void{$this->p->say($s,'Chunk konnte nicht geladen werden.');});
    }
    public function run(CommandSender $s,array $args):void{
        $this->confirmationLabel='/p '.implode(' ',$args);
        if($s instanceof Player&&(($args===[]&&$this->p->getConfig()->getNested('ui.open-on-empty',true))||in_array(strtolower($args[0]??''),['menu','ui','gui'],true))){
            $this->permission($s,'plots.use');$this->p->menus->open($s,$args[1]??'');return;
        }

        $prefix=null;if(isset($args[0])&&str_contains($args[0],';')){$prefix=array_shift($args);}
        $cmd=strtolower(array_shift($args)??'help');
        if(in_array($cmd,['schmatic','schamtic'],true)){$cmd='schematic';}if($cmd==='unmerge'){$cmd='unlink';}if(in_array($cmd,['pos1','pos2','1','2'],true)){$args=[$cmd==='1'?'pos1':($cmd==='2'?'pos2':$cmd)];$cmd='schematic';}
        if(in_array($cmd,['rand','border','wand','wall'],true)){
            $kind=in_array($cmd,['rand','border'],true)?'border':'wall';
            $this->permission($s,'plots.set');
            if($args===[]){$this->p->menus->open($this->player($s),'','rank-'.$kind);return;}
            array_unshift($args,$kind);$cmd='set';
        }
        if($cmd==='confirm'){$this->permission($s,'plots.confirm');$entry=$this->confirmations[$this->p->actor($s)]??null;unset($this->confirmations[$this->p->actor($s)]);if($entry===null||$entry[0]<time()){throw new \RuntimeException('Keine gültige Bestätigung.');}$entry[1]();return;}
        if($cmd==='importcheck'){if(!$this->p->admin($s)){throw new \RuntimeException('Nur Admins duerfen den Weltimport pruefen.');}$world=$args[0]??($s instanceof Player?$s->getWorld()->getFolderName():'plot');$this->p->say($s,\BetterPlots\World\ExistingWorld::report($this->p,$world));return;}
        if(in_array($cmd,['roadstatus','roadcancel','roadretry'],true)){
            if(!$this->p->admin($s)){throw new \RuntimeException('Nur Admins duerfen Strassenauftraege verwalten.');}
            if(!$this->p->ready()){throw new \RuntimeException($this->p->loadingMessage());}
            $world=$args[0]??($s instanceof Player?$s->getWorld()->getFolderName():'');$this->p->settings($world);
            if($cmd==='roadcancel'){$this->p->roads->cancel($world);}elseif($cmd==='roadretry'){$this->p->roads->retry($world,$s);}else{$this->p->say($s,$this->p->roads->status($world));}return;
        }
        if($cmd==='spawnerstatus'){$player=$this->player($s);$this->p->say($s,$this->p->spawners->report($player->getPosition()));return;}
        $this->permission($s,'plots.'.$cmd);
        if($cmd==='help'){
            $text='/p setup <Welt> [Größe Straße Höhe] | /p auto | /p claim | /p home | /p visit <Spieler/ID/Alias>\n/p info | /p list | /p add/trust/deny/remove <Spieler> | /p flag list/set/remove\n/p merge [n/e/s/w] | /merge all Spielername | /p unmerge [n/e/s/w] | /p clear | /p delete | /p set <floor/wall/border/all/air> <Block>';
            $this->p->say($s,str_replace('\\n',"\n",$text));
            if($s instanceof Player&&($args[0]??'')==='gui'){$this->p->menus->open($s);}return;
        }
        if(!$this->p->ready()){throw new \RuntimeException($this->p->loadingMessage());}
        if($cmd==='schematic'&&in_array(strtolower($args[0]??''),['pos1','pos2','create'],true)){$this->selectionCommand($s,$args);return;}
        if($cmd==='load'&&count($args)===2){$this->loadRoad($s,$args[0],$args[1]);return;}
        if(in_array($cmd,['roadstyle','strassenstil','straßenstil'],true)){$this->roadStyle($s,$args);return;}
        if($cmd==='schematic'&&$s instanceof Player&&($args===[]||in_array($args[0],['ui','menu'],true))){$this->p->menus->open($s,$args[1]??'','backups');return;}
        if($cmd==='schematic'&&($args[0]??'')==='guide'){
            $this->p->say($s,\BetterPlots\Text::defer('Bereich: /p schematic pos1, /p schematic pos2, /p schematic create NAME. Laden: /p load WELT NAME.
Speichern: /p schematic → Aktuelles Plot speichern → Name.
Einsetzen: Auf eigenes Zielplot gehen, Bauwerk auswählen, Einsetzen bestätigen.
Export: Bauwerk auswählen → Export. Die Datei liegt im Pluginordner schematics/.
Import: Datei dort ablegen als {v1}-NAME.bplot, dann Datei importieren → NAME.
Nur BetterPlots .bplot; Java .schem/.schematic sind nicht kompatibel. Gleiche Plotgröße und Gruppenform erforderlich.',['v1'=>$this->p->actor($s)]));return;
        }
        if(in_array($cmd,['schematic','backup'],true)&&in_array($args[0]??'',['list','export','import','delete'],true)){$this->snapshot($s,'',[],$cmd,$args);return;}

        if($prefix===null&&$s instanceof Player&&in_array($cmd,['merge','unlink'],true)){
            $world=$s->getWorld()->getFolderName();$pos=$s->getPosition();
            [$cx,$cz]=$this->p->grid($world)->cell($pos->getFloorX(),$pos->getFloorZ());
            $prefix=$world.';'.$cx.';'.$cz;
        }
        [$world,$id,$key]=$this->resolve($s,$prefix);
        if($this->p->roads->rebuilding($world)){throw new \RuntimeException('Diese Plotwelt wird gerade umgebaut. Bitte warten.');}
        if($cmd==='bypass'){$enabled=$this->p->toggleBuildBypass($this->player($s));$this->p->say($s,$enabled?'Bau-Bypass AKTIV: Du kannst auch auf Straßen und fremden Plots bauen.':'Bau-Bypass AUS: Normale Grundstücksrechte gelten.');return;}
        if($cmd==='setup'){$this->setup($s,$args);return;}
        if($cmd==='plugin'){$this->p->say($s,\BetterPlots\Text::defer('BetterPlots {version} | API 5.51.0 | SQLite/MySQL | {count} Plotgruppen',['version'=>$this->p->getDescription()->getVersion(),'count'=>count($this->p->repo->all('bp/plot/'))]));return;}
        if($cmd==='auto'){$this->auto($s,$world,$args);return;}
        if($cmd==='claim'){
            if($id===null){$pl=$this->player($s);$id=$this->p->grid($world)->locate($pl->getPosition()->getFloorX(),$pl->getPosition()->getFloorZ())?->key();}
            if($id===null){throw new \InvalidArgumentException('Du stehst auf einer Straße.');}$this->claim($s,$world,[$id]);return;
        }
        if(in_array($cmd,['home','visit'],true)){$this->visit($s,$cmd,$args,$prefix!==null?$key:null);return;}
        if($cmd==='list'){$this->listing($s,$world,$args);return;}
        if($cmd==='toggle'){$this->toggle($s,$args);return;}
        if($cmd==='area'){$this->area($s,$args);return;}
        if($cmd==='cluster'){$this->cluster($s,$world,$args);return;}
        if($cmd==='debugexec'){$this->debugExec($s,$args);return;}
        if($cmd==='grant'){
            if(!$this->p->admin($s)){throw new \RuntimeException('Keine Berechtigung.');}$uuid=$this->p->identity($args[0]??'');$count=$this->integer($args[1]??'1',1,255);$pk='bp/preferences/'.$uuid;$prefs=$this->p->repo->get($pk)??[];$prefs['grants']=min(255,($prefs['grants']??0)+$count);$this->save($s,[$pk=>$prefs]);return;
        }
        if($cmd==='condense'){
            $this->confirm($s,function()use($s):void{$this->p->database->submit(['op'=>'compact'],function(array $r)use($s):void{$this->p->say($s,($r['ok']??false)?'Datenbank komprimiert.':'Komprimierung fehlgeschlagen.');});});return;
        }
        if($cmd==='template'){$this->template($s,$args);return;}
        if($cmd==='purge'){$this->purge($s,$args);return;}
        if($cmd==='debugsavetest'||$cmd==='debugloadtest'){
            $probe='bp/diagnostic/'.bin2hex(random_bytes(8));$nonce=bin2hex(random_bytes(16));
            $this->p->repo->commit([$probe=>['nonce'=>$nonce]],function(bool $ok)use($s,$probe,$nonce):void{
                if(!$ok){$this->p->say($s,'Datenbanktest fehlgeschlagen.');return;}
                $this->p->database->submit(['op'=>'get','key'=>$probe],function(array $r)use($s,$probe,$nonce):void{
                    $data=json_decode($r['value']['payload']??'null',true);$this->p->say($s,($r['ok']??false)&&($data['nonce']??null)===$nonce?'Dauerhafter Schreib-/Lesetest bestanden.':'Datenbanktest fehlgeschlagen.');
                    if(!$this->p->repo->busy()){$this->p->repo->commit([$probe=>null],function(bool $ok):void{if(!$ok){$this->p->getLogger()->error('Diagnosedatensatz konnte nicht entfernt werden.');}});}
                });
            });return;
        }
        if(in_array($cmd,['debug','debugpaste','debugsavetest','debugloadtest','database'],true)){
            if($cmd==='database'&&isset($args[0])){throw new \RuntimeException('Treiberwechsel benötigt einen Offline-Datenexport; siehe README. Keine Live-Konvertierung.');}
            $this->p->sayRaw($s,json_encode(['api'=>$this->p->getServer()->getApiVersion(),'ready'=>$this->p->ready(),'plots'=>count($this->p->repo->all('bp/plot/')),'jobs'=>count($this->p->repo->all('bp/job/')),'dbBusy'=>$this->p->repo->busy()],JSON_THROW_ON_ERROR));return;
        }
        if($cmd==='reload'){if($this->p->repo->all('bp/job/')!==[]){throw new \RuntimeException('Während laufender Jobs kein Reload.');}$this->p->reloadConfig();$this->p->menus->reload();$this->p->say($s,'Konfiguration neu geladen. Weltgeometrie benötigt einen Neustart.');return;}
        if($key===null){throw new \InvalidArgumentException('Hier ist kein beanspruchtes Plot.');}
        $plot=$this->p->repo->get($key);
        if($cmd==='info'||$cmd==='caps'){
            if(($plot['flags']['hide-info']??false)&&!$this->p->admin($s)&&!in_array($this->p->actor($s),$plot['owners'],true)){throw new \RuntimeException('Plotinformationen sind verborgen.');}
            $this->p->sayRaw($s,$plot['world'].' | '.implode(', ',$plot['cells']).' | Owner: '.$plot['ownerName'].' | Alias: '.$plot['alias'].' | '.$plot['description'].' | Flags: '.json_encode($plot['flags'],JSON_UNESCAPED_UNICODE));return;
        }
        if($cmd==='middle'){$this->go($this->player($s),$plot,true);return;}
        if($cmd==='target'){$prefs=$this->p->repo->get('bp/preferences/'.$this->p->actor($s))??[];$prefs['target']=($args[0]??'')==='off'?null:$key;$this->save($s,['bp/preferences/'.$this->p->actor($s)=>$prefs]);$this->p->say($s,'Befehlsziel gespeichert; /p target off hebt die Auswahl auf.');return;}
        if(in_array($cmd,['like','dislike','rate'],true)){
            $rating=match($cmd){'like'=>10,'dislike'=>0,default=>$this->integer($args[0]??null,0,10)};
            if(in_array($this->p->actor($s),$plot['owners'],true)){throw new \RuntimeException('Eigene Plots können nicht bewertet werden.');}
            $plot['ratings'][$this->p->actor($s)]=$rating;$this->save($s,[$key=>$plot]);return;
        }
        if($cmd==='comment'||$cmd==='inbox'){$this->comments($s,$key,$plot,$cmd,$args);return;}
        if($cmd==='buy'){throw new \RuntimeException('Plots kaufen ist vorübergehend deaktiviert.');}
        $plot=$this->owner($s,$key,in_array($cmd,['merge','unlink'],true));
        if($cmd==='road'){
            $block=$args[0]??throw new \InvalidArgumentException('Wegmaterial fehlt.');
            $item=StringToItemParser::getInstance()->parse($block);
            if(!$item instanceof \pocketmine\item\ItemBlock||!Guard::safeBlock($item->getBlock())||$item->getBlock()->getIdInfo()->getTileClass()!==null){throw new \InvalidArgumentException('Ungültiges Wegmaterial.');}
            $this->confirm($s,function()use($s,$key,$block):void{$plot=$this->owner($s,$key);$job=$this->p->edits->definition($plot['world'],$plot['cells'],'road',$block);$this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);});return;
        }
        if($cmd==='climate'){
            $choice=$args[0]??'';
            if(!in_array($choice,['sun','rain','storm','snow'],true)){throw new \InvalidArgumentException('Wetter: sun, rain, storm, snow.');}
            if($choice!=='snow'){$this->flag($s,$key,$plot,['set','weather',match($choice){'sun'=>'clear','rain'=>'rain',default=>'thunder'}]);return;}
            $this->permission($s,'plots.set.flag.weather');$this->permission($s,'plots.biome');
            $this->confirm($s,function()use($s,$key):void{
                $plot=$this->owner($s,$key);$updated=$plot;$updated['flags']['weather']='rain';
                if(!$this->p->event('FlagAdd',$s,$key,$plot,$updated)){return;}
                $job=$this->p->edits->definition($plot['world'],$plot['cells'],'biome','12',[$key=>$updated]);$this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);
            });return;
        }
        if(in_array($cmd,['backup','schematic','load','download','copy','move','swap'],true)){$this->snapshot($s,$key,$plot,$cmd,$args);return;}
        if(in_array($cmd,['add','trust','deny','remove'],true)){
            $uuid=$this->p->identity($args[0]??throw new \InvalidArgumentException('Spielername fehlt.'));
            if(in_array($uuid,$plot['owners'],true)){throw new \RuntimeException('Owner können nicht über die Mitgliederliste verändert werden.');}
            $before=$plot;foreach(['members','trusted','denied'] as $field){$plot[$field]=array_values(array_diff($plot[$field],[$uuid]));}
            $field=match($cmd){'add'=>'members','trust'=>'trusted','deny'=>'denied',default=>null};if($field!==null){$plot[$field][]=$uuid;}
            $event=match($cmd){'add'=>'Member','trust'=>'Trust','deny'=>'Deny',default=>null};
            if($event!==null&&!$this->p->event($event,$s,$key,$before,$plot)){return;}$this->save($s,[$key=>$plot]);return;
        }
        if($cmd==='setowner'){
            $name=trim(implode(' ',$args));if($name===''){throw new \InvalidArgumentException('/setowner SPIELER');}$uuid=$this->p->identity($name);if($uuid==='*'){throw new \InvalidArgumentException('Owner darf kein Wildcard sein.');}
            $this->confirm($s,function()use($s,$key,$uuid,$name):void{$plot=$this->owner($s,$key);$plot['owners']=[$uuid];$plot['ownerName']=$name;$this->save($s,[$key=>$plot]);});return;
        }
        if($cmd==='kick'){$name=$args[0]??'';foreach($this->p->getServer()->getOnlinePlayers() as $p){if(($name==='*'||strcasecmp($p->getName(),$name)===0)&&$this->p->keyAt($p->getPosition())===$key&&!in_array($this->p->actor($p),$plot['owners'],true)&&!$this->p->admin($p)){$p->teleport($p->getWorld()->getSpawnLocation());}}return;}
        if($cmd==='flag'){$this->flag($s,$key,$plot,$args);return;}
        if($cmd==='done'||$cmd==='continue'){if($cmd==='done'){$plot['flags']['done']=time();}else{unset($plot['flags']['done']);}$this->save($s,[$key=>$plot]);return;}
        if($cmd==='alias'||$cmd==='desc'){
            $value=trim(implode(' ',$args));if(strlen($value)>512){throw new \InvalidArgumentException('Text zu lang.');}
            if($cmd==='alias'){foreach($this->p->repo->all('bp/plot/') as $k=>$other){if($k!==$key&&$value!==''&&strcasecmp($other['alias'],$value)===0){throw new \RuntimeException('Alias bereits vergeben.');}}}
            $plot[$cmd==='alias'?'alias':'description']=$value;$this->save($s,[$key=>$plot]);return;
        }
        if($cmd==='music'){$this->flag($s,$key,$plot,['set','music',...$args]);return;}
        if($cmd==='merge'){$this->merge($s,$key,$args[0]??'', $id??$plot['cells'][0],implode(' ',array_slice($args,1)));return;}
        if($cmd==='unlink'){$this->unlink($s,$key,$args[0]??'', $id??$plot['cells'][0]);return;}
        if($cmd==='clear'||$cmd==='delete'){
            $this->confirm($s,function()use($s,$key,$cmd):void{$plot=$this->owner($s,$key);if(!$this->p->event(ucfirst($cmd),$s,$key,$plot,[])){return;}$job=$this->p->edits->definition($plot['world'],$plot['cells'],$cmd,null,$cmd==='delete'?[$key=>null]:[]);$this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);});return;
        }
        if($cmd==='set'||$cmd==='biome'){
            $kind=$cmd==='biome'?'biome':strtolower(array_shift($args)??'');
            if($kind==='empty'){$kind='air';}
            if($kind==='home'){$pos=$this->player($s)->getPosition();if($this->p->keyAt($pos)!==$key){throw new \RuntimeException('Home muss innerhalb des Plots liegen.');}$plot['home']=[$pos->x,$pos->y,$pos->z];$this->save($s,[$key=>$plot]);return;}
            if($kind==='alias'){$this->run($s,[$plot['world'].';'.$plot['cells'][0],'alias',...$args]);return;}
            if(!in_array($kind,['floor','wall','all','air','main','middle','outline','border','biome','refill'],true)){throw new \InvalidArgumentException('Unbekannter set-Unterbefehl.');}
            $block=in_array($kind,['air','refill'],true)?'air':($args[0]??throw new \InvalidArgumentException('Block/Biom-ID fehlt.'));
            if($kind==='biome'){$this->integer($block,0,255);}else{$item=StringToItemParser::getInstance()->parse($block);if($item===null||(!($item instanceof \pocketmine\item\ItemBlock)&&strtolower($block)!=='air'&&strtolower($block)!=='minecraft:air')||!Guard::safeBlock($item->getBlock())){throw new \InvalidArgumentException('Block wird von dieser Engine-Schutzkonfiguration nicht unterstützt.');}}
            $block=\BetterPlots\Domain\RankPrivileges::material($s,$this->p->admin($s),$kind,$block);
            $this->confirm($s,function()use($s,$key,$kind,$block):void{$block=\BetterPlots\Domain\RankPrivileges::material($s,$this->p->admin($s),$kind,$block);$plot=$this->owner($s,$key);$job=$this->p->edits->definition($plot['world'],$plot['cells'],$kind,$block);$this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);});return;
        }
        throw new \InvalidArgumentException('Dieser Befehl ist in diesem Entwicklungsstand noch nicht freigegeben. /p help');
    }
    private function setup(CommandSender $s,array $args,?array $template=null):void{
        $name=$args[0]??throw new \InvalidArgumentException('Syntax: /p setup <Welt> [Größe Straße Höhe]');
        if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$name)){throw new \InvalidArgumentException('Ungültiger Weltname.');}
        $preset=['size'=>$this->integer($args[1]??'32',2,256),'road'=>$this->integer($args[2]??'7',1,64),'height'=>$this->integer($args[3]??'64',-63,317)];
        if($template!==null){$preset=$template['preset'];}
        $wm=$this->p->getServer()->getWorldManager();if($wm->isWorldGenerated($name)){throw new \RuntimeException('Welt existiert bereits. Keine automatische Übernahme fremder Welten.');}
        new PlotGenerator(0,json_encode($preset,JSON_THROW_ON_ERROR));
        $style=$args[4]??'classic';$styles=(array)$this->p->getConfig()->get('road-styles',[]);
        $designStyle=$template===null&&\BetterPlots\World\RoadDesign::valid(strtolower($style))?strtolower($style):null;
        if($designStyle!==null){\BetterPlots\World\RoadDesign::check($designStyle,new \BetterPlots\Domain\Grid($preset['size'],$preset['road']));}
        elseif($template===null&&isset($styles[$style])){$preset['blocks']=array_replace($preset['blocks']??[],$styles[$style]);}
        elseif($template===null&&$style!=='classic'){throw new \InvalidArgumentException('Unbekannter Wegstil.');}
        new PlotGenerator(0,json_encode($preset,JSON_THROW_ON_ERROR));
        $record=['name'=>$name,'generator'=>'betterplots','preset'=>$preset,'always-day'=>(bool)$this->p->getConfig()->getNested('world-creation.always-day',true),'flags'=>[],'road-flags'=>[]];
        if($template!==null){$record['flags']=$template['flags']??[];$record['road-flags']=$template['road-flags']??[];}
        $this->p->repo->commit(['bp/world/'.$name=>$record],function(bool $ok)use($s,$wm,$name,$preset,$designStyle):void{
            if(!$ok){$this->p->say($s,'Speichern fehlgeschlagen.');return;}
            $options=WorldCreationOptions::create()->setGeneratorClass(PlotGenerator::class)->setGeneratorOptions(json_encode($preset,JSON_THROW_ON_ERROR))->setSeed(0)->setSpawnPosition(new Vector3($preset['size']+$preset['road']/2,$preset['height']+2,$preset['size']+$preset['road']/2));
            $ok=$wm->generateWorld($name,$options);$this->p->say($s,$ok?\BetterPlots\Text::defer('Plotwelt erstellt. /p area tp {v1}',['v1'=>$name]):'Welt konnte nicht erstellt werden.');
            if($ok&&$designStyle!==null){try{$this->p->roads->activateStyle($name,$designStyle,$s);}catch(\Throwable $e){$this->p->say($s,$e->getMessage());}}
        });
    }
    private function claim(CommandSender $s,string $world,array $cells):void{
        \BetterPlots\Domain\RankPrivileges::checkMerge($s,$this->p->admin($s),count($cells));
        $uuid=$this->p->actor($s);$g=$this->p->grid($world);
        if(count($cells)+$this->p->plots->ownedCount($uuid,$world)>$this->p->limit($s)){throw new \RuntimeException('Plotlimit erreicht.');}
        foreach($cells as $id){$cell=PlotId::parse($id);$b=$g->bounds($cell);if($this->p->plots->cell($world,$id)!==null||$this->p->edits->locked($world,$b['minX'],$b['minZ'])){throw new \RuntimeException('Plot ist bereits belegt oder gesperrt.');}
            $areas=$this->p->repo->all('bp/area/'.$world.'/');$inside=$areas===[];
            foreach($areas as $area){$r=$area['bounds'];if($cell->x>=$r[0]&&$cell->z>=$r[1]&&$cell->x<=$r[2]&&$cell->z<=$r[3]){$inside=true;}}
            if(!$inside){throw new \RuntimeException('Plot liegt außerhalb der definierten Areas.');}
            foreach($this->p->repo->all('bp/cluster/'.$world.'/') as $cluster){$r=$cluster['bounds'];if($cell->x>=$r[0]&&$cell->z>=$r[1]&&$cell->x<=$r[2]&&$cell->z<=$r[3]&&!$this->p->admin($s)&&!in_array($uuid,[...$cluster['owners'],...$cluster['members'],...$cluster['helpers']],true)){throw new \RuntimeException('Dieser Cluster ist für dich nicht freigegeben.');}}
        }
        $key='bp/plot/'.bin2hex(random_bytes(12));$plot=Plots::create($world,$cells,$uuid,$s->getName());
        if(!$this->p->event('Claim',$s,$key,[],$plot)){return;}$updates=[$key=>$plot];
        if(count($cells)>1){$updates['bp/job/'.bin2hex(random_bytes(12))]=$this->p->edits->definition($world,$cells,'merge');}
        $this->p->plots->commit($updates,function(bool $ok)use($s,$plot):void{$this->p->say($s,$ok?\BetterPlots\Text::defer('Plot beansprucht: {v1}',['v1'=>implode(', ',$plot['cells'])]):'Claim fehlgeschlagen.');if($ok&&$s instanceof Player){$this->go($s,$plot);}});
    }
    private function auto(CommandSender $s,string $world,array $args):void{
        $w=$this->integer($args[0]??'1',1,16);$h=$this->integer($args[1]??'1',1,16);$uuid=$this->p->actor($s);
        \BetterPlots\Domain\RankPrivileges::checkMerge($s,$this->p->admin($s),$w*$h);
        if($w*$h+$this->p->plots->ownedCount($uuid,$world)>$this->p->limit($s)){throw new \RuntimeException('Plotlimit reicht für diese Fläche nicht.');}
        if(isset($this->searching[$uuid])){throw new \RuntimeException('Plotsuche läuft bereits.');}
        $g=$this->p->grid($world);$iterator=$g->spiral(max(1,min(1000000,(int)$this->p->getConfig()->getNested('commands.auto-search-limit',100000))));$this->searching[$uuid]=true;$handler=null;
        $handler=$this->p->getScheduler()->scheduleRepeatingTask(new ClosureTask(function()use($s,$world,$w,$h,$uuid,$g,$iterator,&$handler):void{
            if($s instanceof Player&&!$s->isConnected()){$handler->cancel();unset($this->searching[$uuid]);return;}
            if($this->p->repo->busy()){return;}$end=hrtime(true)+1500000;
            while($iterator->valid()&&hrtime(true)<$end){
                $start=$iterator->current();$iterator->next();$cells=[];$valid=true;
                for($x=0;$x<$w;++$x){for($z=0;$z<$h;++$z){$id=new PlotId($start->x+$x,$start->z+$z);$b=$g->bounds($id);if($this->p->plots->cell($world,$id->key())!==null||$this->p->edits->locked($world,$b['minX'],$b['minZ'])){$valid=false;break 2;}$cells[]=$id->key();}}
                if($valid){$handler->cancel();unset($this->searching[$uuid]);try{$this->claim($s,$world,$cells);}catch(\Throwable $e){$this->p->say($s,$e->getMessage());}return;}
            }
            if(!$iterator->valid()){$handler->cancel();unset($this->searching[$uuid]);$this->p->say($s,'Suchbudget ausgeschöpft.');}
        }),1);$this->p->say($s,'Suche freie Plots …');
    }
    private function visit(CommandSender $s,string $cmd,array $args,?string $explicit):void{
        $p=$this->player($s);$all=$this->p->repo->all('bp/plot/');$needle=$args[0]??$p->getName();$result=[];
        if($explicit!==null){$result[$explicit]=$this->p->repo->get($explicit);}
        else{foreach($all as $key=>$plot){if($cmd==='home'?in_array($this->p->actor($s),$plot['owners'],true):(strcasecmp($plot['ownerName'],$needle)===0||strcasecmp($plot['alias'],$needle)===0||in_array($needle,$plot['cells'],true))){$result[$key]=$plot;}}}
        $index=$this->integer($cmd==='home'?($args[0]??'1'):($args[1]??'1'),1,100000)-1;$values=array_values($result);if(!isset($values[$index])){throw new \InvalidArgumentException('Kein passendes Plot gefunden.');}$this->go($p,$values[$index]);
    }
    private function listing(CommandSender $s,string $world,array $args):void{
        $filter=$args[0]??'mine';$uuid=$this->p->actor($s);$all=$this->p->repo->all('bp/plot/');$out=[];
        foreach($all as $plot){$match=match($filter){'all','top'=>true,'world','area'=>$plot['world']===$world,'forsale'=>isset($plot['flags']['price']),'shared'=>in_array($uuid,array_merge($plot['trusted'],$plot['members']),true),'done'=>isset($plot['flags']['done']),'expired'=>$plot['activity']<time()-86400*30,'player'=>strcasecmp($plot['ownerName'],$args[1]??'')===0,'fuzzy'=>str_contains(strtolower($plot['alias']),strtolower($args[1]??'')),'mine'=>in_array($uuid,$plot['owners'],true),default=>false};if($match){$out[]=$plot;}}
        if($filter==='top'){usort($out,static fn(array $a,array $b):int=>(array_sum($b['ratings'])/max(1,count($b['ratings'])))<=>(array_sum($a['ratings'])/max(1,count($a['ratings']))));}
        if(in_array($filter,['unowned','unknown'],true)){$g=$this->p->grid($world);$lines=[];foreach($g->spiral(10000) as $id){if($this->p->plots->cell($world,$id->key())===null){$lines[]=$id->key();if(count($lines)===20){break;}}}$this->p->sayRaw($s,implode(', ',$lines));return;}
        $page=$this->integer($args[in_array($filter,['player','fuzzy'],true)?2:1]??'1',1,100000);$lines=array_map(static fn(array $plot):string=>$plot['world'].';'.$plot['cells'][0].' — '.$plot['ownerName'].' '.$plot['alias'],array_slice($out,($page-1)*10,10));
        $this->p->sayRaw($s,implode("\n",$lines)?:\BetterPlots\Text::t($s,'Keine Plots gefunden.'));
    }
    private function toggle(CommandSender $s,array $args):void{
        $name=$args[0]??'';if(!in_array($name,['chat','chatspy','clear-confirmation','time','titles','worldedit'],true)){throw new \InvalidArgumentException('Unbekannter Schalter.');}
        if(in_array($name,['chatspy','clear-confirmation'],true)&&!$this->p->admin($s)){throw new \RuntimeException('Dieser Schalter benötigt Adminrechte.');}
        $key='bp/preferences/'.$this->p->actor($s);$prefs=$this->p->repo->get($key)??[];$prefs[$name]=!($prefs[$name]??false);$this->save($s,[$key=>$prefs]);
    }
    private function flag(CommandSender $s,string $key,array $plot,array $args):void{
        $action=$args[0]??'list';$name=$args[1]??'';
        if($action==='list'){$this->p->sayRaw($s,implode(', ',array_keys($this->p->flags->all())));return;}
        if($action==='info'){$this->p->say($s,\BetterPlots\Text::defer('{flag}: {type} | {value}',['flag'=>$name,'type'=>\BetterPlots\Text::ref($this->p->flags->all()[$name]??'unbekannt'),'value'=>json_encode($plot['flags'][$name]??null)]));return;}
        $this->permission($s,'plots.set.flag.'.$name);$before=$plot;
        if($action==='remove'&&count($args)===2){unset($plot['flags'][$name]);}
        else{
            $value=$this->p->flags->parse($name,implode(' ',array_slice($args,2)));
            if(in_array($name,['break','place','use'],true)){foreach($value as $block){if(StringToItemParser::getInstance()->parse($block)===null){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Unbekannter Bedrock-Block: {v1}',['v1'=>$block]));}}}
            if($action==='set'){$plot['flags'][$name]=$value;}
            elseif(is_array($value)&&in_array($action,['add','remove'],true)){$old=$plot['flags'][$name]??[];$plot['flags'][$name]=array_values($action==='add'?array_unique([...$old,...$value]):array_diff($old,$value));}
            else{throw new \InvalidArgumentException('add/remove mit Werten ist nur für Listen möglich.');}
        }
        if(!$this->p->event($action==='remove'?'FlagRemove':'FlagAdd',$s,$key,$before,$plot)){return;}$this->p->plots->commit([$key=>$plot],function(bool $ok)use($s,$key,$name,$plot):void{
            if(!$ok){$this->p->say($s,'Flag konnte nicht gespeichert werden.');return;}
            $saved=$this->p->repo->get($key);$value=$saved['flags'][$name]??null;
            $this->p->say($s,\BetterPlots\Text::defer('Gespeichert: {flag} = {value} | {world} | Plot {plots}',['flag'=>$name,'value'=>is_bool($value)?\BetterPlots\Text::ref($value?'an':'aus'):json_encode($value),'world'=>$plot['world'],'plots'=>implode(', ',$plot['cells'])]));
            if($name==='border'){$this->p->roads->refresh($plot['world']);}
        });
    }
    private function direction(CommandSender $s,string $direction):string{
        if($direction===''){$direction=\BetterPlots\Domain\MergeSelection::facing($this->player($s)->getLocation()->yaw);}
        if(!in_array($direction,['n','e','s','w','all'],true)){throw new \InvalidArgumentException('Richtung n/e/s/w oder /merge all Spielername.');}
        return $direction;
    }
    private function merge(CommandSender $s,string $key,string $direction,string $origin,string $name=''):void{
        if(!$this->p->admin($s)){throw new \RuntimeException('Mergen ist vorübergehend nur für OPs möglich.');}
        $direction=$this->direction($s,$direction);
        if($direction==='all'&&trim($name)===''){throw new \InvalidArgumentException('/merge all Spielername - nur benachbarte Plots dieses Spielers.');}
        $owner=$direction==='all'?$this->p->identity(trim($name)):null;
        $target=$direction==='all'?null:\BetterPlots\Domain\MergeSelection::neighbour($origin,$direction);
        $this->p->say($s,$target===null?\BetterPlots\Text::defer('Alle direkt verbundenen Nachbarplots von {v1} werden ausgewaehlt.',['v1'=>$name]):\BetterPlots\Text::defer('Nur Nachbarplot {v1} in Richtung {v2} ausgewaehlt.',['v1'=>$target,'v2'=>$direction]));
        $this->confirm($s,function()use($s,$key,$origin,$target,$owner):void{
            $plot=$this->owner($s,$key,true);$before=$plot;
            if(!in_array($origin,$plot['cells'],true)){throw new \RuntimeException('Ausgangsplot wurde geaendert. Bitte erneut auswaehlen.');}
            if($target===null){$keys=\BetterPlots\Domain\MergeSelection::nearby($this->p->repo->all('bp/plot/'),$key,$owner,$plot['world']);}
            else{$other=$this->p->plots->cell($plot['world'],$target);$keys=$other===null||$other===$key?[$key]:[$key,$other];}
            if(count($keys)<2){throw new \RuntimeException('Kein separates Nachbarplot in der Auswahl.');}
            $others=[];
            foreach($keys as $otherKey){if($otherKey===$key){continue;}$other=$this->owner($s,$otherKey,true);$others[$otherKey]=$other;
                if($other['owners']!==$plot['owners']&&!$this->p->admin($s)){throw new \RuntimeException('Unterschiedliche Besitzer koennen nur von OP zusammengefuehrt werden.');}
                $plot['cells']=array_values(array_unique([...$plot['cells'],...$other['cells']]));
                $plot['owners']=array_values(array_unique([...$plot['owners'],...$other['owners']]));
            }
            $plot['denied']=array_values(array_diff($plot['denied']??[],$plot['owners']));
            \BetterPlots\Domain\RankPrivileges::checkMerge($s,$this->p->admin($s),count($plot['cells']));
            if(!$this->p->event('Merge',$s,$key,$before,$plot)){return;}
            $finish=[$key=>$plot];foreach($others as $k=>$other){$finish[$k]=null;}
            $job=$this->p->edits->definition($plot['world'],$plot['cells'],'merge',null,$finish);
            $this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);
        });
    }
    private function unlink(CommandSender $s,string $key,string $direction,string $origin):void{
        $direction=$this->direction($s,$direction);
        if($direction==='all'){throw new \InvalidArgumentException('Unmerge trennt nur die ausgewaehlte Nachbarseite. Blickrichtung oder n/e/s/w verwenden.');}
        $target=\BetterPlots\Domain\MergeSelection::neighbour($origin,$direction);
        $this->p->say($s,\BetterPlots\Text::defer('Nur Verbindung {v1} -> {v2} trennen.',['v1'=>$origin,'v2'=>$target]));
        $this->confirm($s,function()use($s,$key,$origin,$target):void{
            $plot=$this->owner($s,$key,true);
            $groups=\BetterPlots\Domain\MergeSelection::split($plot['cells'],$origin,$target);
            if(!$this->p->event('Unlink',$s,$key,$plot,[])){return;}
            $finish=[];foreach($groups as $i=>$cells){$copy=$plot;$copy['cells']=$cells;$copy['home']=null;if($i!==0){$copy['alias']='';}$finish[$i===0?$key:'bp/plot/'.bin2hex(random_bytes(12))]=$copy;}
            $job=$this->p->edits->definition($plot['world'],$plot['cells'],'unlink',null,$finish);
            $job['after-groups']=$groups;
            $this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);
        });
    }
    private function comments(CommandSender $s,string $key,array $plot,string $cmd,array $args):void{
        $uuid=$this->p->actor($s);$owner=in_array($uuid,$plot['owners'],true)||$this->p->admin($s);
        if($cmd==='inbox'){$lines=[];foreach($plot['comments'] as $c){if($c['type']==='public'||$owner||$c['uuid']===$uuid){$lines[]=$c['name'].': '.$c['text'];}}$this->p->sayRaw($s,implode("\n",array_slice($lines,-20))?:\BetterPlots\Text::t($s,'Keine Nachrichten.'));return;}
        $type=array_shift($args)??'public';if(!in_array($type,['owner','public','report'],true)){throw new \InvalidArgumentException('Typ: owner, public oder report.');}$text=trim(implode(' ',$args));if($text===''||strlen($text)>1000){throw new \InvalidArgumentException('Kommentar benötigt 1..1000 Zeichen.');}
        $plot['comments'][]=['uuid'=>$uuid,'name'=>$s->getName(),'type'=>$type,'text'=>$text,'at'=>time()];$plot['comments']=array_slice($plot['comments'],-max(1,min(1000,(int)$this->p->getConfig()->getNested('limits.comments-per-plot',100))));$this->save($s,[$key=>$plot]);
    }
    private function debugExec(CommandSender $s,array $args):void{
        if(!$this->p->admin($s)){throw new \RuntimeException('Keine Berechtigung.');}
        if(($args[0]??'')==='status'){$this->run($s,['debug']);return;}
        throw new \InvalidArgumentException('Sichere Unterbefehle: status | balance <Spieler> <Betrag>.');
    }
    private function loadRoad(CommandSender $s,string $world,string $name):void{
        if(!$this->p->admin($s)){throw new \RuntimeException('Straßen der ganzen Welt dürfen nur Admins ändern.');}
        $this->p->settings($world);$actor=$this->p->actor($s);$id=$this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)['snapshot']??null;
        $snapshot=$id===null?null:$this->p->repo->get('bp/snapshot/'.$id);
        if($snapshot===null||!($snapshot['complete']??false)||($snapshot['kind']??'')!=='road'){throw new \RuntimeException('Straßenvorlage nicht gefunden. Mit pos1, pos2 und create neu speichern.');}
        $this->confirm($s,function()use($s,$world,$id):void{
            if(!$this->p->admin($s)||$this->p->repo->get('bp/snapshot/'.$id)===null){throw new \RuntimeException('Vorlage oder Adminrecht nicht mehr verfügbar.');}
            $this->p->roads->activate($world,$id,$s);
            $this->p->say($s,'Strassenumbau angefordert. Spieler werden in die Hauptwelt versetzt. Nach Abschluss wird die Plotwelt neu geladen. Grundstuecke und Merges bleiben erhalten.');
        });
    }

    private function roadStyle(CommandSender $s,array $args):void{
        if(!$this->p->admin($s)){throw new \RuntimeException('Straßenstile benötigen Adminrechte.');}
        $style=strtolower($args[0]??'');
        if(!\BetterPlots\World\RoadDesign::valid($style)){$list=[];foreach(\BetterPlots\World\RoadDesign::STYLES as $id=>$label){$list[]=$id.' ('.$label.')';}throw new \InvalidArgumentException('/p roadstyle <Stil> [Welt] | Stile: '.implode(', ',$list));}
        $world=$args[1]??($s instanceof Player?$s->getWorld()->getFolderName():throw new \InvalidArgumentException('Weltname fehlt.'));
        if(!$this->p->managed($world)){throw new \InvalidArgumentException('Keine BetterPlots-Welt: '.$world);}
        \BetterPlots\World\RoadDesign::check($style,$this->p->grid($world));
        $this->p->say($s,\BetterPlots\Text::defer('Straßenstil {style} für alle öffentlichen Straßen in {world}. Grundstücke und Merges bleiben unverändert. Spieler werden während des Umbaus in die Hauptwelt versetzt.',['style'=>\BetterPlots\Text::ref(\BetterPlots\World\RoadDesign::STYLES[$style]),'world'=>$world]));
        $this->confirm($s,function()use($s,$world,$style):void{
            if(!$this->p->admin($s)){throw new \RuntimeException('Adminrecht nicht mehr verfügbar.');}
            $this->p->roads->activateStyle($world,$style,$s);
        });
    }
    private function selectionCommand(CommandSender $s,array $args):void{
        if(!$this->p->admin($s)){throw new \RuntimeException('Straßenvorlagen benötigen Adminrechte.');}
        $p=$this->player($s);$actor=$this->p->actor($s);$action=strtolower($args[0]);$selectionKey='bp/road-selection/'.$actor;$selection=$this->p->repo->get($selectionKey)??[];
        if($action==='pos1'||$action==='pos2'){
            $world=$p->getWorld()->getFolderName();$this->p->settings($world);$v=$p->getPosition();
            $selection[$action]=['world'=>$world,'x'=>$v->getFloorX(),'y'=>$v->getFloorY(),'z'=>$v->getFloorZ()];$this->save($s,[$selectionKey=>$selection]);$this->p->say($s,\BetterPlots\Text::defer('{position} gespeichert: {world} {x}, {y}, {z}.',['position'=>strtoupper($action),'world'=>$world,'x'=>$v->getFloorX(),'y'=>$v->getFloorY(),'z'=>$v->getFloorZ()]));return;
        }
        if(count($args)!==2||!preg_match('/^[a-zA-Z0-9_-]{1,48}$/D',$args[1])){throw new \InvalidArgumentException('/p schematic create NAME');}
        if(!isset($selection['pos1'],$selection['pos2'])){throw new \InvalidArgumentException('Zuerst /p schematic pos1 und pos2 setzen.');}
        $a=$selection['pos1'];$b=$selection['pos2'];$world=$a['world'];$settings=$this->p->settings($world);$bounds=\BetterPlots\World\RoadSelection::bounds($a,$b,$this->p->grid($world),(int)($settings['preset']['height']??64));
        if($bounds[3]-$bounds[0]>=128||$bounds[5]-$bounds[2]>=128||$bounds[4]-$bounds[1]>=384){throw new \InvalidArgumentException('Straßenmodul maximal 128 × 384 × 128 Blöcke.');}
        $w=$p->getWorld();if($w->getFolderName()!==$world||$bounds[1]<$w->getMinY()||$bounds[4]>=$w->getMaxY()){throw new \InvalidArgumentException('Welt oder Auswahlhöhe ungültig.');}
        $name=$args[1];if($this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)!==null){throw new \RuntimeException('Name bereits vergeben.');}
        foreach($this->p->repo->all('bp/capture/') as $job){if(($job['owner']??null)===$actor&&($job['name']??null)===$name){throw new \RuntimeException('Name wird gerade gespeichert.');}}
        $id=bin2hex(random_bytes(12));$job=['world'=>$world,'cells'=>[],'bounds'=>[$bounds[0],$bounds[2],$bounds[3],$bounds[5]],'selection'=>$bounds,'kind'=>'road','road-layout'=>2,'source-grid'=>['size'=>$this->p->grid($world)->size,'road'=>$this->p->grid($world)->road,'offsetX'=>$this->p->grid($world)->offsetX,'offsetZ'=>$this->p->grid($world)->offsetZ],'base-height'=>(int)($settings['preset']['height']??64),'snapshot'=>$id,'owner'=>$actor,'name'=>$name,'created'=>time(),'after'=>['bp/snapshot-index/'.$actor.'/'.$name=>['snapshot'=>$id]]];
        foreach([...$this->p->repo->all('bp/job/'),...$this->p->repo->all('bp/capture/')] as $other){if($other['world']===$world){throw new \RuntimeException('Bitte laufende Weltbearbeitung zuerst abschließen lassen.');}}
        $this->save($s,['bp/capture/'.$id=>$job]);
    }
    private function snapshot(CommandSender $s,string $key,array $plot,string $cmd,array $args,?array $selection=null):void{
        $actor=$this->p->actor($s);
        if(in_array($cmd,['copy','move','swap'],true)){
            $target=$args[0]??throw new \InvalidArgumentException('Ziel-ID fehlt.');$parts=explode(';',$target);if(count($parts)===2){$target=$plot['world'].';'.$target;}
            [,,$destination]=$this->resolve($s,$target);if($destination===null||$destination===$key){throw new \InvalidArgumentException('Anderes beanspruchtes Zielplot erforderlich.');}
            $this->owner($s,$destination);
            $this->confirm($s,function()use($s,$key,$destination,$cmd,$actor):void{
                $source=$this->owner($s,$key);$dest=$this->owner($s,$destination);$a=bin2hex(random_bytes(12));$b=bin2hex(random_bytes(12));
                $captureA=$this->p->edits->definition($source['world'],$source['cells'],'capture');$captureA+=['snapshot'=>$a,'owner'=>$actor,'name'=>$cmd.'-'.$a];
                $restoreB=$this->restoreJob($captureA,$dest,$a);
                $reserveA='bp/reservation/'.$a;$reserveB='bp/reservation/'.$b;
                if($cmd==='swap'){
                    $captureB=$this->p->edits->definition($dest['world'],$dest['cells'],'capture');$captureB+=['snapshot'=>$b,'owner'=>$actor,'name'=>'swap-'.$b];
                    $restoreA=$this->restoreJob($captureB,$source,$b);
                    $captureB['after']=['bp/job/'.$a=>$restoreA,'bp/job/'.$b=>$restoreB,$reserveA=>null];
                    $captureA['after']=['bp/capture/'.$b=>$captureB,$reserveB=>null,$reserveA=>['world'=>$source['world'],'bounds'=>$captureA['bounds']]];
                }else{
                    if($cmd==='move'){$clear=$this->p->edits->definition($source['world'],$source['cells'],'clear');$restoreB['finish']['bp/job/'.$b]=$clear;$restoreB['finish'][$reserveA]=null;}
                    $captureA['after']=['bp/job/'.$a=>$restoreB,$reserveB=>null];if($cmd==='move'){$captureA['after'][$reserveA]=['world'=>$source['world'],'bounds'=>$captureA['bounds']];}
                }

                $reservation=['world'=>$dest['world'],'bounds'=>$restoreB['bounds']];
                $this->save($s,['bp/capture/'.$a=>$captureA,$reserveB=>$reservation]);
            });return;
        }
        $action=in_array($cmd,['load','download'],true)?($cmd==='load'?'load':'export'):(array_shift($args)??'list');
        if($action==='saveall'){
            if(!$this->p->admin($s)){throw new \RuntimeException('Keine Berechtigung.');}$updates=[];
            foreach($this->p->repo->all('bp/plot/') as $k=>$other){
                if(count($updates)>=20){throw new \RuntimeException('saveall ist auf 20 Plotgruppen je Aufruf begrenzt.');}
                $other=$this->owner($s,$k);$sid=bin2hex(random_bytes(12));$n='all-'.date('YmdHis').'-'.substr($sid,0,6);$capture=$this->p->edits->definition($other['world'],$other['cells'],'capture');$capture+=['snapshot'=>$sid,'owner'=>$actor,'name'=>$n,'after'=>['bp/snapshot-index/'.$actor.'/'.$n=>['snapshot'=>$sid]]];$updates['bp/capture/'.$sid]=$capture;
            }$this->save($s,$updates);return;
        }
        if($action==='paste'){$action='load';}
        if($action==='list'){$lines=[];foreach($this->p->repo->all('bp/snapshot/') as $snapshot){if(($snapshot['owner']??null)===$actor||$this->p->admin($s)){$lines[]=$snapshot['name'].' — '.$snapshot['world'].' — '.date('Y-m-d H:i',$snapshot['created']);}}$this->p->sayRaw($s,implode("\n",array_slice($lines,-30))?:\BetterPlots\Text::t($s,'Keine Backups.'));return;}
        $name=$args[0]??date('Ymd-His');if(!preg_match('/^[a-zA-Z0-9_-]{1,48}$/D',$name)){throw new \InvalidArgumentException('Name: Buchstaben, Zahlen, _ und -.');}
        if(in_array($action,['save','import'],true)){
            if($this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)!==null){throw new \RuntimeException('Dieser Name existiert bereits. Anderen Namen wählen oder die alte Sicherung löschen.');}
            foreach($this->p->repo->all('bp/capture/') as $pending){if(($pending['owner']??null)===$actor&&($pending['name']??null)===$name){throw new \RuntimeException('Eine Sicherung mit diesem Namen läuft bereits.');}}
        }
        if($action==='import'){
            $path=$this->p->getDataFolder().'schematics/'.$actor.'-'.$name.'.bplot';
            $this->p->database->submit(['op'=>'read-snapshot','path'=>$path],function(array $r)use($s,$actor,$name):void{
                try{
                    if(!($r['ok']??false)){throw new \RuntimeException('Importdatei fehlt oder ist ungültig.');}$data=$r['value'];$manifest=$data['manifest'];$id=bin2hex(random_bytes(12));
                    if(!isset($manifest['world'],$manifest['cells'],$manifest['bounds'])||!is_array($manifest['cells'])||!Topology::connected($manifest['cells'])){throw new \RuntimeException('Ungültige Snapshot-Geometrie.');}
                    $this->p->settings($manifest['world']);$updates=[];$parts=[];
                    foreach($data['chunks'] as $part=>$chunk){
                        PlotId::parse((string)$part);
                        if(!is_array($chunk)||($chunk['minY']??null)!==-64||($chunk['maxY']??null)!==320||!is_array($chunk['palette']??null)||count($chunk['palette'])>4096||!is_array($chunk['tiles']??null)){throw new \RuntimeException('Ungültiger Snapshot-Chunk.');}
                        \BetterPlots\World\SnapshotCodec::expand($chunk['rle'],98304);foreach($chunk['palette'] as $entry){\BetterPlots\World\SnapshotCodec::runtime($entry);}
                        foreach($chunk['tiles'] as $position=>$nbt){if(!preg_match('/^-?[0-9]+;-?[0-9]+;-?[0-9]+$/D',(string)$position)||!is_string($nbt)||strlen($nbt)>1048576||base64_decode($nbt,true)===false){throw new \RuntimeException('Ungültige Tile-Daten.');}}
                        $updates['bp/snapshot-chunk/'.$id.'/'.$part]=$chunk;$parts[]=(string)$part;
                    }
                    if($this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)!==null){throw new \RuntimeException('Name wurde inzwischen vergeben.');}$manifest['snapshot']=$id;$manifest['name']=$name;$manifest['owner']=$actor;$manifest['complete']=true;$manifest['parts']=$parts;$manifest['created']=time();unset($manifest['after']);$updates['bp/snapshot/'.$id]=$manifest;$updates['bp/snapshot-index/'.$actor.'/'.$name]=['snapshot'=>$id];$this->save($s,$updates);
                }catch(\Throwable $e){$this->p->say($s,\BetterPlots\Text::defer('Import abgelehnt: {v1}',['v1'=>\BetterPlots\Text::ref($e->getMessage())]));}
            });return;
        }
        if($action==='save'){
            $count=0;foreach($this->p->repo->all('bp/snapshot/') as $snapshot){if(($snapshot['owner']??null)===$actor){++$count;}}
            $limit=max(1,min(100,(int)$this->p->getConfig()->getNested('limits.snapshots-per-player',20)));if($count>=$limit){throw new \RuntimeException(\BetterPlots\Text::defer('Snapshot-Limit {v1} erreicht. /p backup delete <Name> entfernt alte Snapshots.',['v1'=>$limit]));}
            $id=bin2hex(random_bytes(12));$job=$this->p->edits->definition($plot['world'],$plot['cells'],'capture');$job+=['snapshot'=>$id,'owner'=>$actor,'name'=>$name,'after'=>['bp/snapshot-index/'.$actor.'/'.$name=>['snapshot'=>$id]]];if($selection!==null){$job['selection']=$selection;}$this->save($s,['bp/capture/'.$id=>$job]);return;
        }
        $id=$this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)['snapshot']??null;$snapshot=$id===null?null:$this->p->repo->get('bp/snapshot/'.$id);
        if($snapshot===null||!($snapshot['complete']??false)){throw new \InvalidArgumentException('Vollständiger Snapshot nicht gefunden.');}
        if($action==='delete'){foreach($this->p->repo->all('bp/road-template/') as $template){if($template['snapshot']===$id){throw new \RuntimeException('Diese Straßenvorlage ist in einer Welt aktiv.');}}
            foreach($this->p->repo->all('bp/job/') as $job){if(($job['snapshot']??null)===$id){throw new \RuntimeException('Snapshot wird von einem Job verwendet.');}}
            $this->confirm($s,function()use($s,$snapshot,$id,$actor,$name):void{
                foreach($this->p->repo->all('bp/road-template/') as $template){if($template['snapshot']===$id){throw new \RuntimeException('Diese Strassenvorlage ist aktiv.');}}
                if(($this->p->repo->get('bp/snapshot-index/'.$actor.'/'.$name)['snapshot']??null)!==$id){throw new \RuntimeException('Diese Sicherungsauswahl ist nicht mehr aktuell.');}
                foreach($this->p->repo->all('bp/job/') as $job){if(($job['snapshot']??null)===$id){throw new \RuntimeException('Snapshot wird verwendet.');}}
                $updates=['bp/snapshot/'.$id=>null,'bp/snapshot-index/'.$actor.'/'.$name=>null];foreach($snapshot['parts'] as $part){$updates['bp/snapshot-chunk/'.$id.'/'.$part]=null;}
                if(count($updates)>256){throw new \RuntimeException('Snapshot ist für eine atomare Löschung zu groß.');}$this->save($s,$updates);
            });return;
        }
        if($action==='load'){$this->confirm($s,function()use($s,$key,$snapshot,$id):void{if($this->p->repo->get('bp/snapshot/'.$id)===null){throw new \RuntimeException('Sicherung wurde inzwischen gelöscht.');}$plot=$this->owner($s,$key);$job=$this->restoreJob($snapshot,$plot,$id);$this->save($s,['bp/job/'.bin2hex(random_bytes(12))=>$job]);});return;}
        if($action==='export'){
            $path=$this->p->getDataFolder().'schematics/'.$actor.'-'.$name.'.bplot';
            $this->p->database->submit(['op'=>'export-snapshot','snapshot'=>$id,'path'=>$path],function(array $r)use($s,$name):void{$this->p->say($s,($r['ok']??false)?\BetterPlots\Text::defer('Export gespeichert unter schematics/*-{v1}.bplot',['v1'=>$name]):'Export fehlgeschlagen.');});return;
        }
        throw new \InvalidArgumentException('backup: save, list, load. schematic: save, paste. download: lokaler Export.');
    }
    private function restoreJob(array $snapshot,array $destination,string $id):array{
        if(($snapshot['kind']??'')==='road'){throw new \InvalidArgumentException('Strassenvorlagen mit /p load WELT NAME aktivieren.');}
        $sourceGrid=$this->p->grid($snapshot['world']);$targetGrid=$this->p->grid($destination['world']);
        if($sourceGrid->size!==$targetGrid->size||$sourceGrid->road!==$targetGrid->road){throw new \InvalidArgumentException('Quell- und Zielraster müssen übereinstimmen.');}
        $a=PlotId::parse($snapshot['cells'][0]);$b=PlotId::parse($destination['cells'][0]);$shifted=[];
        foreach($snapshot['cells'] as $cell){$p=PlotId::parse($cell);$shifted[]=($p->x+$b->x-$a->x).';'.($p->z+$b->z-$a->z);}$expected=$destination['cells'];sort($shifted);sort($expected);if($shifted!==$expected){throw new \InvalidArgumentException('Quell- und Zielgruppen müssen dieselbe Form haben.');}
        $from=$sourceGrid->bounds($a);$to=$targetGrid->bounds($b);$job=$this->p->edits->definition($destination['world'],$destination['cells'],'restore');$job['snapshot']=$id;$job['dx']=$to['minX']-$from['minX'];$job['dz']=$to['minZ']-$from['minZ'];if(isset($snapshot['selection'])){$job['selection']=$snapshot['selection'];}return $job;
    }
    private function template(CommandSender $s,array $args):void{
        $action=array_shift($args)??'list';if($action==='list'){$this->p->sayRaw($s,implode(', ',array_keys($this->p->repo->all('bp/template/'))));return;}
        $name=$args[0]??'';if(!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$name)){throw new \InvalidArgumentException('Ungültiger Vorlagenname.');}$key='bp/template/'.$name;
        if($action==='save'){$world=$args[1]??($s instanceof Player?$s->getWorld()->getFolderName():'plots');$this->save($s,[$key=>$this->p->settings($world)]);return;}
        if($action==='delete'){$this->confirm($s,fn()=>$this->save($s,[$key=>null]));return;}
        if($action==='load'){$template=$this->p->repo->get($key)??throw new \RuntimeException('Vorlage fehlt.');$world=$args[1]??throw new \InvalidArgumentException('Neue Welt fehlt.');$this->setup($s,[$world],$template);return;}
        throw new \InvalidArgumentException('template: save <Name> [Welt], load <Name> <neue Welt>, list, delete.');
    }
    private function purge(CommandSender $s,array $args):void{
        $days=$this->integer($args[0]??'30',1,36500);$this->confirm($s,function()use($s,$days):void{
            $updates=[];foreach($this->p->repo->all('bp/plot/') as $key=>$plot){if($plot['activity']>=time()-$days*86400||($plot['flags']['server-plot']??false)){continue;}$this->owner($s,$key);$job=$this->p->edits->definition($plot['world'],$plot['cells'],'delete',null,[$key=>null]);$updates['bp/job/'.bin2hex(random_bytes(12))]=$job;if(count($updates)>=100){break;}}
            if($updates===[]){$this->p->say($s,'Keine abgelaufenen Plots.');return;}$this->save($s,$updates);
        });
    }
    private function area(CommandSender $s,array $args):void{
        $action=array_shift($args)??'list';
        if($action==='list'){$this->p->sayRaw($s,implode(', ',array_map(static fn(array $r):string=>$r['name'],$this->p->repo->all('bp/world/'))));return;}
        $name=$args[0]??throw new \InvalidArgumentException('Weltname fehlt.');
        if($action==='create'){
            if(count($args)<4){$this->setup($s,$args);return;}
            $this->p->settings($name);$areaName=$args[1];if(!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$areaName)){throw new \InvalidArgumentException('Ungültiger Areaname.');}
            $a=PlotId::parse($args[2]);$b=PlotId::parse($args[3]);$bounds=[min($a->x,$b->x),min($a->z,$b->z),max($a->x,$b->x),max($a->z,$b->z)];
            foreach($this->p->repo->all('bp/area/'.$name.'/') as $area){$r=$area['bounds'];if($r[0]<=$bounds[2]&&$r[2]>=$bounds[0]&&$r[1]<=$bounds[3]&&$r[3]>=$bounds[1]){throw new \RuntimeException('Areas dürfen nicht überlappen.');}}
            $this->save($s,['bp/area/'.$name.'/'.$areaName=>['name'=>$areaName,'world'=>$name,'bounds'=>$bounds]]);return;
        }
        if($action==='info'){$this->p->sayRaw($s,json_encode($this->p->settings($name),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return;}
        if($action==='tp'){
            $player=$this->player($s);$wm=$this->p->getServer()->getWorldManager();$wm->loadWorld($name);$world=$wm->getWorldByName($name)??throw new \InvalidArgumentException('Welt nicht geladen.');$pos=$world->getSpawnLocation();
            $world->requestChunkPopulation($pos->getFloorX()>>4,$pos->getFloorZ()>>4,null)->onCompletion(function()use($player,$pos):void{if($player->isConnected()){$this->p->teleportPlayer($player,$pos);}},function()use($s):void{$this->p->say($s,'Chunk konnte nicht geladen werden.');});return;
        }throw new \InvalidArgumentException('area: create, info, list, tp.');
    }
    private function cluster(CommandSender $s,string $world,array $args):void{
        $action=array_shift($args)??'list';if($action==='list'){$this->p->sayRaw($s,implode(', ',array_map(static fn(array $r):string=>$r['name'],$this->p->repo->all('bp/cluster/'))));return;}
        $name=$args[0]??throw new \InvalidArgumentException('Clustername fehlt.');if(!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$name)){throw new \InvalidArgumentException('Ungültiger Name.');}$key='bp/cluster/'.$world.'/'.$name;$cluster=$this->p->repo->get($key);
        if($action==='create'){
            if($cluster!==null){throw new \RuntimeException('Cluster existiert.');}$a=PlotId::parse($args[1]??'');$b=PlotId::parse($args[2]??'');
            $cluster=['name'=>$name,'world'=>$world,'owners'=>[$this->p->actor($s)],'members'=>[],'helpers'=>[],'bounds'=>[min($a->x,$b->x),min($a->z,$b->z),max($a->x,$b->x),max($a->z,$b->z)],'home'=>null];$this->save($s,[$key=>$cluster]);return;
        }
        if($cluster===null){throw new \RuntimeException('Cluster nicht gefunden.');}
        if($action==='info'){$this->p->sayRaw($s,json_encode($cluster,JSON_THROW_ON_ERROR));return;}
        if($action==='leave'){$cluster['members']=array_values(array_diff($cluster['members'],[$this->p->actor($s)]));$cluster['helpers']=array_values(array_diff($cluster['helpers'],[$this->p->actor($s)]));$this->save($s,[$key=>$cluster]);return;}
        if(!$this->p->admin($s)&&!in_array($this->p->actor($s),$cluster['owners'],true)&&!(in_array($action,['tp','sethome','invite','kick'],true)&&in_array($this->p->actor($s),$cluster['helpers'],true))){throw new \RuntimeException('Keine Cluster-Ownerrechte.');}
        if($action==='delete'){$this->confirm($s,fn()=>$this->save($s,[$key=>null]));return;}
        if(in_array($action,['invite','kick','helpers','leave'],true)){
            $uuid=$action==='leave'?$this->p->actor($s):$this->p->identity($args[1]??'');$field=$action==='helpers'?'helpers':'members';
            $cluster[$field]=in_array($action,['kick','leave'],true)?array_values(array_diff($cluster[$field],[$uuid])):array_values(array_unique([...$cluster[$field],$uuid]));$this->save($s,[$key=>$cluster]);return;
        }
        if($action==='resize'){$a=PlotId::parse($args[1]??'');$b=PlotId::parse($args[2]??'');$cluster['bounds']=[min($a->x,$b->x),min($a->z,$b->z),max($a->x,$b->x),max($a->z,$b->z)];$this->save($s,[$key=>$cluster]);return;}
        if($action==='sethome'){$pos=$this->player($s)->getPosition();if($pos->getWorld()->getFolderName()!==$world){throw new \RuntimeException('Falsche Welt.');}$cluster['home']=[$pos->x,$pos->y,$pos->z];$this->save($s,[$key=>$cluster]);return;}
        if($action==='tp'){$this->go($this->player($s),['world'=>$world,'cells'=>[$cluster['bounds'][0].';'.$cluster['bounds'][1]],'home'=>$cluster['home'],'owners'=>$cluster['owners'],'trusted'=>[],'members'=>$cluster['members'],'denied'=>[],'flags'=>[]]);return;}
        throw new \InvalidArgumentException('Unbekannter Cluster-Unterbefehl.');
    }
}
