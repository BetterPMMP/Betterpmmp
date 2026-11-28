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

use pocketmine\block\utils\Lightable;
use pocketmine\block\utils\LightableTrait;
use pocketmine\block\utils\RedstoneSignalEmitter;
use pocketmine\block\utils\RedstonePower;
use pocketmine\math\Facing;
use pocketmine\data\runtime\RuntimeDataDescriber;
use function count;
use function mt_rand;

class RedstoneTorch extends Torch implements Lightable, RedstoneSignalEmitter{
	use LightableTrait;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo){
		$this->lit = true;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->lit);
	}

	public function getLightLevel() : int{
		return $this->lit ? 7 : 0;
	}

	private function shouldBeLit() : bool{
		$support = RedstonePower::side($this, Facing::opposite($this->facing));
		return $support === null || RedstonePower::conductorInput($support) === 0;
	}

	/**
	 * Bedrock: a torch only burns out when it powers itself without delay (wire from the torch back into its
	 * own block). Such a loop turns the torch off every 4 game ticks; clocks with a repeater, comparator or
	 * more torches are slower and never burn out. 8 turn-offs within 30 game ticks only happen in that loop.
	 */
	private const BURNOUT_TOGGLES = 8;
	private const BURNOUT_WINDOW = 30;
	private const BURNOUT_RECOVERY = 160;

	/**
	 * Game ticks at which torches turned off, per world and position.
	 * @var int[][][]
	 * @phpstan-var array<int, array<int, list<int>>>
	 */
	private static array $toggles = [];

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		if(!$this->position->getWorld()->getBlock($this->position)->hasSameTypeId($this)){
			return;
		}
		if($this->lit !== $this->shouldBeLit()){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 2);
		}
	}

	/** Records a turn-off (if $add) and tells whether the torch toggled too often recently. */
	private function toggledTooOften(bool $add) : bool{
		$world = $this->position->getWorld();
		$worldId = $world->getId();
		$key = \pocketmine\world\World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		$now = $world->getServer()->getTick();
		$recent = [];
		foreach(self::$toggles[$worldId][$key] ?? [] as $tick){
			if($now - $tick <= self::BURNOUT_WINDOW){
				$recent[] = $tick;
			}
		}
		if($add){
			$recent[] = $now;
		}
		if($recent === []){
			unset(self::$toggles[$worldId][$key]);
		}else{
			self::$toggles[$worldId][$key] = $recent;
		}
		return count($recent) >= self::BURNOUT_TOGGLES;
	}

	public function onScheduledUpdate() : void{
		$lit = $this->shouldBeLit();
		$world = $this->position->getWorld();
		if($this->lit && !$lit){
			$this->lit = false;
			$world->setBlock($this->position, $this);
			if($this->toggledTooOften(true)){
				//burnt out: smoke and fizz, it tries again later
				$center = $this->position->add(0.5, 0.5, 0.5);
				$world->addSound($center, new \pocketmine\world\sound\FizzSound(2.6));
				for($i = 0; $i < 5; ++$i){
					$world->addParticle($center->add(mt_rand(-20, 20) / 100, mt_rand(0, 20) / 100, mt_rand(-20, 20) / 100), new \pocketmine\world\particle\SmokeParticle());
				}
				$world->scheduleDelayedBlockUpdate($this->position, self::BURNOUT_RECOVERY);
			}
		}elseif(!$this->lit && $lit && !$this->toggledTooOften(false)){
			$this->lit = true;
			$world->setBlock($this->position, $this);
		}
	}
}
