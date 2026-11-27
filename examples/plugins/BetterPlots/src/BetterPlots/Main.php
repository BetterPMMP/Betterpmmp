<?php
declare(strict_types=1);
namespace BetterPlots;

use BetterPlots\Domain\{Grid,Access};
use BetterPlots\Flags\Registry;
use BetterPlots\Generator\Registration;
use BetterPlots\Service\Plots;
use BetterPlots\Storage\{AsyncDatabase,Repository};
use BetterPlots\Command\Router;
use BetterPlots\Protection\Guard;
use BetterPlots\World\Environment;
use BetterPlots\World\EditQueue;
use BetterPlots\World\CaptureQueue;
use pocketmine\plugin\PluginBase;
use pocketmine\command\{Command,CommandSender};
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\world\Position;

final class Main extends PluginBase{
    public AsyncDatabase $database;
    public Repository $repo;
    public Plots $plots;
    public Registry $flags;
    public EditQueue $edits;
    public CaptureQueue $captures;
    public Environment $environment;
    public \BetterPlots\World\Roads $roads;
    public \BetterPlots\World\PlotAnimals $animals;
    private Router $router;

    private array $worlds=[];
    public \BetterPlots\Storage\LegacyImport $legacy;
    private ?string $startupError=null;
    public \BetterPlots\World\SpawnSafety $spawnSafety;
    private bool $indexed=false;
    private int $indexedGeneration=0;
    private ?string $reportedDatabaseError=null;
    private array $buildBypass=[];
    public \BetterPlots\UI\MenuManager $menus;

