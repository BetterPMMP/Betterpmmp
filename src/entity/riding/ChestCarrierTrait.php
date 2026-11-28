<?php

/*
 * BetterPMMP - chests on donkeys, mules and llamas, and the horse inventory screen.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\block\BlockTypeIds;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;

/**
 * Vanilla: a tamed adult donkey, mule or llama takes a chest (use a chest on it). Sneaking and using a
 * tamed horse, donkey, mule or llama - or the inventory key while riding it - opens its inventory:
 * saddle, armor or carpet, and the chest. Breaking the chest off is not possible; the chest and its
 * items drop when the animal dies.
 */
trait ChestCarrierTrait{

	private bool $chested = false;
	private ?MountInventory $mountInventory = null;
	/** @var Item[] chest items loaded before the inventory exists */
	private array $pendingChestItems = [];

	/** Number of chest slots (0 = this animal cannot carry a chest). */
	abstract protected function chestSlots() : int;

	/** Whether the animal may carry a chest and show its inventory now (tamed and grown up). */
	abstract protected function canOpenMountInventory() : bool;

	/** Called when the saddle or armor/carpet slot changes in the inventory screen. */
	protected function onMountEquipmentChanged(int $slot, Item $item) : void{}

	/** Saddle and armor/carpet currently worn, put into slots 0 and 1 when the inventory is created. */
	protected function currentMountEquipment() : array{ return [MountInventory::SLOT_SADDLE => VanillaItems::AIR(), MountInventory::SLOT_ARMOR => VanillaItems::AIR()]; }

	public function isChested() : bool{ return $this->chested; }

	/** Whether the inventory screen can be opened now (tamed, grown up). */
	public function canShowMountInventory() : bool{ return $this->canOpenMountInventory(); }

	public function getMountInventory() : MountInventory{
		if($this->mountInventory === null){
			$inventory = new MountInventory($this, $this->chested ? $this->chestSlots() : 0);
			foreach($this->currentMountEquipment() as $slot => $item){
				$inventory->setItem($slot, $item);
			}
			foreach($this->pendingChestItems as $slot => $item){
				if($inventory->slotExists(MountInventory::CHEST_START + $slot)){
					$inventory->setItem(MountInventory::CHEST_START + $slot, $item);
				}
			}
			$this->pendingChestItems = [];
			$inventory->getListeners()->add(new CallbackInventoryListener(
				function(Inventory $inventory, int $slot, Item $oldItem) : void{
					if($slot === MountInventory::SLOT_SADDLE || $slot === MountInventory::SLOT_ARMOR){
						$this->onMountEquipmentChanged($slot, $inventory->getItem($slot));
					}
				},
				null
			));
			$this->mountInventory = $inventory;
		}
		return $this->mountInventory;
	}

	/** Keeps the inventory in step when the saddle or armor is put on by clicking the animal. */
	protected function syncMountEquipment() : void{
		if($this->mountInventory === null){
			return;
		}
		foreach($this->currentMountEquipment() as $slot => $item){
			if(!$this->mountInventory->getItem($slot)->equalsExact($item)){
				$this->mountInventory->setItem($slot, $item);
			}
		}
	}

	private static function isChestItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::fromBlockTypeId(BlockTypeIds::CHEST);
	}

	/** Chest and inventory handling of a click on the animal; true if it was handled. */
	protected function chestCarrierInteract(Player $player, Item $held) : bool{
		if(!$this->canOpenMountInventory()){
			return false;
		}
		if(!$this->chested && $this->chestSlots() > 0 && self::isChestItem($held)){
			$this->setChested(true);
			if($player->hasFiniteResources()){
				$held->pop();
				$player->getInventory()->setItemInHand($held);
			}
			return true;
		}
		if($player->isSneaking()){
			$this->openMountInventory($player);
			return true;
		}
		return false;
	}

	public function openMountInventory(Player $player) : void{
		if($this->canOpenMountInventory()){
			$player->setCurrentWindow($this->getMountInventory());
		}
	}

	private function setChested(bool $chested) : void{
		if($this->chested === $chested){
			return;
		}
		$this->chested = $chested;
		//the chest changes the size: build the inventory again with the same items
		$old = $this->mountInventory;
		$this->mountInventory = null;
		if($old !== null){
			$old->removeAllViewers();
			foreach($old->getContents() as $slot => $item){
				if($slot >= MountInventory::CHEST_START){
					$this->pendingChestItems[$slot - MountInventory::CHEST_START] = $item;
				}
			}
		}
		$this->networkPropertiesDirty = true;
	}

	protected function chestCarrierInit(CompoundTag $nbt) : void{
		$this->chested = $nbt->getByte("Chested", 0) !== 0;
		$items = $nbt->getListTag("ChestItems");
		if($items !== null){
			foreach($items as $tag){
				if($tag instanceof CompoundTag){
					try{
						$this->pendingChestItems[$tag->getByte("Slot", 0)] = Item::nbtDeserialize($tag);
					}catch(\Throwable){
						//unknown item
					}
				}
			}
		}
	}

	/** @return Item[] chest items by chest slot */
	private function chestItems() : array{
		if($this->mountInventory === null){
			return $this->pendingChestItems;
		}
		$items = [];
		foreach($this->mountInventory->getContents() as $slot => $item){
			if($slot >= MountInventory::CHEST_START){
				$items[$slot - MountInventory::CHEST_START] = $item;
			}
		}
		return $items;
	}

	protected function chestCarrierSave(CompoundTag $nbt) : void{
		$nbt->setByte("Chested", $this->chested ? 1 : 0);
		$list = [];
		foreach($this->chestItems() as $slot => $item){
			if(!$item->isNull()){
				$list[] = $item->nbtSerialize($slot);
			}
		}
		$nbt->setTag("ChestItems", new ListTag($list));
	}

	/** @return Item[] the chest and its contents */
	protected function chestCarrierDrops() : array{
		if(!$this->chested){
			return [];
		}
		$drops = [\pocketmine\block\VanillaBlocks::CHEST()->asItem()];
		foreach($this->chestItems() as $item){
			if(!$item->isNull()){
				$drops[] = $item;
			}
		}
		return $drops;
	}

	protected function chestCarrierSync(EntityMetadataCollection $properties) : void{
		$properties->setGenericFlag(EntityMetadataFlags::CHESTED, $this->chested);
		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, WindowTypes::HORSE);
		$properties->setInt(EntityMetadataProperties::CONTAINER_BASE_SIZE, MountInventory::CHEST_START + ($this->chested ? $this->chestSlots() : 0));
		$properties->setInt(EntityMetadataProperties::CONTAINER_EXTRA_SLOTS_PER_STRENGTH, 0);
	}

	protected function chestCarrierDispose() : void{
		$this->mountInventory?->removeAllViewers();
	}
}
