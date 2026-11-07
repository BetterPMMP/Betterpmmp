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

namespace pocketmine\block\utils;

use pocketmine\data\runtime\RuntimeDataDescriber;

trait RailPoweredByRedstoneTrait{
	use PoweredByRedstoneTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->powered);
	}

	/**
	 * BetterPMMP: vanilla rail power - a powered / activator rail is on when it
	 * gets redstone power itself or a rail of the same kind up to 8 rails away
	 * along the track does.
	 */
	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		$world = $this->position->getWorld();
		$block = $world->getBlock($this->position);
		if(!$block instanceof self){
			return; // broken by the support check
		}
		$powered = \pocketmine\block\utils\RedstonePower::isPowered($this) || $this->chainPowered();
		if($powered !== $this->powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
		}
	}

	private function chainPowered() : bool{
		$world = $this->position->getWorld();
		foreach($this->getCurrentShapeConnections() as $connection){
			$face = $connection & ~\pocketmine\block\utils\RailConnectionInfo::FLAG_ASCEND;
			$pos = $this->position->asVector3();
			for($step = 1; $step <= 8; ++$step){
				$pos = $pos->getSide($face);
				$next = null;
				foreach([0, 1, -1] as $dy){
					$candidate = \pocketmine\block\utils\RedstonePower::at($world, $pos->add(0, $dy, 0));
					if($candidate !== null && $candidate->getTypeId() === $this->getTypeId()){
						$next = $candidate;
						$pos = $pos->add(0, $dy, 0);
						break;
					}
				}
				if($next === null){
					break;
				}
				if(\pocketmine\block\utils\RedstonePower::isPowered($next)){
					return true;
				}
			}
		}
		return false;
	}
}
