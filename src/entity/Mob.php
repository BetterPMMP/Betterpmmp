<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\BlockTypeIds;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;
use function atan2;
use function cos;
use function deg2rad;
use function max;
use function min;
use function mt_rand;
use function rad2deg;
use function round;
use function sin;
use function sqrt;

/**
 * Base class for server-controlled living mobs.
 *
 * This is the first native AI layer for BetterPMMP. It provides low-cost ground
 * wandering; species-specific target, combat and navigation goals are added by
 * concrete mob classes.
 */
abstract class Mob extends Living{
	private const TAG_BETTERPMMP_NATURAL_SPAWN = "BetterPMMPNaturalSpawn";

	private int $wanderTicks = 0;
	private int $fleeTicks = 0;
	private float $fleeX = 0.0;
	private float $fleeZ = 0.0;
	private bool $naturalSpawned = false;
	private int $despawnCheckTicks = 0;
	private int $daylightCheckTicks = 0;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->naturalSpawned = $nbt->getByte(self::TAG_BETTERPMMP_NATURAL_SPAWN, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->naturalSpawned){
			$nbt->setByte(self::TAG_BETTERPMMP_NATURAL_SPAWN, 1);
		}
		return $nbt;
	}

	/** Marks this entity as a world-generated mob eligible for natural despawning. */
	public function markNaturalSpawned() : void{
		$this->naturalSpawned = true;
	}

	public function isNaturalSpawned() : bool{
		return $this->naturalSpawned;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->isAlive() && $source instanceof EntityDamageByEntityEvent && $this->shouldFleeFromAttacker($source)){
			$attacker = $source->getDamager();
			if($attacker !== null){
				$dx = $this->getPosition()->x - $attacker->getPosition()->x;
				$dz = $this->getPosition()->z - $attacker->getPosition()->z;
				$length = ($dx * $dx) + ($dz * $dz);
				if($length < 0.0001){
					$angle = deg2rad((float) mt_rand(0, 359));
					$dx = sin($angle);
					$dz = cos($angle);
				}else{
					$length = sqrt($length);
					$dx /= $length;
					$dz /= $length;
				}
				$this->fleeX = $dx;
				$this->fleeZ = $dz;
				$this->fleeTicks = 60;
				$this->wanderTicks = 0;
			}
		}
	}

	protected function shouldFleeFromAttacker(EntityDamageByEntityEvent $source) : bool{ return false; }

	protected function burnsInDaylight() : bool{ return false; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			$this->tickDaylightBurning($tickDiff);
			if($this->naturalSpawned && $this->shouldDespawnNaturally($tickDiff)){
				$this->flagForDespawn();
				return $hasUpdate;
			}
			$this->tickWandering($tickDiff);
		}
		return $hasUpdate;
	}

	private function tickDaylightBurning(int $tickDiff) : void{
		if(!$this->burnsInDaylight()){
			return;
		}
		$this->daylightCheckTicks += $tickDiff;
		if($this->daylightCheckTicks < 20){
			return;
		}
		$this->daylightCheckTicks %= 20;
		$time = $this->getWorld()->getTimeOfDay();
		if(!($time < World::TIME_SUNSET || $time >= World::TIME_SUNRISE) || $this->isUnderwater() || !$this->getArmorInventory()->getHelmet()->isNull()){
			return;
		}
		$x = $this->getPosition()->getFloorX();
		$y = $this->getPosition()->getFloorY() + (int) $this->getEyeHeight();
		$z = $this->getPosition()->getFloorZ();
		if($this->getWorld()->getRealBlockSkyLightAt($x, $y, $z) >= 12){
			$this->setOnFire(8);
		}
	}

	private function shouldDespawnNaturally(int $tickDiff) : bool{
		if($this->getNameTag() !== ""){
			return false;
		}

		$this->despawnCheckTicks += $tickDiff;
		if($this->despawnCheckTicks < 20){
			return false;
		}
		$this->despawnCheckTicks %= 20;

		$nearestDistanceSquared = 1.0e18;
		foreach($this->getWorld()->getPlayers() as $player){
			$distanceSquared = $player->getPosition()->distanceSquared($this->getPosition());
			if($distanceSquared < $nearestDistanceSquared){
				$nearestDistanceSquared = $distanceSquared;
			}
		}

		if($nearestDistanceSquared > 128 * 128){
			return true;
		}
		return $nearestDistanceSquared > 32 * 32 && mt_rand(1, 400) === 1;
	}

	protected function tickWandering(int $tickDiff) : void{
		if($this->fleeTicks > 0){
			$this->fleeTicks = max(0, $this->fleeTicks - $tickDiff);
			$speed = min(0.32, max(0.04, $this->getMovementSpeed() * 1.6));
			$motion = $this->getMotion();
			if($this->isOnGround()){
				if(!$this->moveGroundToward($this->fleeX, $this->fleeZ, $speed)){
					// Keep escape intent when the safe-path probe rejects every direction
					// (for example, on a narrow ledge or beside a liquid block).
					$this->setMotion(new Vector3($this->fleeX * $speed, max(0.18, $motion->y), $this->fleeZ * $speed));
				}
			}else{
				// Continue steering after knockback instead of waiting motionless for landing.
				$this->setMotion(new Vector3($this->fleeX * $speed, $motion->y, $this->fleeZ * $speed));
			}
			return;
		}

		$this->wanderTicks -= $tickDiff;
		if($this->wanderTicks > 0 || !$this->isOnGround()){
			return;
		}

		$this->wanderTicks = mt_rand(20, 60);
		if(mt_rand(0, 3) === 0){
			$motion = $this->getMotion();
			$this->setMotion(new Vector3(0, $motion->y, 0));
			return;
		}

		$angle = mt_rand(0, 359);
		$directionX = sin(deg2rad((float) $angle));
		$directionZ = cos(deg2rad((float) $angle));
		$speed = min(0.25, max(0.02, $this->getMovementSpeed()));
		$this->moveGroundToward($directionX, $directionZ, $speed);
	}

	/**
	 * Applies steering for ground mobs without allowing them to walk into walls
	 * or off a ledge. A one-block obstacle is handled with a short jump.
	 */
	protected function moveGroundToward(float $directionX, float $directionZ, float $speed) : bool{
		$length = sqrt(($directionX * $directionX) + ($directionZ * $directionZ));
		if($length < 0.0001){
			return false;
		}
		$directionX /= $length;
		$directionZ /= $length;
		$world = $this->getWorld();
		$feetY = $this->getPosition()->getFloorY();
		$distance = max(0.75, min(1.1, $this->getBoundingBox()->getXLength() + 0.2));
		$directions = [
			[$directionX, $directionZ],
			[$directionX * 0.7071 - $directionZ * 0.7071, $directionX * 0.7071 + $directionZ * 0.7071],
			[$directionX * 0.7071 + $directionZ * 0.7071, -$directionX * 0.7071 + $directionZ * 0.7071],
			[-$directionZ, $directionX],
			[$directionZ, -$directionX]
		];

		foreach($directions as [$dx, $dz]){
			$sampleX = $this->getPosition()->getFloorX() + (int) round($dx * $distance);
			$sampleZ = $this->getPosition()->getFloorZ() + (int) round($dz * $distance);
			if(!$world->isChunkLoaded($sampleX >> 4, $sampleZ >> 4)){
				continue;
			}

			$floor = $world->getBlockAt($sampleX, $feetY - 1, $sampleZ);
			$feet = $world->getBlockAt($sampleX, $feetY, $sampleZ);
			$head = $world->getBlockAt($sampleX, $feetY + 1, $sampleZ);
			if(!$floor->isSolid() || $floor->getTypeId() === BlockTypeIds::WATER || $floor->getTypeId() === BlockTypeIds::LAVA || $feet->getTypeId() === BlockTypeIds::WATER || $feet->getTypeId() === BlockTypeIds::LAVA || $head->getTypeId() === BlockTypeIds::WATER || $head->getTypeId() === BlockTypeIds::LAVA){
				continue;
			}

			$jump = false;
			if($feet->isSolid()){
				if($head->isSolid() || !$this->isOnGround()){
					continue;
				}
				$jump = true;
			}

			$motion = $this->getMotion();
			$verticalMotion = $jump ? max(0.42, $motion->y) : $motion->y;
			$this->setMotion(new Vector3($dx * $speed, $verticalMotion, $dz * $speed));
			$this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
			return true;
		}

		$motion = $this->getMotion();
		$this->setMotion(new Vector3(0.0, $motion->y, 0.0));
		return false;
	}
}
