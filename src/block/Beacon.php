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

use pocketmine\block\inventory\BeaconInventory;
use pocketmine\block\tile\Beacon as TileBeacon;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function in_array;
use function max;

/**
 * BetterPMMP: vanilla beacon. A pyramid of iron, gold, emerald, diamond or netherite blocks (1-4 levels)
 * below it unlocks powers: level 1 speed / haste, level 2 resistance / jump boost, level 3 strength,
 * level 4 a second power (regeneration, or the first power at level II). Paid with one iron or gold ingot,
 * emerald, diamond or netherite ingot. Every 4 seconds all players within 10 + 10 x levels blocks get the
 * powers for (9 + 2 x levels) seconds. The beam needs a clear view of the sky.
 */
final class Beacon extends Transparent{

	private const TICK_INTERVAL = 80;

	/** Bedrock effect ids: speed 1, haste 3, resistance 11, jump boost 8, strength 5, regeneration 10 */
	private const POWERS = [1 => [1, 3], 2 => [11, 8], 3 => [5]];
	private const REGENERATION = 10;

	public function getLightLevel() : int{
		return 15;
	}

	public static function isPayment(Item $item) : bool{
		return in_array($item->getTypeId(), [ItemTypeIds::IRON_INGOT, ItemTypeIds::GOLD_INGOT, ItemTypeIds::EMERALD, ItemTypeIds::DIAMOND, ItemTypeIds::NETHERITE_INGOT], true);
	}

	private static function isBaseBlock(Block $block) : bool{
		return in_array($block->getTypeId(), [BlockTypeIds::IRON, BlockTypeIds::GOLD, BlockTypeIds::EMERALD, BlockTypeIds::DIAMOND, BlockTypeIds::NETHERITE], true);
	}

	/** Number of complete pyramid levels below the beacon (0-4). */
	public function getLevels() : int{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($level = 1; $level <= 4; ++$level){
			$ly = $y - $level;
			if($ly < $world->getMinY()){
				return $level - 1;
			}
			for($dx = -$level; $dx <= $level; ++$dx){
				for($dz = -$level; $dz <= $level; ++$dz){
					if(!self::isBaseBlock($world->getBlockAt($x + $dx, $ly, $z + $dz))){
						return $level - 1;
					}
				}
			}
		}
		return 4;
	}

	/** Whether the beam reaches the sky (only transparent blocks above). */
	private function seesSky() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		for($y = $this->position->getFloorY() + 1, $max = $world->getMaxY(); $y < $max; ++$y){
			$block = $world->getBlockAt($x, $y, $z);
			if($block->isSolid() && !$block->isTransparent()){
				return false;
			}
		}
		return true;
	}

	/** Whether the pyramid allows these powers (Bedrock effect ids, 0 = none). */
	public function canUsePowers(int $primary, int $secondary) : bool{
		$levels = $this->getLevels();
		$allowed = [];
		foreach(self::POWERS as $level => $powers){
			if($levels >= $level){
				foreach($powers as $power){
					$allowed[] = $power;
				}
			}
		}
		if($primary === 0 || !in_array($primary, $allowed, true)){
			return false;
		}
		if($secondary === 0){
			return true;
		}
		return $levels >= 4 && ($secondary === self::REGENERATION || $secondary === $primary);
	}

	public function setPowers(int $primary, int $secondary) : void{
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBeacon){
			$tile->setPrimaryEffect($primary);
			$tile->setSecondaryEffect($secondary);
			$this->position->getWorld()->setBlock($this->position, $this); //sends the new powers to the clients
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$player->setCurrentWindow(new BeaconInventory($this->position));
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::TICK_INTERVAL);
		}
		return true;
	}

	public function onPostPlace() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::TICK_INTERVAL);
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::TICK_INTERVAL);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$world->scheduleDelayedBlockUpdate($this->position, self::TICK_INTERVAL);
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBeacon || $tile->getPrimaryEffect() === 0){
			return;
		}
		$levels = $this->getLevels();
		if($levels === 0 || !$this->seesSky() || !$this->canUsePowers($tile->getPrimaryEffect(), $tile->getSecondaryEffect())){
			return;
		}
		$range = 10 + 10 * $levels;
		$duration = (9 + 2 * $levels) * 20;
		$primary = EffectIdMap::getInstance()->fromId($tile->getPrimaryEffect());
		$secondaryId = $tile->getSecondaryEffect();
		$secondary = $secondaryId !== 0 && $secondaryId !== $tile->getPrimaryEffect() ? EffectIdMap::getInstance()->fromId($secondaryId) : null;
		$amplifier = $secondaryId === $tile->getPrimaryEffect() ? 1 : 0;
		$box = new AxisAlignedBB(
			$this->position->x - $range, $world->getMinY(), $this->position->z - $range,
			$this->position->x + $range + 1, $world->getMaxY(), $this->position->z + $range + 1
		);
		foreach($world->getNearbyEntities($box) as $entity){
			if(!$entity instanceof Player || !$entity->isAlive() || $entity->isSpectator()){
				continue;
			}
			if($primary !== null){
				$entity->getEffects()->add(new EffectInstance($primary, $duration, $amplifier, true));
			}
			if($secondary !== null){
				$entity->getEffects()->add(new EffectInstance($secondary, $duration, 0, true));
			}
		}
	}

	/** Levels, primary and secondary power for screens (the Java beacon screen). */
	public function getScreenData() : array{
		$tile = $this->position->getWorld()->getTile($this->position);
		return [$this->getLevels(), $tile instanceof TileBeacon ? $tile->getPrimaryEffect() : 0, $tile instanceof TileBeacon ? $tile->getSecondaryEffect() : 0];
	}
}
