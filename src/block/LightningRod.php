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

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperTrait;
use pocketmine\block\utils\RedstoneSignalEmitter;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

final class LightningRod extends Transparent implements AnyFacing, CopperMaterial, RedstoneSignalEmitter, \pocketmine\block\utils\DirectionalRedstoneSource{
	use CopperTrait;
	use AnyFacingTrait;

	protected function recalculateCollisionBoxes() : array{
		$myAxis = Facing::axis($this->facing);

		$result = AxisAlignedBB::one();
		foreach([Axis::X, Axis::Y, Axis::Z] as $axis){
			if($axis !== $myAxis){
				$result->squash($axis, 6 / 16);
			}
		}

		return [$result];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face;
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	/**
	 * BetterPMMP: struck by lightning the rod gives a signal of 15 for 8 ticks
	 * (strong into the block it is attached to). Kept in memory, Bedrock has no state for it.
	 * @var int[][] world id => block hash => 1
	 */
	private static array $powered = [];

	private function poweredKey() : int{
		return World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	public function isStruck() : bool{
		return $this->position->isValid() && isset(self::$powered[$this->position->getWorld()->getId()][$this->poweredKey()]);
	}

	public function onLightningStrike() : void{
		$world = $this->position->getWorld();
		self::$powered[$world->getId()][$this->poweredKey()] = 1;
		$world->notifyRedstoneChange($this->position);
		$world->scheduleDelayedBlockUpdate($this->position, 8);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if(isset(self::$powered[$world->getId()][$this->poweredKey()])){
			unset(self::$powered[$world->getId()][$this->poweredKey()]);
			$world->notifyRedstoneChange($this->position);
		}
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->isStruck() ? 15 : 0;
	}

	public function getStrongPowerTowards(int $towards) : int{
		return $this->isStruck() && $towards === Facing::opposite($this->facing) ? 15 : 0;
	}
}
