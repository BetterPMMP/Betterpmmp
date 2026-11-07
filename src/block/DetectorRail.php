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

use pocketmine\block\utils\RedstoneSignalEmitter;
use pocketmine\data\runtime\RuntimeDataDescriber;

class DetectorRail extends StraightOnlyRail implements RedstoneSignalEmitter{
	protected bool $activated = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->activated);
	}

	public function isActivated() : bool{ return $this->activated; }

	/** @return $this */
	public function setActivated(bool $activated) : self{
		$this->activated = $activated;
		return $this;
	}
	//TODO

	/** BetterPMMP: a minecart on the rail powers it (checked every 20 ticks, like vanilla). */
	public function onMinecartOn() : void{
		$world = $this->position->getWorld();
		if(!$this->activated){
			$world->setBlock($this->position, $this->setActivated(true));
			$world->notifyRedstoneChange($this->position);
			$world->scheduleDelayedBlockUpdate($this->position, 20);
		}
	}

	public function onScheduledUpdate() : void{
		if(!$this->activated){
			return;
		}
		$world = $this->position->getWorld();
		$box = new \pocketmine\math\AxisAlignedBB($this->position->x + 0.125, $this->position->y, $this->position->z + 0.125, $this->position->x + 0.875, $this->position->y + 0.5, $this->position->z + 0.875);
		foreach($world->getNearbyEntities($box) as $entity){
			if($entity instanceof \pocketmine\entity\object\MinecartEntity){
				$world->scheduleDelayedBlockUpdate($this->position, 20);
				return;
			}
		}
		$world->setBlock($this->position, $this->setActivated(false));
		$world->notifyRedstoneChange($this->position);
	}
}
