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

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function atan2;
use function max;
use function min;
use function rad2deg;
use function sqrt;
use function mt_rand;

class Zombie extends Mob{
	protected function burnsInDaylight() : bool{ return true; }

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6); //TODO: eye height ??
	}

	public function getName() : string{
		return "Zombie";
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 2))
		];

		if(mt_rand(0, 199) < 5){
			switch(mt_rand(0, 2)){
				case 0:
					$drops[] = VanillaItems::IRON_INGOT();
					break;
				case 1:
					$drops[] = VanillaItems::CARROT();
					break;
				case 2:
					$drops[] = VanillaItems::POTATO();
					break;
			}
		}

		return $drops;
	}

	public function getXpDropAmount() : int{
		//TODO: check for equipment and whether it's a baby
		return 5;
	}

	protected function tickWandering(int $tickDiff) : void{
		$target = null;
		$targetDistanceSquared = 14 * 14;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){
				continue;
			}
			$distanceSquared = $this->getPosition()->distanceSquared($player->getPosition());
			if($distanceSquared < $targetDistanceSquared){
				$target = $player;
				$targetDistanceSquared = $distanceSquared;
			}
		}

		if(!$target instanceof Player){
			parent::tickWandering($tickDiff);
			return;
		}

		$dx = $target->getPosition()->x - $this->getPosition()->x;
		$dz = $target->getPosition()->z - $this->getPosition()->z;
		$distanceSquared = ($dx * $dx) + ($dz * $dz);
		if($distanceSquared <= 2.25 && $this->attackTime <= 0){
			$this->attackTime = 20;
			$target->attack(new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3));
			return;
		}

		if($distanceSquared > 0.0001 && $this->isOnGround()){
			$distance = sqrt($distanceSquared);
			$speed = min(0.25, max(0.02, $this->getMovementSpeed()));
			$directionX = $dx / $distance;
			$directionZ = $dz / $distance;
			$this->moveGroundToward($directionX, $directionZ, $speed);
		}
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOMBIE_SPAWN_EGG();
	}
}
