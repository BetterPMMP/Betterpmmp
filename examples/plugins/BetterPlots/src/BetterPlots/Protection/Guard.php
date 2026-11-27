<?php
declare(strict_types=1);
namespace BetterPlots\Protection;

use BetterPlots\Main;
use BetterPlots\Domain\Access;
use pocketmine\event\Listener;
use pocketmine\event\block\{BlockBreakEvent,BlockPlaceEvent,BlockSpreadEvent,BlockGrowEvent,BlockFormEvent,BlockMeltEvent,BlockBurnEvent,BlockUpdateEvent,BlockExplodeEvent,LeavesDecayEvent,SignChangeEvent,StructureGrowEvent};
use pocketmine\event\player\{PlayerInteractEvent,PlayerMoveEvent,PlayerBucketEmptyEvent,PlayerBucketFillEvent,PlayerDropItemEvent,PlayerItemUseEvent,PlayerEntityInteractEvent,PlayerDeathEvent,PlayerChatEvent};
use pocketmine\event\entity\{EntityTeleportEvent,EntityDamageEvent,EntityDamageByEntityEvent,EntityExplodeEvent,EntitySpawnEvent,EntityBlockChangeEvent,EntityItemPickupEvent,ProjectileLaunchEvent,EntityTrampleFarmlandEvent};
use pocketmine\event\server\CommandEvent;
use pocketmine\block\{Block,GenericBedrockBlock,Hopper,CarvedPumpkin,LitPumpkin,Pumpkin,TNT};
use pocketmine\entity\object\ItemEntity;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class Guard implements Listener{
    private array $noticeAt=[];
    public function __construct(private Main $plugin){}
    private function denied(Player $p,Position $pos):void{
        if(!$this->plugin->getConfig()->getNested('protection.denial-messages',true)){return;}
        $uuid=$p->getUniqueId()->toString();if(time()<($this->noticeAt[$uuid]??0)){return;}$this->noticeAt[$uuid]=time()+2;
        $locked=$this->plugin->edits->locked($pos->getWorld()->getFolderName(),$pos->getFloorX(),$pos->getFloorZ());
        $this->plugin->say($p,$locked?'Dieses Plot wird noch bearbeitet und gespeichert. Danach wird es automatisch freigegeben.':'Hier fehlen dir Baurechte oder dieser Block ist in Plotwelten gesperrt.');
    }
    public static function safeBlock(Block $block):bool{
        return !($block instanceof GenericBedrockBlock||$block instanceof Hopper||$block instanceof Pumpkin||$block instanceof CarvedPumpkin||$block instanceof LitPumpkin||$block instanceof TNT||$block instanceof \pocketmine\block\NetherPortal||$block instanceof \pocketmine\block\EndPortal||$block instanceof \pocketmine\block\EndPortalFrame);
    }
    private function redstone(Block $b):bool{return $b instanceof \pocketmine\block\utils\RedstoneSignalEmitter||$b instanceof \pocketmine\block\utils\PoweredByRedstone;}
    private function managed(Position $p):bool{return $this->plugin->managed($p->getWorld()->getFolderName());}
    private function flag(Position $p,string $name,mixed $default=false):mixed{return $this->plugin->flag($this->plugin->getPlotAt($p),$p->getWorld()->getFolderName(),$name,$default);}
    private function environmental(Position $p,string $flag,bool $default=false):bool{
        return !$this->managed($p)||($this->plugin->ready()&&$this->plugin->keyAt($p)!==null&&!$this->plugin->edits->locked($p->getWorld()->getFolderName(),$p->getFloorX(),$p->getFloorZ())&&(bool)$this->flag($p,$flag,$default));
    }
    private function blockAllowed(Player $player,Block $block,string $flag):bool{
        $pos=$block->getPosition();if(!$this->managed($pos)){return true;}
        if(!$this->plugin->ready()){return false;}
        if($this->plugin->admin($player)){return true;}
        if($this->plugin->edits->locked($pos->getWorld()->getFolderName(),$pos->getFloorX(),$pos->getFloorZ())){return false;}
        if(in_array($flag,['break','place'],true)&&!$this->plugin->admin($player)&&$this->plugin->isProtectedBorder($pos)){return false;}
        if($this->plugin->canBuild($player,$pos)){return true;}
        $plot=$this->plugin->getPlotAt($pos);if($plot===null||!Access::enter($plot,$player->getUniqueId()->toString())){return false;}
        $allowed=$this->flag($pos,$flag,[]);$type=$block->getTypeId();
        foreach(is_array($allowed)?$allowed:[] as $name){$item=\pocketmine\item\StringToItemParser::getInstance()->parse($name);if($item!==null&&$item->getBlock()->getTypeId()===$type){return true;}}
        return false;
    }
    /** @priority HIGHEST */
    public function place(BlockPlaceEvent $e):void{
        if($this->plugin->admin($e->getPlayer())&&$this->plugin->ready()){return;}
        foreach($e->getTransaction()->getBlocks() as [$x,$y,$z,$block]){
            if($this->managed($block->getPosition())&&(!self::safeBlock($block)||($this->redstone($block)&&!$this->flag($block->getPosition(),'redstone',true))||!$this->blockAllowed($e->getPlayer(),$block,'place'))){$e->cancel();$this->denied($e->getPlayer(),$block->getPosition());return;}
        }
    }
    /** @priority HIGHEST */
    public function break(BlockBreakEvent $e):void{

        if($this->plugin->admin($e->getPlayer())&&$this->plugin->ready()){return;}
        foreach([$e->getBlock(),...$e->getBlock()->getAffectedBlocks()] as $block){if(!$this->blockAllowed($e->getPlayer(),$block,'break')){$e->cancel();$this->denied($e->getPlayer(),$block->getPosition());return;}}
        $p=$e->getBlock()->getPosition();if($this->managed($p)){
            if(!$this->flag($p,'tile-drop',true)){$e->setDrops([]);$e->setXpDropAmount(0);}
        }
    }
    /** @priority HIGHEST */
    public function interact(PlayerInteractEvent $e):void{
        if($this->plugin->admin($e->getPlayer())&&$this->plugin->ready()){return;}
        $p=$e->getBlock()->getPosition();if(!$this->managed($p)){return;}
        if(!$this->plugin->ready()){$e->cancel();return;}
        if($e->getAction()===PlayerInteractEvent::RIGHT_CLICK_BLOCK&&$e->getItem() instanceof \pocketmine\item\Food&&!$this->plugin->canBuild($e->getPlayer(),$p)){$e->setUseBlock(false);return;}
        if($e->getItem() instanceof \pocketmine\item\FlintSteel||$e->getItem() instanceof \pocketmine\item\FireCharge){$e->cancel();return;}
        if(!self::safeBlock($e->getBlock())||($this->redstone($e->getBlock())&&!$this->flag($p,'redstone',true))||!$this->blockAllowed($e->getPlayer(),$e->getBlock(),'use')){$e->cancel();return;}

        $side=$e->getBlock()->getSide($e->getFace())->getPosition();
        if(!$this->plugin->canBuild($e->getPlayer(),$side)){$e->setUseItem(false);}
    }
    private function bucket(\pocketmine\event\player\PlayerBucketEvent $e):void{
        foreach([$e->getBlockClicked(),$e->getBlockClicked()->getSide($e->getBlockFace())] as $block){if(!$this->plugin->canBuild($e->getPlayer(),$block->getPosition())){$e->cancel();}}
    }
    public function emptyBucket(PlayerBucketEmptyEvent $e):void{$this->bucket($e);}
    public function fillBucket(PlayerBucketFillEvent $e):void{$this->bucket($e);}
    private function allowedEnter(Player $p,Position $from,Position $to):bool{
        if($this->plugin->admin($p)){return true;}
        if(!$this->plugin->ready()){return $from->getWorld()===$to->getWorld()||!$this->managed($to);}
        $source=$this->plugin->keyAt($from);$dest=$this->plugin->keyAt($to);
        if($this->managed($from)&&$source!==$dest&&$this->flag($from,'deny-exit',false)){return false;}
        if(!$this->managed($to)){return true;}if(!$this->plugin->ready()){return false;}
        $plot=$this->plugin->getPlotAt($to);return $plot===null||Access::enter($plot,$p->getUniqueId()->toString());
    }
    public function move(PlayerMoveEvent $e):void{if(!$this->allowedEnter($e->getPlayer(),$e->getFrom(),$e->getTo())){$e->cancel();}}
    public function teleport(EntityTeleportEvent $e):void{
        $p=$e->getEntity();if(!$p instanceof Player){return;}if($this->plugin->spawnSafety->rescuing($p)){return;}
        if(!$this->allowedEnter($p,$e->getFrom(),$e->getTo())){$e->cancel();return;}
        if($this->managed($e->getTo())&&!$this->plugin->admin($p)){
            $plot=$this->plugin->getPlotAt($e->getTo());if($plot===null){return;}$role=Access::role($plot,$p->getUniqueId()->toString());
            $denied=match($this->flag($e->getTo(),'deny-teleport')){'members'=>$role==='member','trusted'=>$role==='trusted','nonmembers'=>!in_array($role,['owner','member','trusted'],true),'nontrusted'=>!in_array($role,['owner','trusted'],true),'nonowners'=>$role!=='owner',default=>false};if($denied){$e->cancel();}
        }
    }
    public function spread(BlockSpreadEvent $e):void{
        $p=$e->getBlock()->getPosition();$source=$e->getSource()->getPosition();if(!$this->managed($p)&&!$this->managed($source)){return;}
        $name=strtolower($e->getSource()->getName());$flag=match(true){str_contains($name,'grass')=>'grass-grow',str_contains($name,'mycel')=>'mycel-grow',str_contains($name,'vine')=>'vine-grow',str_contains($name,'fire')=>'block-ignition',default=>'liquid-flow'};
        $value=$this->flag($p,$flag,$flag==='liquid-flow'?'true':true);
        if(!$this->plugin->ready()||$this->plugin->keyAt($p)===null||$this->plugin->keyAt($p)!==$this->plugin->keyAt($source)||$value===false||$value==='false'){$e->cancel();}
    }
    public function grow(BlockGrowEvent $e):void{if(!$this->environmental($e->getBlock()->getPosition(),'crop-grow',true)){$e->cancel();}}
    public function form(BlockFormEvent $e):void{$name=strtolower($e->getNewState()->getName());$flag=str_contains($name,'ice')?'ice-form':(str_contains($name,'snow')?'snow-form':'concrete-harden');if(!$this->environmental($e->getBlock()->getPosition(),$flag,$flag==='concrete-harden')){$e->cancel();}}
    public function melt(BlockMeltEvent $e):void{$flag=str_contains(strtolower($e->getBlock()->getName()),'snow')?'snow-melt':'ice-melt';if(!$this->environmental($e->getBlock()->getPosition(),$flag,$flag==='snow-melt')){$e->cancel();}}
    public function burn(BlockBurnEvent $e):void{if(!$this->environmental($e->getBlock()->getPosition(),'block-burn')){$e->cancel();}}
    public function decay(LeavesDecayEvent $e):void{if(!$this->environmental($e->getBlock()->getPosition(),'leaf-decay',true)){$e->cancel();}}
    public function update(BlockUpdateEvent $e):void{
        $p=$e->getBlock()->getPosition();if($this->managed($p)&&(!$this->plugin->ready()||$this->plugin->keyAt($p)===null||$this->flag($p,'disable-physics',false)||($this->redstone($e->getBlock())&&!$this->flag($p,'redstone',true))||!self::safeBlock($e->getBlock()))){$e->cancel();}
    }
    public function sign(SignChangeEvent $e):void{if(!$this->plugin->canBuild($e->getPlayer(),$e->getBlock()->getPosition())&&!$this->environmental($e->getBlock()->getPosition(),'edit-sign')){$e->cancel();}}
    public function structure(StructureGrowEvent $e):void{
        $origin=$e->getBlock()->getPosition();if(!$this->managed($origin)){return;}$key=$this->plugin->keyAt($origin);
        foreach($e->getTransaction()->getBlocks() as [$x,$y,$z,$block]){if($key===null||$this->plugin->keyAt(new Position($x,$y,$z,$origin->getWorld()))!==$key||!self::safeBlock($block)){$e->cancel();return;}}
    }
    public function entityExplosion(EntityExplodeEvent $e):void{
        if($this->managed($e->getPosition())){$e->cancel();return;}
        $e->setBlockList(array_values(array_filter($e->getBlockList(),fn(Block $b):bool=>!$this->managed($b->getPosition()))));
        $e->setIgnitions([]);
    }
    public function blockExplosion(BlockExplodeEvent $e):void{
        if($this->managed($e->getPosition())){$e->cancel();return;}
        $e->setAffectedBlocks(array_values(array_filter($e->getAffectedBlocks(),fn(Block $b):bool=>!$this->managed($b->getPosition()))));$e->setIgnitions([]);
    }
    public function entityBlock(EntityBlockChangeEvent $e):void{if($this->managed($e->getBlock()->getPosition())){$e->cancel();}}
    public function trample(EntityTrampleFarmlandEvent $e):void{if($this->managed($e->getBlock()->getPosition())){$e->cancel();}}
    public function spawn(EntitySpawnEvent $e):void{
        $entity=$e->getEntity();if(!$this->managed($entity->getPosition())||$entity instanceof Player){return;}

        if(!$this->plugin->ready()){if(method_exists($entity,'getSplashSpawnerOrigin')&&$entity->getSplashSpawnerOrigin()!==null){$this->plugin->spawners->defer($entity,fn()=>$this->spawn(new EntitySpawnEvent($entity)));}else{$entity->flagForDespawn();}return;}
        if($entity instanceof \pocketmine\entity\Living){
            $origin=method_exists($entity,'getSplashSpawnerOrigin')?$entity->getSplashSpawnerOrigin():(method_exists($entity,'getBlockSpawnerOrigin')?$entity->getBlockSpawnerOrigin():null);
            $position=$entity->getPosition();
            if($origin===null||$origin->getString('world','')!==$position->getWorld()->getFolderName()){$entity->flagForDespawn();return;}
            $source=new Position($origin->getInt('x'),$origin->getInt('y'),$origin->getInt('z'),$position->getWorld());
            if(!PlotSpawnerRules::allows($this->plugin,$source,$position)){$entity->flagForDespawn();return;}
        }
        elseif(!$entity instanceof ItemEntity){$entity->flagForDespawn();return;}
        if(!PlotSpawnerRules::capacity($this->plugin,$entity->getPosition(),$entity)){$entity->flagForDespawn();}
    }
    public function damage(EntityDamageEvent $e):void{
        $p=$e->getEntity()->getPosition();if(!$this->managed($p)){return;}
        if(!$this->plugin->ready()||$this->plugin->keyAt($p)===null||$this->flag($p,'invincible',false)){$e->cancel();return;}
        if($e instanceof EntityDamageByEntityEvent){
            $damager=$e->getDamager();
            if($damager instanceof \pocketmine\entity\projectile\Projectile){$damager=$damager->getOwningEntity();}
            $victim=$e->getEntity();$pvp=$damager instanceof Player&&$victim instanceof Player;
            $mobCombat=$damager instanceof \pocketmine\entity\Living&&$victim instanceof \pocketmine\entity\Living&&(!$damager instanceof \pocketmine\entity\Human||!$victim instanceof \pocketmine\entity\Human);
            $allowed=(bool)$this->flag($p,$pvp?'pvp':'pve',false)||(!$pvp&&$mobCombat&&(bool)$this->flag($p,'mob-spawner',false));
            if($damager instanceof Player&&$victim instanceof \pocketmine\entity\Living&&!$victim instanceof \pocketmine\entity\Human&&$this->flag($p,'mob-combat','all')==='owner'){
                $plot=$this->plugin->getPlotAt($p);if($plot===null||!in_array($damager->getUniqueId()->toString(),$plot['owners']??[],true)){$e->cancel();return;}
            }
            if(!$allowed||$damager===null||$damager->getPosition()->getWorld()!==$p->getWorld()||$this->plugin->keyAt($damager->getPosition())!==$this->plugin->keyAt($p)){$e->cancel();}
        }
    }
    public function drop(PlayerDropItemEvent $e):void{if($this->managed($e->getPlayer()->getPosition())&&!$this->flag($e->getPlayer()->getPosition(),'item-drop',true)){$e->cancel();}}
    public function pickup(EntityItemPickupEvent $e):void{
        $entity=$e->getEntity();$pos=$e->getOrigin()->getPosition();
        if($entity instanceof Player&&$this->managed($pos)&&$this->flag($pos,'drop-protection',false)&&!$this->plugin->canBuild($entity,$pos)){$e->cancel();}
    }
    public function itemUse(PlayerItemUseEvent $e):void{
        if(!$e->getItem() instanceof \pocketmine\item\Food&&$this->managed($e->getPlayer()->getPosition())&&!$this->plugin->canBuild($e->getPlayer(),$e->getPlayer()->getPosition())){$e->cancel();}
    }
    public function projectile(ProjectileLaunchEvent $e):void{
        $projectile=$e->getEntity();$p=$projectile->getPosition();if(!$this->managed($p)){return;}
        $owner=$projectile->getOwningEntity();
        if(!$this->environmental($p,'mob-spawner')||!$owner instanceof \pocketmine\entity\Living||$owner->getPosition()->getWorld()!==$p->getWorld()||$this->plugin->keyAt($owner->getPosition())!==$this->plugin->keyAt($p)){$e->cancel();}
    }
    public function interactEntity(PlayerEntityInteractEvent $e):void{if(!$this->plugin->canBuild($e->getPlayer(),$e->getEntity()->getPosition())){$e->cancel();}}
    public function death(PlayerDeathEvent $e):void{
        $p=$e->getPlayer();

        if($this->managed($p->getPosition())&&$this->flag($p->getPosition(),'keep-inventory',false)){$e->setKeepInventory(true);$e->setKeepXp(true);}
    }
    public function command(CommandEvent $e):void{
        $p=$e->getSender();if(!$p instanceof Player||!$this->managed($p->getPosition())||$this->plugin->admin($p)){return;}
        $name=strtolower(ltrim(explode(' ',trim($e->getCommand()))[0],'/'));
        foreach((array)$this->flag($p->getPosition(),'blocked-cmds',[]) as $blocked){if($blocked==='*'||$name===strtolower(ltrim($blocked,'/'))){$e->cancel();return;}}
    }
    /** @priority HIGHEST */
    public function inventory(\pocketmine\event\inventory\InventoryTransactionEvent $e):void{
        foreach($e->getTransaction()->getInventories() as $inventory){

            if($inventory instanceof \BetterPlots\UI\MenuInventory){continue;}
            if($inventory instanceof \pocketmine\block\inventory\BlockInventory){
                $pos=$inventory->getHolder();
                if($this->managed($pos)&&!$this->blockAllowed($e->getTransaction()->getSource(),$pos->getWorld()->getBlockAt($pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ()),'use')){$e->cancel();return;}
            }
        }
    }
    public function chat(PlayerChatEvent $e):void{
        $p=$e->getPlayer();if(!$this->managed($p->getPosition())){return;}
        $settings=$this->plugin->repo->get('bp/preferences/'.$p->getUniqueId()->toString())??[];
        if(!($settings['chat']??false)){return;}$e->cancel();$key=$this->plugin->keyAt($p->getPosition());
        if($key===null){return;}
        foreach($this->plugin->getServer()->getOnlinePlayers() as $other){
            $spy=$this->plugin->repo->get('bp/preferences/'.$other->getUniqueId()->toString())['chatspy']??false;
            if($this->plugin->keyAt($other->getPosition())===$key||($spy&&$this->plugin->admin($other))){$other->sendMessage('§b[Plot] '.$p->getName().'§7: '.$e->getMessage());}
        }
    }
}
