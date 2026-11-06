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

use pocketmine\block\tile\Comparator;
use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\RedstonePower;
use pocketmine\block\tile\Container;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function assert;

class RedstoneComparator extends Flowable implements AnalogRedstoneSignalEmitter, PoweredByRedstone, HorizontalFacing{
	use HorizontalFacingTrait;
	use AnalogRedstoneSignalEmitterTrait;
	use PoweredByRedstoneTrait;
	use StaticSupportTrait;

	protected bool $isSubtractMode = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->isSubtractMode);
		$w->bool($this->powered);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof Comparator){
			$this->signalStrength = $tile->getSignalStrength();
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof Comparator);
		$tile->setSignalStrength($this->signalStrength);
	}

	public function isSubtractMode() : bool{
		return $this->isSubtractMode;
	}

	/** @return $this */
	public function setSubtractMode(bool $isSubtractMode) : self{
		$this->isSubtractMode = $isSubtractMode;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 8)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->isSubtractMode = !$this->isSubtractMode;
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	private function readRearSignal() : int{
		$power = RedstonePower::diodeInput($this);
		$rear = RedstonePower::side($this, $this->facing);
		if($rear === null){
			return $power;
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($rear->getPosition());
		if($tile instanceof Container){
			return RedstonePower::inventorySignal($tile->getInventory());
		}
		if($power < 15 && RedstonePower::isConductor($rear)){
			$far = RedstonePower::side($rear, $this->facing);
			$tile = $far === null ? null : $world->getTile($far->getPosition());
			if($tile instanceof Container){
				return RedstonePower::inventorySignal($tile->getInventory());
			}
		}
		return $power;
	}

	private function calculateSignal() : int{
		return RedstonePower::comparatorOutput($this->readRearSignal(), RedstonePower::diodeSideInput($this), $this->isSubtractMode);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		if($this->signalStrength !== $this->calculateSignal()){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 2);
		}
	}

	public function onScheduledUpdate() : void{
		$power = $this->calculateSignal();
		if($power !== $this->signalStrength || $this->powered !== ($power > 0)){
			$this->signalStrength = $power;
			$this->powered = $power > 0;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}
}
