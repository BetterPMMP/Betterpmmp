<?php

/*
 * BetterPMMP - armor stand.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\world\sound\ItemBreakSound;

/**
 * Vanilla armor stand: holds four armor pieces and an item in its hand.
 * Using it with armor puts the piece on (and gives back the old one), with
 * another item puts it in the hand, with an empty hand takes the piece you
 * click at (head, chest, legs, feet) or the held item. Sneak-using changes the
 * pose (13 poses). A hit breaks it and drops everything.
 */
class ArmorStand extends Living{

	private const POSES = 13;

	private Item $heldItem;
	private int $pose = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::ARMOR_STAND; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.975, 0.5); }
	public function getName() : string{ return "Armor Stand"; }
	public function canBreathe() : bool{ return true; }
	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{}
	public function getDrops() : array{ return []; }
	public function getXpDropAmount() : int{ return 0; }

	protected function initEntity(CompoundTag $nbt) : void{
		$this->heldItem = VanillaItems::AIR();
		parent::initEntity($nbt);
		$this->setMaxHealth(6);
		$this->pose = $nbt->getInt("Pose", 0) % self::POSES;
		$armor = $nbt->getListTag("BetterPMMPArmor");
		if($armor !== null){
			foreach($armor as $slot => $tag){
				if($tag instanceof CompoundTag && $slot < 4){
					try{ $this->armorInventory->setItem($slot, Item::nbtDeserialize($tag)); }catch(\Exception){}
				}
			}
		}
		$hand = $nbt->getCompoundTag("BetterPMMPHand");
		if($hand !== null){
			try{ $this->heldItem = Item::nbtDeserialize($hand); }catch(\Exception){}
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT()->setInt("Pose", $this->pose);
		$armor = [];
		for($slot = 0; $slot < 4; ++$slot){
			$armor[] = $this->armorInventory->getItem($slot)->nbtSerialize();
		}
		$nbt->setTag("BetterPMMPArmor", new ListTag($armor));
		$nbt->setTag("BetterPMMPHand", $this->heldItem->nbtSerialize());
		return $nbt;
	}

	public function getHeldItem() : Item{ return clone $this->heldItem; }

	public function setHeldItem(Item $item) : void{
		$this->heldItem = clone $item;
		foreach($this->getViewers() as $viewer){
			$this->sendHeldItem($viewer);
		}
	}

	private function sendHeldItem(Player $player) : void{
		$session = $player->getNetworkSession();
		$session->sendDataPacket(MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($this->heldItem)), 0, 0, ContainerIds::INVENTORY));
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		$this->sendHeldItem($player);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::ARMOR_STAND_POSE_INDEX, $this->pose);
	}

	private function give(Player $player, Item $item) : void{
		if($item->isNull()){
			return;
		}
		foreach($player->getInventory()->addItem($item) as $rest){
			$this->getWorld()->dropItem($this->location, $rest);
		}
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSpectator()){
			return false;
		}
		if($player->isSneaking()){
			$this->pose = ($this->pose + 1) % self::POSES;
			$this->networkPropertiesDirty = true;
			return true;
		}
		$held = $player->getInventory()->getItemInHand();
		if($held instanceof Armor){
			$slot = $held->getArmorSlot();
			$old = $this->armorInventory->getItem($slot);
			$this->armorInventory->setItem($slot, (clone $held)->setCount(1));
			$held->pop();
			$player->getInventory()->setItemInHand($held);
			$this->give($player, $old);
			return true;
		}
		if(!$held->isNull()){
			$old = $this->heldItem;
			$this->setHeldItem((clone $held)->setCount(1));
			$held->pop();
			$player->getInventory()->setItemInHand($held);
			$this->give($player, $old);
			return true;
		}
		// empty hand: take the piece at the clicked height, else whatever is there
		$height = $clickPos->y - $this->location->y;
		if($height < 0 || $height > 2.2){
			$height = $clickPos->y; // relative click position
		}
		$wanted = $height >= 1.6 ? ArmorInventory::SLOT_HEAD : ($height >= 0.9 ? ArmorInventory::SLOT_CHEST : ($height >= 0.4 ? ArmorInventory::SLOT_LEGS : ArmorInventory::SLOT_FEET));
		if($wanted === ArmorInventory::SLOT_CHEST && !$this->heldItem->isNull() && $this->armorInventory->getItem($wanted)->isNull()){
			$this->give($player, $this->heldItem);
			$this->setHeldItem(VanillaItems::AIR());
			return true;
		}
		foreach([$wanted, ArmorInventory::SLOT_HEAD, ArmorInventory::SLOT_CHEST, ArmorInventory::SLOT_LEGS, ArmorInventory::SLOT_FEET] as $slot){
			$piece = $this->armorInventory->getItem($slot);
			if(!$piece->isNull()){
				$this->armorInventory->setItem($slot, VanillaItems::AIR());
				$this->give($player, $piece);
				return true;
			}
		}
		if(!$this->heldItem->isNull()){
			$this->give($player, $this->heldItem);
			$this->setHeldItem(VanillaItems::AIR());
			return true;
		}
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->isFlaggedForDespawn() || $source->getCause() === EntityDamageEvent::CAUSE_FALL || $source->getCause() === EntityDamageEvent::CAUSE_DROWNING || $source->getCause() === EntityDamageEvent::CAUSE_SUFFOCATION){
			$source->cancel();
			return;
		}
		$creative = $source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Player && $damager->isCreative();
		$world = $this->getWorld();
		if(!$creative && ($stand = ExtraVanillaItems::get("minecraft:armor_stand")) !== null){
			$world->dropItem($this->location, $stand);
		}
		foreach($this->armorInventory->getContents() as $item){
			$world->dropItem($this->location->add(0, 1, 0), $item);
		}
		if(!$this->heldItem->isNull()){
			$world->dropItem($this->location->add(0, 1, 0), $this->heldItem);
		}
		$this->armorInventory->clearAll();
		$world->addSound($this->location, new ItemBreakSound());
		$this->flagForDespawn();
	}
}
