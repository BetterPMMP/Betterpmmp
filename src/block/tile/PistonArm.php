<?php

/*
 * BetterPMMP - piston block entity (renders the piston head on Bedrock clients).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;

class PistonArm extends Spawnable{

	public const STATE_RETRACTED = 0;
	public const STATE_EXTENDING = 1;
	public const STATE_EXTENDED = 2;
	public const STATE_RETRACTING = 3;

	private int $state = self::STATE_RETRACTED;
	private float $progress = 0.0;
	private float $lastProgress = 0.0;
	private bool $sticky = false;

	public function getState() : int{ return $this->state; }

	public function isSticky() : bool{ return $this->sticky; }

	public function setSticky(bool $sticky) : void{ $this->sticky = $sticky; }

	public function isExtended() : bool{
		return $this->state === self::STATE_EXTENDED || $this->state === self::STATE_EXTENDING;
	}

	public function isMoving() : bool{
		return $this->state === self::STATE_EXTENDING || $this->state === self::STATE_RETRACTING;
	}

	public function setState(int $state) : void{
		$this->lastProgress = $this->progress;
		$this->state = $state;
		$this->progress = match($state){
			self::STATE_RETRACTED => 0.0,
			self::STATE_EXTENDED => 1.0,
			default => 0.5,
		};
		$this->clearSpawnCompoundCache();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->state = $nbt->getByte("State", self::STATE_RETRACTED);
		// a piston saved mid-move finishes the move on load
		if($this->state === self::STATE_EXTENDING){
			$this->state = self::STATE_EXTENDED;
		}elseif($this->state === self::STATE_RETRACTING){
			$this->state = self::STATE_RETRACTED;
		}
		$this->progress = $this->lastProgress = $this->state === self::STATE_EXTENDED ? 1.0 : 0.0;
		$this->sticky = $nbt->getByte("Sticky", 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->writeCommon($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->writeCommon($nbt);
	}

	private function writeCommon(CompoundTag $nbt) : void{
		$nbt->setByte("State", $this->state);
		$nbt->setByte("NewState", $this->state);
		$nbt->setFloat("Progress", $this->progress);
		$nbt->setFloat("LastProgress", $this->lastProgress);
		$nbt->setByte("Sticky", $this->sticky ? 1 : 0);
		$nbt->setByte("isMovable", $this->isMoving() ? 0 : 1);
		$nbt->setTag("AttachedBlocks", new ListTag([], NBT::TAG_Int));
		$nbt->setTag("BreakBlocks", new ListTag([], NBT::TAG_Int));
	}
}
