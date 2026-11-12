<?php

/*
 * BetterPMMP - conduit block entity.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;

/** Conduit: remembers whether it is active (the client animates active ones). */
class Conduit extends Spawnable{

	public const TAG_ACTIVE = "Active";
	public const TAG_TARGET = "Target";

	private bool $active = false;
	private int $attackCooldown = 0;

	public function isActive() : bool{
		return $this->active;
	}

	public function setActive(bool $active) : void{
		$this->active = $active;
		$this->clearSpawnCompoundCache();
		$this->position->getWorld()->setBlock($this->position, $this->getBlock()); // resend the block entity
	}

	/** Called every 2 seconds while active; returns true every attack interval. */
	public function tickAttack() : bool{
		if(--$this->attackCooldown > 0){
			return false;
		}
		$this->attackCooldown = 1;
		return true;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->active = $nbt->getByte(self::TAG_ACTIVE, 0) === 1;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_ACTIVE, $this->active ? 1 : 0);
		$nbt->setLong(self::TAG_TARGET, -1);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_ACTIVE, $this->active ? 1 : 0);
		$nbt->setLong(self::TAG_TARGET, -1);
	}
}
