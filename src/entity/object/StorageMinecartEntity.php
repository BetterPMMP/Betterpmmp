<?php

/*
 * BetterPMMP - minecarts with chest and hopper.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\tile\Container;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;

/** The inventory of a storage minecart; opened as an entity container. */
class MinecartInventory extends SimpleInventory{

	public function __construct(private StorageMinecartEntity $holder, int $size){
		parent::__construct($size);
	}

	public function getHolder() : StorageMinecartEntity{
		return $this->holder;
	}
}

/**
 * Base for minecarts with an inventory: right-click opens it (no riding),
 * breaking the cart drops its contents. The contents are saved as "Items".
 */
abstract class StorageMinecartEntity extends MinecartEntity{

	protected MinecartInventory $inventory;

	abstract public function getInventorySize() : int;
	abstract public function getWindowType() : int;

	public function getMaxRiders() : int{ return 0; }

	public function getInventory() : MinecartInventory{
		return $this->inventory;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->inventory = new MinecartInventory($this, $this->getInventorySize());
		parent::initEntity($nbt);
		$items = $nbt->getListTag(Container::TAG_ITEMS);
		if($items !== null){
			$contents = [];
			foreach($items as $tag){
				if($tag instanceof CompoundTag){
					$item = Item::nbtDeserialize($tag);
					$slot = $tag->getByte(\pocketmine\data\bedrock\item\SavedItemStackData::TAG_SLOT, 0);
					if($slot >= 0 && $slot < $this->inventory->getSize() && !$item->isNull()){
						$contents[$slot] = $item;
					}
				}
			}
			$this->inventory->setContents($contents);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}
		$nbt->setTag(Container::TAG_ITEMS, new ListTag($items));
		return $nbt;
	}

	public function getInteractButtonText(Player $player) : string{
		return "action.interact.opencontainer";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() && !$player->getInventory()->getItemInHand()->isNull()){
			return false;
		}
		$player->setCurrentWindow($this->inventory);
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($this->isFlaggedForDespawn()){
			$this->dropContents();
		}
	}

	private function dropContents() : void{
		foreach($this->inventory->getContents() as $item){
			$this->getWorld()->dropItem($this->location, $item);
		}
		$this->inventory->clearAll();
		$this->inventory->removeAllViewers();
	}

	protected function onDispose() : void{
		$this->inventory->removeAllViewers();
		parent::onDispose();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, $this->getWindowType());
		$properties->setInt(EntityMetadataProperties::CONTAINER_BASE_SIZE, $this->getInventorySize());
		$properties->setInt(EntityMetadataProperties::CONTAINER_EXTRA_SLOTS_PER_STRENGTH, 0);
	}
}

/** Minecart with chest: 27 slots. */
class ChestMinecartEntity extends StorageMinecartEntity{

	public static function getNetworkTypeId() : string{ return EntityIds::CHEST_MINECART; }
	protected function dropItemName() : ?string{ return "minecraft:chest_minecart"; }
	public function getInventorySize() : int{ return 27; }
	public function getWindowType() : int{ return WindowTypes::MINECART_CHEST; }
}

/**
 * Minecart with hopper: 5 slots, picks up dropped items and pulls items out of
 * containers above it (one item every 4 ticks, like a hopper block).
 * A powered activator rail locks it.
 */
class HopperMinecartEntity extends StorageMinecartEntity{

	private int $transferCooldown = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::HOPPER_MINECART; }
	protected function dropItemName() : ?string{ return "minecraft:hopper_minecart"; }
	public function getInventorySize() : int{ return 5; }
	public function getWindowType() : int{ return WindowTypes::MINECART_HOPPER; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && !$this->isFlaggedForDespawn() && !$this->isLocked()){
			$this->transferCooldown -= $tickDiff;
			if($this->transferCooldown <= 0){
				$this->transferCooldown = 4;
				if(!$this->pullFromAbove()){
					$this->collectItems();
				}
			}
		}
		return $hasUpdate;
	}

	private function isLocked() : bool{
		$block = $this->getWorld()->getBlock($this->location);
		return $block instanceof \pocketmine\block\ActivatorRail && $block->isPowered();
	}

	/** Pulls one item from a container above (the block above the cart). */
	public function pullFromAbove() : bool{
		$pos = $this->location->floor()->add(0, 1, 0);
		$tile = $this->getWorld()->getTile($pos);
		if(!$tile instanceof Container){
			return false;
		}
		return self::moveOne($tile->getInventory(), $this->inventory);
	}

	private static function moveOne(Inventory $from, Inventory $to) : bool{
		foreach($from->getContents() as $slot => $item){
			$one = (clone $item)->setCount(1);
			if($to->canAddItem($one)){
				$to->addItem($one);
				$from->setItem($slot, $item->setCount($item->getCount() - 1));
				return true;
			}
		}
		return false;
	}

	/** Picks up item entities touching the cart (or just above it). */
	public function collectItems() : bool{
		$box = new AxisAlignedBB($this->location->x - 0.5, $this->location->y, $this->location->z - 0.5, $this->location->x + 0.5, $this->location->y + 1.5, $this->location->z + 0.5);
		$taken = false;
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->getPickupDelay() > 0){
				continue;
			}
			$item = $entity->getItem();
			$left = $this->inventory->addItem($item);
			$rest = 0;
			foreach($left as $l){
				$rest += $l->getCount();
			}
			if($rest === 0){
				$entity->flagForDespawn();
				$taken = true;
			}elseif($rest < $item->getCount()){
				$entity->setStackSize($rest);
				$taken = true;
			}
		}
		return $taken;
	}
}
