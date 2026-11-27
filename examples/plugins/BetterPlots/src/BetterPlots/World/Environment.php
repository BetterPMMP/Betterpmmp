<?php
declare(strict_types=1);
namespace BetterPlots\World;

use BetterPlots\Main;
use BetterPlots\Domain\Access;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\player\{Player,GameMode};
use pocketmine\network\mcpe\protocol\{SetTimePacket,LevelEventPacket};
use pocketmine\network\mcpe\protocol\types\LevelEvent;

final class Environment implements Listener{

    private array $states=[];
    private bool $sending=false;private int $hotbarTick=0;
    private array $clearWeatherWorlds=[];
    private array $dayWorlds=[];
    public function __construct(private Main $plugin){}
    public function tick():void{
        ++$this->hotbarTick;
        foreach($this->plugin->getServer()->getWorldManager()->getWorlds() as $world){
            $name=$world->getFolderName();
            if($this->plugin->managed($name)){
                $always=(bool)($this->plugin->settings($name)['always-day']??false);
                if($always&&!isset($this->dayWorlds[$world->getId()])){$world->setTime(6000);$world->stopTime();$this->dayWorlds[$world->getId()]=true;}
                elseif(!$always&&isset($this->dayWorlds[$world->getId()])){$world->startTime();unset($this->dayWorlds[$world->getId()]);}
            }
            if($this->plugin->managed($name)&&!isset($this->clearWeatherWorlds[$world->getId()])){$world->getWeather()->set(false,false,2147483647);$this->clearWeatherWorlds[$world->getId()]=true;}
        }
        foreach($this->plugin->getServer()->getOnlinePlayers() as $p){
            $uuid=$p->getUniqueId()->toString();$key=$this->plugin->keyAt($p->getPosition());
            $plot=$key===null?null:$this->plugin->repo->get($key);
            if($this->hotbarTick%20===0&&$this->plugin->managed($p->getWorld()->getFolderName())){
                $cell=$this->plugin->grid($p->getWorld()->getFolderName())->locate($p->getPosition()->getFloorX(),$p->getPosition()->getFloorZ());
                if($plot!==null){$p->sendActionBarMessage(\BetterPlots\Text::t($p,'§bPlot §f{plot} §7| Besitzer: §e{owner}',['plot'=>$plot['cells'][0],'owner'=>$plot['ownerName']]));}
                elseif($cell!==null){$p->sendActionBarMessage(\BetterPlots\Text::t($p,'§aFreies Plot §7| §e/p claim'));}

            }
            $fingerprint=$key===null?'':$key.':'.hash('sha256',json_encode($plot['flags'],JSON_THROW_ON_ERROR));
            if(($this->states[$uuid]['fingerprint']??'')!==$fingerprint){
                $old=$this->states[$uuid]??null;
                if($old!==null){
                    $this->restore($p);
                    $before=$old['plot'];$this->plugin->event('Leave',$p,$old['key'],$before,[]);
                    if(($before['flags']['farewell']??'')!==''){$this->plugin->sayRaw($p,$before['flags']['farewell']);}
                    $this->notify($before,'notify-leave',$p);
                }
                if($plot!==null){
                    if(!$this->plugin->event('Enter',$p,$key,[],$plot)){
                        $p->teleport($p->getWorld()->getSpawnLocation());continue;
                    }
                    $this->states[$uuid]=['fingerprint'=>$fingerprint,'key'=>$key,'plot'=>$plot,'mode'=>$p->getGamemode(),'fly'=>$p->getAllowFlight(),'appliedMode'=>null,'appliedFly'=>null,'healAt'=>time(),'feedAt'=>time()];
                    $world=$plot['world'];$role=Access::role($plot,$uuid);
                    $gm=$this->plugin->flag($plot,$world,$role==='guest'?'guest-gamemode':'gamemode','default');
                    if($gm!=='default'&&($mode=GameMode::fromString((string)$gm))!==null&&$p->setGamemode($mode)){$this->states[$uuid]['appliedMode']=$mode;}
                    if($this->plugin->flag($plot,$world,'fly',false)){$p->setAllowFlight(true);$this->states[$uuid]['appliedFly']=true;}
                    $greeting=$this->plugin->flag($plot,$world,'greeting','');if($greeting!==''){$this->plugin->sayRaw($p,(string)$greeting);}
                    $pref=$this->plugin->repo->get('bp/preferences/'.$uuid)??[];

                    $this->notify($plot,'notify-enter',$p);
                    $this->sendOverrides($p,$plot);
                }
            }
            if($plot!==null){
                foreach(['heal','feed'] as $flag){
                    $value=$this->plugin->flag($plot,$plot['world'],$flag);
                    if(is_array($value)&&time()>=($this->states[$uuid][$flag.'At']??PHP_INT_MAX)+$value[0]){
                        $amount=$value[1]?:1;
                        if($flag==='heal'){$p->setHealth(min($p->getMaxHealth(),$p->getHealth()+$amount));}
                        else{$h=$p->getHungerManager();$h->setFood(min(20,$h->getFood()+$amount));}
                        $this->states[$uuid][$flag.'At']=time();
                    }
                }
            }
        }
    }
    private function notify(array $plot,string $flag,Player $visitor):void{
        if(!($plot['flags'][$flag]??false)){return;}
        foreach($this->plugin->getServer()->getOnlinePlayers() as $p){if(in_array($p->getUniqueId()->toString(),$plot['owners'],true)){$this->plugin->sayRaw($p,\BetterPlots\Text::t($p,$flag==='notify-enter'?'{player} betritt {plot}':'{player} verlässt {plot}',['player'=>$visitor->getName(),'plot'=>$plot['cells'][0]]));}}
    }
    private function sendOverrides(Player $p,array $plot):void{
        $time=$this->plugin->flag($plot,$plot['world'],'time');$weather=$this->plugin->flag($plot,$plot['world'],'weather','default');
        $this->sending=true;
        try{
            if(is_int($time)){$p->getNetworkSession()->sendDataPacket(SetTimePacket::create($time));}
            if($weather!=='default'){
                $p->getNetworkSession()->sendDataPacket(LevelEventPacket::create($weather==='clear'?LevelEvent::STOP_RAIN:LevelEvent::START_RAIN,$weather==='clear'?0:65535,null));
                $p->getNetworkSession()->sendDataPacket(LevelEventPacket::create($weather==='thunder'?LevelEvent::START_THUNDER:LevelEvent::STOP_THUNDER,$weather==='thunder'?65535:0,null));
            }
        }finally{$this->sending=false;}
    }
    public function packets(DataPacketSendEvent $e):void{
        if($this->sending||!$this->plugin->ready()){return;}
        $relevant=false;
        foreach($e->getPackets() as $packet){if($packet instanceof SetTimePacket||($packet instanceof LevelEventPacket&&in_array($packet->eventId,[LevelEvent::START_RAIN,LevelEvent::STOP_RAIN,LevelEvent::START_THUNDER,LevelEvent::STOP_THUNDER],true))){$relevant=true;break;}}
        if(!$relevant){return;}
        $needs=false;foreach($e->getTargets() as $session){$p=$session->getPlayer();if($p!==null&&isset($this->states[$p->getUniqueId()->toString()])){$needs=true;break;}}
        if(!$needs){return;}$e->cancel();$this->sending=true;
        try{
            foreach($e->getTargets() as $session){
                $p=$session->getPlayer();$plot=$p===null?null:($this->states[$p->getUniqueId()->toString()]['plot']??null);
                foreach($e->getPackets() as $packet){
                    $replacement=$packet;
                    if($plot!==null){
                        if($packet instanceof SetTimePacket){$time=$this->plugin->flag($plot,$plot['world'],'time');if(is_int($time)){$replacement=SetTimePacket::create($time);}}
                        if($packet instanceof LevelEventPacket){
                            $weather=$this->plugin->flag($plot,$plot['world'],'weather','default');
                            if($weather!=='default'){
                                if(in_array($packet->eventId,[LevelEvent::START_RAIN,LevelEvent::STOP_RAIN],true)){$replacement=LevelEventPacket::create($weather==='clear'?LevelEvent::STOP_RAIN:LevelEvent::START_RAIN,$weather==='clear'?0:65535,null);}
                                elseif(in_array($packet->eventId,[LevelEvent::START_THUNDER,LevelEvent::STOP_THUNDER],true)){$replacement=LevelEventPacket::create($weather==='thunder'?LevelEvent::START_THUNDER:LevelEvent::STOP_THUNDER,$weather==='thunder'?65535:0,null);}
                            }
                        }
                    }
                    $session->sendDataPacket($replacement);
                }
            }
        }finally{$this->sending=false;}
    }
    public function quit(PlayerQuitEvent $e):void{$this->restore($e->getPlayer());}
    public function restore(Player $p):void{
        $uuid=$p->getUniqueId()->toString();$state=$this->states[$uuid]??null;unset($this->states[$uuid]);if($state===null){return;}
        if($state['appliedMode']!==null&&$p->getGamemode()===$state['appliedMode']){$p->setGamemode($state['mode']);}
        if($state['appliedFly']!==null&&$p->getAllowFlight()===$state['appliedFly']){$p->setAllowFlight($state['fly']);}
        if($p->isConnected()){
            $this->sending=true;try{$p->getNetworkSession()->sendDataPacket(SetTimePacket::create($p->getWorld()->getTime()));$p->getWorld()->getWeather()->sendTo($p);}finally{$this->sending=false;}
        }
    }
    public function restoreAll():void{foreach($this->plugin->getServer()->getOnlinePlayers() as $p){$this->restore($p);}}
}