    private array $messages=[];
    protected function onLoad():void{Registration::register();\BetterPlots\Spawner\Registration::register();}
    public function teleportPlayer(Player $player,Position $to):bool{return $player->teleport($to);}
    protected function onEnable():void{
        foreach(['config.yml','worlds.yml','flags.json','de_DE.json','menus.yml'] as $resource){$this->saveResource($resource);}
        $menuPath=$this->getDataFolder().'menus.yml';$menuData=yaml_parse_file($menuPath);
        if((int)($menuData['version']??0)<4){
            $backup=$this->getDataFolder().'menus-before-0.4.yml';
            if(!is_file($backup)&&!copy($menuPath,$backup)){throw new \RuntimeException('Alte Menükonfiguration konnte nicht gesichert werden.');}
            $this->saveResource('menus.yml',true);
        }
        $menuConfig=new Config($menuPath,Config::YAML);
        if((int)$menuConfig->get('version',0)<5){
            $backup=$this->getDataFolder().'menus-before-0.5.yml';
            if(!is_file($backup)&&!copy($menuPath,$backup)){throw new \RuntimeException('Menüsicherung fehlgeschlagen.');}
            $resource=$this->getResource('menus.yml');$bundled=yaml_parse(stream_get_contents($resource));fclose($resource);
            $menuConfig->setNested('pages.backups',$bundled['pages']['backups']);$menuConfig->set('version',5);$menuConfig->save();
        }
        $defaults=$this->getResource('config.yml');
        if($defaults!==null){$values=yaml_parse(stream_get_contents($defaults));fclose($defaults);if(is_array($values)){$this->getConfig()->setDefaults($values);$this->getConfig()->save();}}
        $this->worlds=(new Config($this->getDataFolder().'worlds.yml',Config::YAML))->get('worlds',[]);
        $language=(string)$this->getConfig()->get('language','de_DE');
        if(!preg_match('/^[a-zA-Z_]+$/D',$language)){$language='de_DE';}
        $path=$this->getDataFolder().$language.'.json';
        $this->messages=json_decode((string)file_get_contents(is_file($path)?$path:$this->getDataFolder().'de_DE.json'),true,32,JSON_THROW_ON_ERROR);
        $this->flags=new Registry((string)file_get_contents($this->getDataFolder().'flags.json'));
        $driver=(string)$this->getConfig()->getNested('database.driver','sqlite');
        if($driver==='sqlite'){$dsn='sqlite:'.$this->getDataFolder().'betterplots.sqlite';$user=$password='';}
        elseif($driver==='mysql'){
            $host=(string)$this->getConfig()->getNested('database.mysql.host','127.0.0.1');
            $name=(string)$this->getConfig()->getNested('database.mysql.database','betterplots');
            $port=(int)$this->getConfig()->getNested('database.mysql.port',3306);
            if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$host)||!preg_match('/^[a-zA-Z0-9_]+$/D',$name)||$port<1||$port>65535){throw new \InvalidArgumentException('Ungültige MySQL-Konfiguration.');}
            $dsn="mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            $user=(string)$this->getConfig()->getNested('database.mysql.username','betterplots');$password=(string)$this->getConfig()->getNested('database.mysql.password','');
        }else{throw new \InvalidArgumentException('Treiber muss sqlite oder mysql sein.');}
        $this->database=new AsyncDatabase($dsn,$user,$password);
        $this->repo=new Repository($this->database);$this->plots=new Plots($this->repo);
        $this->spawnSafety=new \BetterPlots\World\SpawnSafety($this);
        $this->legacy=new \BetterPlots\Storage\LegacyImport($this);$this->getServer()->getPluginManager()->registerEvents($this->legacy,$this);
        $this->edits=new EditQueue($this);$this->captures=new CaptureQueue($this);$this->environment=new Environment($this);$this->router=new Router($this);
        $this->roads=new \BetterPlots\World\Roads($this);$this->animals=new \BetterPlots\World\PlotAnimals($this);
        $this->getServer()->getPluginManager()->registerEvents($this->roads,$this);
        $this->menus=new \BetterPlots\UI\MenuManager($this);
        $this->getServer()->getPluginManager()->registerEvents($this->menus,$this);
        $this->getServer()->getPluginManager()->registerEvents(new Guard($this),$this);
        $this->spawners=new \BetterPlots\Spawner\SpawnerService($this);$this->getServer()->getPluginManager()->registerEvents($this->spawners,$this);$this->spawners->scan();
        $this->getServer()->getPluginManager()->registerEvents($this->environment,$this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->tick(...)),1);
        $this->getLogger()->warning('Entwicklungsbuild: geschützte Plotwelten sperren Geräte ohne vollständige BetterPMMP-Schutzereignisse. PARITY.md beachten.');
    }
    public \BetterPlots\Spawner\SpawnerService $spawners;
    private function tick():void{
        try{

            $this->repo->tick();
            $databaseError=$this->database->error()??$this->repo->error();
            if($databaseError!==null&&$databaseError!==$this->reportedDatabaseError){
                $this->getLogger()->error('BetterPlots: '.$databaseError.' | Datenordner: '.$this->getDataFolder().' | Plotzugriff gesperrt; erneuter Ladeversuch folgt.');
            }
            $this->reportedDatabaseError=$databaseError;
            if(!$this->repo->ready()){$this->indexed=false;return;}
            foreach($this->repo->all('bp/world/') as $settings){$this->worlds[$settings['name']]=$settings;}
            $this->legacy->tick();if(!$this->legacy->ready()){return;}
            if(!$this->indexed||$this->indexedGeneration!==$this->repo->generation()){
                $this->plots->rebuild();$this->indexedGeneration=$this->repo->generation();$this->indexed=true;$this->startupError=null;
                $this->getLogger()->info('BetterPlots: '.count($this->repo->all('bp/plot/')).' Plotgruppen geladen; Besitzerdaten und Schutz bereit.');
            }
            $this->spawnSafety->tick();$this->spawners->tick();
            $this->captures->tick();$this->edits->tick();$this->environment->tick();$this->roads->tick();
            if(!$this->repo->busy()){
                foreach($this->getServer()->getOnlinePlayers() as $p){
                    if($this->legacy->bind($p->getName(),$p->getUniqueId()->toString())){break;}
                    $key='bp/player/'.strtolower($p->getName());$existing=$this->repo->get($key);
                    if(($existing['uuid']??null)!==$p->getUniqueId()->toString()){
                        $record=['uuid'=>$p->getUniqueId()->toString(),'name'=>$p->getName()];
                        $this->repo->commit([$key=>$record],function(bool $ok):void{if(!$ok){$this->getLogger()->error('Spieleridentität nicht gespeichert; Zugriff bleibt gesperrt.');}});break;
                    }
                }
            }
        }catch(\Throwable $e){$this->getLogger()->logException($e);$this->indexed=false;$this->startupError=$e->getMessage();}
    }
    protected function onDisable():void{
        if(isset($this->menus)){$this->menus->closeAll();}
        if(isset($this->environment)){$this->environment->restoreAll();}
        if(isset($this->roads)){$this->roads->close();}
        if(isset($this->captures)){$this->captures->close();}
        if(isset($this->edits)){$this->edits->close();}
        if(isset($this->database)){$this->database->beginClose();$this->database->reap();}
    }
    public function onCommand(CommandSender $sender,Command $command,string $label,array $args):bool{
        try{if(in_array($command->getName(),['setowner','merge','unmerge'],true)){array_unshift($args,$command->getName());}if(in_array($command->getName(),['p1','p2'],true)){$args=['schematic',$command->getName()==='p1'?'pos1':'pos2'];}if($command->getName()==='schematic'){array_unshift($args,'schematic');}$this->router->run($sender,$args);}catch(\InvalidArgumentException|\RuntimeException $e){$this->say($sender,$e->getMessage());}
        catch(\Throwable $e){$this->getLogger()->logException($e);$this->say($sender,'Interner Fehler; Änderung abgebrochen.');}
        return true;
    }
    public function notifyJob(array $job,string $message):void{
        foreach($this->getServer()->getOnlinePlayers() as $player){if($player->getUniqueId()->toString()===($job['notify']??null)){$this->say($player,$message);}}
    }
    public function adoptExistingWorld(string $name):\pocketmine\world\World{return \BetterPlots\World\ExistingWorld::load($this,$name);}
    public function loadingMessage():string{
        if($this->startupError!==null){return \BetterPlots\Text::defer('BetterPlots-Startfehler: {error}',['error'=>\BetterPlots\Text::ref($this->startupError)]);}
        if($this->database->hasFailed()||$this->repo->error()!==null){return 'BetterPlots-Datenbank wird erneut geladen. Plotzugriff bleibt bis dahin gesperrt. Bitte Serverlog pruefen.';}
        if(!$this->repo->ready()){return 'BetterPlots-Datenbank wird geladen.';}
        if(!$this->legacy->ready()){return $this->legacy->status();}
        return 'Plot-Index wird aufgebaut.';
    }
    public function ready():bool{return $this->indexed&&$this->repo->ready()&&$this->indexedGeneration===$this->repo->generation()&&$this->legacy->ready();}
    public function managed(string $world):bool{
        if(isset($this->worlds[$world])||(isset($this->repo)&&$this->repo->get('bp/world/'.$world)!==null)){return true;}
        $loaded=$this->getServer()->getWorldManager()->getWorldByName($world);
        if($loaded!==null){
            $data=$loaded->getProvider()->getWorldData();
            if(in_array(strtolower($data->getGenerator()),['betterplots','myplot','cplot_plot'],true)){
                $preset=json_decode($data->getGeneratorOptions(),true);
                if(strtolower($data->getGenerator())!=='betterplots'){$preset=\BetterPlots\Generator\LegacyPreset::convert(is_array($preset)?$preset:[],strtolower($data->getGenerator())==='myplot');}
                $this->worlds[$world]=['name'=>$world,'generator'=>'betterplots','preset'=>is_array($preset)?$preset:[],'flags'=>[],'road-flags'=>[]];
                return true;
            }
        }
        return false;
    }

    public function settings(string $world):array{return $this->repo->get('bp/world/'.$world)??$this->worlds[$world]??throw new \InvalidArgumentException('Keine Plotwelt.');}
    public function grid(string $world):Grid{$p=$this->settings($world)['preset']??[];return new Grid((int)($p['size']??32),(int)($p['road']??7),(int)($p['offsetX']??0),(int)($p['offsetZ']??0));}
    public function keyAt(Position $position):?string{
        $world=$position->getWorld()->getFolderName();return !$this->managed($world)||!$this->ready()?null:$this->plots->at($world,$this->grid($world),$position->getFloorX(),$position->getFloorZ());
    }

    public function getPlotAt(Position $position):?array{$key=$this->keyAt($position);return $key===null?null:$this->repo->get($key);}
    public function admin(CommandSender $sender):bool{return $sender->hasPermission('plots.admin.*');}
    public function actor(CommandSender $sender):string{return $sender instanceof Player?$sender->getUniqueId()->toString():'console';}
    public function limit(CommandSender $sender,string $kind='plot'):int{
        if($this->admin($sender)){return 256;}$n=0;
        foreach($sender->getEffectivePermissions() as $info){if($info->getValue()&&preg_match('/^plots\.'.preg_quote($kind,'/').'\.([0-9]+)$/D',$info->getPermission(),$m)){$n=max($n,min(256,(int)$m[1]));}}
        if($kind==='plot'){$n+=(int)($this->repo->get('bp/preferences/'.$this->actor($sender))['grants']??0);}return min(256,$n);
    }

    public function online():array{return array_values(array_map(static fn(Player $p):string=>$p->getUniqueId()->toString(),$this->getServer()->getOnlinePlayers()));}
    public function identity(string $name):string{
        if($name==='*'){return '*';}
        $p=$this->getServer()->getPlayerExact($name);if($p!==null){return $p->getUniqueId()->toString();}
        return $this->repo->get('bp/player/'.strtolower($name))['uuid']??throw new \InvalidArgumentException('Spieler muss mindestens einmal auf diesem Server gewesen sein.');
    }
    public function say(CommandSender $to,string $text):void{
        if($to instanceof Player&&!$to->isConnected()){return;}
        $to->sendMessage((string)$this->getConfig()->getNested('messages.prefix','§bBetterPlots §8» §r').\BetterPlots\Text::renderDeferred($to,($this->messages[$text]??$text)));
    }

    public function sayRaw(CommandSender $to,string $text):void{
        if($to instanceof Player&&!$to->isConnected()){return;}
        $to->sendMessage((string)$this->getConfig()->getNested('messages.prefix','§bBetterPlots §8» §r').$text);
    }

    public function flag(?array $plot,string $world,string $name,mixed $default=null):mixed{
        $settings=$this->settings($world);
        return $plot===null?($settings['road-flags'][$name]??$default):($plot['flags'][$name]??$settings['flags'][$name]??$default);
    }
    public function isProtectedBorder(Position $pos):bool{
        $world=$pos->getWorld()->getFolderName();if(!$this->managed($world)||!$this->ready()){return false;}
        $plot=$this->getPlotAt($pos);if($plot===null){return false;}
        $height=(int)($this->settings($world)['preset']['height']??64);
        if($pos->getFloorY()===$height+1&&!$this->flag($plot,$world,'border',true)){return false;}
        return \BetterPlots\Domain\Border::contains($this->grid($world),$plot['cells'],(int)($this->settings($world)['preset']['height']??64),$pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ(),$pos->getWorld()->getMinY());
    }
    public function canBuild(Player $p,Position $pos):bool{
        $world=$pos->getWorld()->getFolderName();if(!$this->managed($world)){return true;}
        if(!$this->ready()){return false;}
        if($this->admin($p)){return true;}
        if($this->edits->locked($world,$pos->getFloorX(),$pos->getFloorZ())){return false;}
        if($this->hasBuildBypass($p)){return true;}$plot=$this->getPlotAt($pos);
        return $plot!==null&&Access::build($plot,$p->getUniqueId()->toString(),$this->online());
    }
    public function hasBuildBypass(Player $p):bool{return $this->admin($p)&&($this->buildBypass[spl_object_id($p)]??false);}
    public function toggleBuildBypass(Player $p):bool{
        if(!$this->admin($p)){throw new \RuntimeException('Keine Adminrechte.');}
        return $this->buildBypass[spl_object_id($p)]=!$this->hasBuildBypass($p);
    }
    public function forgetBypass(Player $p):void{unset($this->buildBypass[spl_object_id($p)]);}
    public function executePlot(Player $p,array $args):void{
        try{$this->router->run($p,$args);}catch(\Throwable $e){$this->say($p,$e->getMessage());}
    }

    public function event(string $type,CommandSender $sender,string $key,array $before,array $after):bool{
        $class='BetterPlots\\Event\\'.$type.'Event';
        if(!is_subclass_of($class,\BetterPlots\Event\PlotMutationEvent::class)){throw new \LogicException('Unbekannter Plot-Event.');}
        $event=new $class($this->actor($sender),$key,$before,$after);$event->call();return !$event->isCancelled();
    }
}
