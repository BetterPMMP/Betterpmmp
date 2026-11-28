<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\RedstonePower;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function count;
use function in_array;
use function min;
use function range;

class Hopper extends Transparent implements PoweredByRedstone{
	use PoweredByRedstoneTrait;

	private int $facing = Facing::DOWN;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingExcept($this->facing, Facing::UP);
		$w->bool($this->powered);
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		if($facing === Facing::UP){
			throw new \InvalidArgumentException("Hopper may not face upward");
		}
		$this->facing = $facing;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 6 / 16) //the empty area around the bottom is currently considered solid
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return match($facing){
			Facing::UP => SupportType::FULL,
			Facing::DOWN => $this->facing === Facing::DOWN ? SupportType::CENTER : SupportType::NONE,
			default => SupportType::NONE
		};
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face === Facing::DOWN ? Facing::DOWN : Facing::opposite($face);

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileHopper){ //TODO: find a way to have inventories open on click without this boilerplate in every block
				$player->setCurrentWindow($tile->getInventory());
			}
			return true;
		}
		return false;
	}

	public function onPostPlace() : void{
		$this->onNearbyBlockChange();
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$powered = RedstonePower::isPowered($this);
		if($powered !== $this->powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
		}
		if(!$this->powered){
			$world->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	/**
	 * Wakes the hopper (e.g. an item entity landed in or above it).
	 */
	public function wake() : void{
		if(!$this->powered){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileHopper){
			return;
		}
		$powered = RedstonePower::isPowered($this);
		if($powered !== $this->powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
		}
		if($this->powered){
			return; //locked hoppers neither push nor pull; a power change wakes us
		}
		$cooldown = $tile->getTransferCooldown();
		if($cooldown > 0){
			$world->scheduleDelayedBlockUpdate($this->position, $cooldown);
			return;
		}

		$inventory = $tile->getInventory();
		$pushed = $this->pushItem($inventory);
		$pulled = $this->pullItems($inventory);
		if($pushed || $pulled){
			$tile->setTransferCooldown(TileHopper::TRANSFER_COOLDOWN_TICKS);
			$world->scheduleDelayedBlockUpdate($this->position, TileHopper::TRANSFER_COOLDOWN_TICKS);
		}
		//idle: inventory changes of neighbouring containers, block updates and
		//item entities wake the hopper again, so no polling is required
	}

	private function getContainerAt(int $side) : ?Inventory{
		$pos = $this->position->getSide($side);
		$world = $this->position->getWorld();
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || !$world->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4)){
			return null;
		}
		$tile = $world->getTile($pos);
		return $tile instanceof Container ? $tile->getInventory() : null;
	}

	/**
	 * Moves one item from the first possible slot into the container the hopper faces.
	 */
	private function pushItem(Inventory $inventory) : bool{
		$target = $this->getContainerAt($this->facing);
		if($target === null){
			//vanilla: a hopper pointing down into a composter fills it
			$composter = $this->facing === Facing::DOWN ? $this->getSide(Facing::DOWN) : null;
			if($composter instanceof VanillaComposter){
				for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
					$item = $inventory->getItem($slot);
					if(!$item->isNull() && VanillaComposter::compostChance($item) > 0){
						if(!$composter->addFromHopper((clone $item)->setCount(1))){
							return false; //full
						}
						$item->pop();
						$inventory->setItem($slot, $item);
						return true;
					}
				}
			}
			return false;
		}
		$targetTile = $this->position->getWorld()->getTile($this->position->getSide($this->facing));
		$targetWasEmpty = $targetTile instanceof TileHopper && count($target->getContents()) === 0;
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}
			$one = (clone $item)->setCount(1);
			if(self::insertOne($target, $one, $this->facing)){
				$item->pop();
				$inventory->setItem($slot, $item);
				if($targetWasEmpty && $targetTile->getTransferCooldown() === 0){
					//vanilla: an empty hopper that receives an item waits a full cooldown before passing it on
					$targetTile->setTransferCooldown(TileHopper::TRANSFER_COOLDOWN_TICKS);
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Pulls one item from the container above, or picks up item entities lying
	 * in or on top of the hopper.
	 */
	private function pullItems(Inventory $inventory) : bool{
		$source = $this->getContainerAt(Facing::UP);
		if($source === null && ($composter = $this->getSide(Facing::UP)) instanceof VanillaComposter){
			//vanilla: a hopper below a full composter takes the bone meal
			$boneMeal = $composter->getLevel() >= 8 ? VanillaItems::BONE_MEAL() : null;
			if($boneMeal !== null && $inventory->canAddItem($boneMeal) && ($taken = $composter->takeBoneMeal()) !== null){
				$inventory->addItem($taken);
				return true;
			}
			return false;
		}
		if($source !== null){
			$slots = match(true){
				$source instanceof FurnaceInventory => [FurnaceInventory::SLOT_RESULT],
				//vanilla: only the potions are taken out of a brewing stand
				$source instanceof BrewingStandInventory => [BrewingStandInventory::SLOT_BOTTLE_LEFT, BrewingStandInventory::SLOT_BOTTLE_MIDDLE, BrewingStandInventory::SLOT_BOTTLE_RIGHT],
				default => range(0, $source->getSize() - 1),
			};
			foreach($slots as $slot){
				$item = $source->getItem($slot);
				if($item->isNull()){
					continue;
				}
				$one = (clone $item)->setCount(1);
				if($inventory->canAddItem($one)){
					$inventory->addItem($one);
					$item->pop();
					$source->setItem($slot, $item);
					return true;
				}
			}
			return false;
		}

		$above = $this->getSide(Facing::UP);
		$bb = new AxisAlignedBB(0, 10 / 16, 0, 1, ($above->isFullCube() && $above->isSolid()) ? 1 : 2, 1);
		$picked = false;
		foreach($this->position->getWorld()->getNearbyEntities($bb->offset($this->position->x, $this->position->y, $this->position->z)) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->isClosed()){
				continue;
			}
			$item = $entity->getItem();
			$quantity = $inventory->getAddableItemQuantity($item);
			if($quantity <= 0){
				continue;
			}
			$inventory->addItem((clone $item)->setCount($quantity));
			if($quantity >= $item->getCount()){
				$entity->flagForDespawn();
			}else{
				$entity->setStackSize($item->getCount() - $quantity);
			}
			$picked = true;
		}
		return $picked;
	}

	/**
	 * Inserts a single item following vanilla's side rules for furnaces:
	 * from above into the input slot, from the side into the fuel slot.
	 */
	private static function insertOne(Inventory $target, Item $one, int $hopperFacing) : bool{
		if($target instanceof FurnaceInventory){
			if($hopperFacing === Facing::DOWN){
				return self::insertIntoSlot($target, FurnaceInventory::SLOT_INPUT, $one);
			}
			return $one->getFuelTime() > 0 && self::insertIntoSlot($target, FurnaceInventory::SLOT_FUEL, $one);
		}
		if($target instanceof BrewingStandInventory){
			//vanilla: from above the ingredient, from the side blaze powder as fuel and bottles into the bottle slots
			if($hopperFacing === Facing::DOWN){
				return self::isBrewingIngredient($one) && self::insertIntoSlot($target, BrewingStandInventory::SLOT_INGREDIENT, $one);
			}
			if($one->getTypeId() === ItemTypeIds::BLAZE_POWDER){
				return self::insertIntoSlot($target, BrewingStandInventory::SLOT_FUEL, $one);
			}
			if(in_array($one->getTypeId(), [ItemTypeIds::POTION, ItemTypeIds::SPLASH_POTION, ItemTypeIds::LINGERING_POTION, ItemTypeIds::GLASS_BOTTLE], true)){
				foreach([BrewingStandInventory::SLOT_BOTTLE_LEFT, BrewingStandInventory::SLOT_BOTTLE_MIDDLE, BrewingStandInventory::SLOT_BOTTLE_RIGHT] as $slot){
					if($target->getItem($slot)->isNull()){
						$target->setItem($slot, $one);
						return true;
					}
				}
			}
			return false;
		}
		if(!$target->canAddItem($one)){
			return false;
		}
		$target->addItem($one);
		return true;
	}

	private static function isBrewingIngredient(Item $item) : bool{
		$manager = \pocketmine\Server::getInstance()->getCraftingManager();
		foreach($manager->getPotionTypeRecipes() as $recipe){
			if($recipe->getIngredient()->accepts($item)){
				return true;
			}
		}
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			if($recipe->getIngredient()->accepts($item)){
				return true;
			}
		}
		return false;
	}

	private static function insertIntoSlot(Inventory $target, int $slot, Item $one) : bool{
		$existing = $target->getItem($slot);
		if($existing->isNull()){
			$target->setItem($slot, $one);
			return true;
		}
		if($existing->canStackWith($one) && $existing->getCount() < min($existing->getMaxStackSize(), $target->getMaxStackSize())){
			$existing->setCount($existing->getCount() + 1);
			$target->setItem($slot, $existing);
			return true;
		}
		return false;
	}
}
