<?php

/*
 * BetterPMMP - crafter block entity.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\inventory\CrafterInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

class Crafter extends Spawnable implements Container, Nameable{
	use ContainerTrait;
	use NameableTrait{
		addAdditionalSpawnData as addNameSpawnData;
	}

	private const TAG_DISABLED = "disabled_slots";

	protected CrafterInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new CrafterInventory($this->position);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->inventory->setDisabledMask($nbt->getShort(self::TAG_DISABLED, 0));
		$this->loadItems($nbt);
		$this->loadName($nbt);
		$this->inventory->refresh();
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveName($nbt);
		$nbt->setShort(self::TAG_DISABLED, $this->inventory->getDisabledMask());
	}

	public function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addNameSpawnData($nbt);
		$nbt->setShort(self::TAG_DISABLED, $this->inventory->getDisabledMask());
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	public function getDefaultName() : string{
		return "Crafter";
	}

	public function getInventory() : CrafterInventory{
		return $this->inventory;
	}

	public function getRealInventory() : CrafterInventory{
		return $this->inventory;
	}

	public function toggleSlot(int $slot, bool $disabled) : void{
		if($slot < 0 || $slot > 8 || (!$this->inventory->getItem($slot)->isNull() && $disabled)){
			return;
		}
		$this->inventory->setSlotDisabled($slot, $disabled);
		$this->setDirty();
		$this->position->getWorld()->setBlock($this->position, $this->position->getWorld()->getBlock($this->position)); // resend the block entity
	}
}
