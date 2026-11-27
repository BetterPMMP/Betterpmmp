<?php
declare(strict_types=1);
namespace BetterPlots\Storage;
use BetterPlots\Main;
use BetterPlots\Generator\LegacyPreset;

final class LegacyImport implements \pocketmine\event\Listener{
    private bool $started=false,$waiting=false,$complete=false;
    private array $sources=[],$updates=[],$knownWorlds=[];
    private ?string $failure=null;
    public function __construct(private Main $p){}
    public function ready():bool{return $this->complete;}
    public function status():string{if($this->failure!==null){return \BetterPlots\Text::defer('Plot-Uebernahme angehalten: {error}',['error'=>\BetterPlots\Text::ref($this->failure)]);}if($this->waiting){return 'Plot-Daten werden gelesen oder gespeichert.';}if($this->updates!==[]){return \BetterPlots\Text::defer('Plot-Uebernahme: noch {count} Datensaetze.',['count'=>count($this->updates)]);}return $this->complete?'Plot-Daten bereit.':'Plot-Uebernahme wird vorbereitet.';}
    public function contains(string $world):bool{return isset($this->knownWorlds[$world]);}
    public function tick():void{
        if($this->failure!==null||$this->complete||$this->waiting||$this->p->repo->busy()){return;}
        try{
            if(!$this->started){$this->started=true;$this->discover();}
            if($this->updates!==[]){$batch=array_slice($this->updates,0,200,true);$this->waiting=true;$this->p->repo->commit($batch,function(bool $ok)use($batch):void{$this->waiting=false;if(!$ok){$this->fail('Import konnte nicht gespeichert werden.');return;}foreach($batch as $k=>$_){unset($this->updates[$k]);}});return;}
            if($this->sources===[]){foreach($this->p->repo->all('bp/world/') as $settings){if(isset($settings['legacy-source'])){$this->p->adoptExistingWorld($settings['name']);}}foreach($this->p->getServer()->getWorldManager()->getWorlds() as $w){if(in_array(strtolower($w->getProvider()->getWorldData()->getGenerator()),['myplot','cplot_plot'],true)&&$this->p->repo->get('bp/world/'.$w->getFolderName())===null){throw new \RuntimeException(\BetterPlots\Text::defer('Legacy-Welt ohne importierbare Besitzerdaten: {v1}',['v1'=>$w->getFolderName()]));}}$this->complete=true;$this->p->plots->rebuild();return;}
            $source=array_shift($this->sources);$marker='bp/migration/'.strtolower($source['kind']);
            if($this->p->repo->get($marker)!==null){return;}
            $this->waiting=true;
            $this->p->database->submit(['op'=>'legacy-read','source'=>$source],function(array $reply)use($source,$marker):void{
                $this->waiting=false;
                try{if(!($reply['ok']??false)){throw new \RuntimeException(\BetterPlots\Text::defer('Quelldaten konnten nicht gelesen werden: {v1}',['v1'=>\BetterPlots\Text::ref($reply['error']??'unbekannter Fehler')]));}
                    $data=$reply['value'];$data['world-names']=$source['world-names']??[];$converted=LegacyConverter::convert($source['kind'],$data);$changes=[];
                    foreach($converted['worlds'] as $world){$this->knownWorlds[$world]=true;
                        $existing=$this->p->repo->get('bp/world/'.$world);if($existing!==null&&($existing['legacy-source']??null)!==$source['kind']){throw new \RuntimeException(\BetterPlots\Text::defer('Zielwelt bereits von BetterPlots verwaltet: {v1}',['v1'=>$world]));}
                        $w=$this->p->adoptExistingWorld($world);$wd=$w->getProvider()->getWorldData();$gen=strtolower($wd->getGenerator());
                        if(!in_array($gen,['myplot','cplot_plot'],true)){throw new \RuntimeException(\BetterPlots\Text::defer('Unbekannter Legacy-Generator {v1} in {v2}',['v1'=>$gen,'v2'=>$world]));}
                        $options=$wd->getGeneratorOptions()===''?[]:json_decode($wd->getGeneratorOptions(),true,32,JSON_THROW_ON_ERROR);
                        if($source['kind']==='CPlot'){foreach($data['worlds'] as $r){if($r['worldName']===$world){$options=array_replace($options,$r);break;}}}
                        $preset=LegacyPreset::convert($options,$gen==='myplot');new \BetterPlots\Generator\PlotGenerator(0,json_encode($preset,JSON_THROW_ON_ERROR));
                        $changes['bp/world/'.$world]=['name'=>$world,'generator'=>$gen,'preset'=>$preset,'flags'=>[],'road-flags'=>[],'legacy-source'=>$source['kind'],'legacy-preserve'=>true,'legacy-options'=>$options];
                    }
                    foreach($this->p->repo->all('bp/plot/') as $key=>$p){if(in_array($p['world'],$converted['worlds'],true)&&($p['legacy-source']??null)!==$source['kind']){throw new \RuntimeException(\BetterPlots\Text::defer('Bestehende BetterPlots-Claims in Importwelt: {v1}',['v1'=>$p['world']]));}}
                    foreach($converted['plots'] as $id=>$p){$p['legacy-source']=$source['kind'];$changes['bp/plot/legacy-'.hash('sha256',$source['kind'].'/'.$id)]=$p;}

                    $changes[$marker]=['source'=>$source['path'],'completed'=>time(),'plots'=>count($converted['plots']),'worlds'=>$converted['worlds']];$this->updates=$changes;
                    $this->p->getLogger()->info($source['kind'].': '.count($converted['plots']).' Plotgruppen werden uebernommen; Weltbloecke bleiben erhalten.');
                }catch(\Throwable $e){$this->fail($e->getMessage());}
            });
        }catch(\Throwable $e){$this->fail($e->getMessage());}
    }
    private function discover():void{
        $base=dirname(rtrim($this->p->getDataFolder(),'/\\'));
        foreach(['MyPlot','CPlot'] as $kind){
            if($this->p->repo->get('bp/migration/'.strtolower($kind))!==null){continue;}
            $dir=$base.DIRECTORY_SEPARATOR.$kind;if(!is_dir($dir)){$dir=rtrim($this->p->getServer()->getPluginPath(),'/\\').DIRECTORY_SEPARATOR.$kind;}$cfg=$dir.'/config.yml';if(!is_file($cfg)){continue;}
            if($this->p->getServer()->getPluginManager()->getPlugin($kind)!==null){throw new \RuntimeException(\BetterPlots\Text::defer('{v1}-PHAR entfernen, Datenordner und Welten behalten, dann neu starten. Nicht beide Plotsysteme gleichzeitig laden.',['v1'=>$kind]));}
            $c=yaml_parse_file($cfg);if(!is_array($c)){throw new \RuntimeException('Legacy-Konfiguration unlesbar.');}
            if($kind==='CPlot'){$format=strtolower((string)($c['database']['type']??'sqlite'));$file=(string)($c['database']['sqlite']['file']??'CPlot.sqlite');$path=preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$file)?$file:$dir.'/'.$file;}
            else{$format=strtolower((string)($c['DataProvider']??'sqlite'));$path=$dir.'/'.match($format){'sqlite'=>'plots.db','json'=>'Data/plots.json','yaml'=>'Data/plots.yml',default=>''};}
            if($format==='mysql'){throw new \RuntimeException(\BetterPlots\Text::defer('{v1}: MySQL-Import wird noch nicht automatisch unterstuetzt. Quelle unveraendert; Plotzugriff gesperrt.',['v1'=>$kind]));}
            $names=[];if($kind==='MyPlot'){foreach(glob($dir.'/worlds/*.yml')?:[] as $file){$names[]=basename($file,'.yml');}}
            $this->sources[]=['kind'=>$kind,'format'=>$format,'path'=>$path,'world-names'=>$names];
        }
    }
    public function worldLoaded(\pocketmine\event\world\WorldLoadEvent $event):void{
        $world=$event->getWorld();if($this->complete&&in_array(strtolower($world->getProvider()->getWorldData()->getGenerator()),['myplot','cplot_plot'],true)&&$this->p->repo->get('bp/world/'.$world->getFolderName())===null){$this->complete=false;$this->fail(\BetterPlots\Text::defer('Nachtraeglich geladene Legacy-Welt ohne importierte Besitzerdaten: {world}',['world'=>$world->getFolderName()]));}
    }
    private function fail(string $reason):void{$this->failure=$reason;$this->p->getLogger()->error('Legacy-Uebernahme angehalten: '.$reason.' BetterPlots bleibt gesperrt, damit keine Claims verloren gehen.');}

    public function bind(string $name,string $uuid):bool{
        $needle='legacy-name:'.strtolower($name);$updates=[];
        foreach($this->p->repo->all('bp/plot/') as $key=>$plot){$changed=false;foreach(['owners','members','trusted','denied'] as $role){foreach($plot[$role] as &$id){if($id===$needle){$id=$uuid;$changed=true;}}unset($id);}if($changed){$updates[$key]=$plot;if(count($updates)>=200){break;}}}
        if($updates===[]){return false;}$this->p->plots->commit($updates,static function(bool $ok):void{});return true;
    }
}
