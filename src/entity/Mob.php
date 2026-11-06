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
use function ceil;
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
	private ?\pocketmine\entity\ai\Navigator $navigator = null;
	private ?Vector3 $panicFrom = null;

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
		// vanilla: animals are persistent, only monsters, bats and fish despawn
		if($this->getNameTag() !== "" || $this instanceof BreedableAnimal){
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

	/** Path following helper shared by all walking mobs. */
	public function getNavigator() : \pocketmine\entity\ai\Navigator{
		return $this->navigator ??= new \pocketmine\entity\ai\Navigator($this, max(1, (int) ceil($this->size->getHeight())), $this->canSwimInNavigation());
	}

	protected function canSwimInNavigation() : bool{ return true; }

	/** Vanilla speed attribute of this mob (falls back to the PocketMine attribute). */
	protected function baseSpeedAttribute() : float{
		$stats = \pocketmine\entity\ai\MobStats::get(static::getNetworkTypeId());
		return $stats !== null ? $stats[1] : $this->getMovementSpeed();
	}

	/** Ground speed in blocks per tick. */
	public function getWalkSpeed(bool $chasing = false) : float{
		$factor = $chasing ? \pocketmine\entity\ai\MobStats::CHASE_FACTOR : \pocketmine\entity\ai\MobStats::WANDER_FACTOR;
		return min(0.4, max(0.02, $this->baseSpeedAttribute() * $factor));
	}

	protected function isPanicking() : bool{
		return $this->fleeTicks > 0;
	}

	/** Runs away from a position for a while (vanilla PanicGoal / AvoidEntityGoal). */
	protected function startPanic(Vector3 $from, int $ticks = 60) : void{
		$dx = $this->getPosition()->x - $from->x;
		$dz = $this->getPosition()->z - $from->z;
		$length = sqrt($dx * $dx + $dz * $dz);
		if($length < 0.0001){
			$angle = deg2rad((float) mt_rand(0, 359));
			$dx = sin($angle);
			$dz = cos($angle);
			$length = 1.0;
		}
		$this->fleeX = $dx / $length;
		$this->fleeZ = $dz / $length;
		$this->fleeTicks = $ticks;
		$this->getNavigator()->stop();
	}

	protected function tickWandering(int $tickDiff) : void{
		if($this->fleeTicks > 0){
			$this->fleeTicks = max(0, $this->fleeTicks - $tickDiff);
			$navigator = $this->getNavigator();
			if(!$navigator->isMoving() || $this->panicFrom === null){
				// vanilla PanicGoal: run to a random spot 5-10 blocks away from the attacker at 1.25x speed
				$target = $this->findRandomStandPos(10, 4, $this->fleeX, $this->fleeZ);
				if($target !== null){
					$navigator->moveTo($target, $this->getWalkSpeed(true) * 1.25, 1.0);
					$this->panicFrom = $this->getPosition()->asVector3();
				}
			}
			if(!$navigator->tick($tickDiff)){
				$this->panicFrom = null;
				$speed = $this->getWalkSpeed(true) * 1.25;
				if($this->isOnGround() && !$this->moveGroundToward($this->fleeX, $this->fleeZ, $speed)){
					$motion = $this->getMotion();
					$this->setMotion(new Vector3($this->fleeX * $speed, max(0.18, $motion->y), $this->fleeZ * $speed));
				}
			}
			if($this->fleeTicks === 0){
				$navigator->stop();
				$this->panicFrom = null;
			}
			return;
		}

		$navigator = $this->getNavigator();
		if($navigator->isMoving()){
			$navigator->tick($tickDiff);
			return;
		}

		// vanilla RandomStrollGoal: on average one stroll every 120 ticks
		$this->wanderTicks -= $tickDiff;
		if($this->wanderTicks > 0){
			return;
		}
		$this->wanderTicks = mt_rand(60, 180);
		$target = $this->findRandomStandPos(10, 7);
		if($target !== null){
			$navigator->moveTo($target, $this->getWalkSpeed(), 1.0);
		}
	}

	/**
	 * A random spot the mob can stand on, within $range blocks horizontally and
	 * $vertical blocks vertically. With a direction, only spots that lie in that
	 * direction are chosen (used to run away).
	 */
	protected function findRandomStandPos(int $range, int $vertical, ?float $dirX = null, ?float $dirZ = null) : ?Vector3{
		$world = $this->getWorld();
		$pos = $this->getPosition();
		$finder = new \pocketmine\entity\ai\PathFinder($world, max(1, (int) ceil($this->size->getHeight())), $this->canSwimInNavigation());
		for($attempt = 0; $attempt < 10; ++$attempt){
			$ox = mt_rand(-$range, $range);
			$oz = mt_rand(-$range, $range);
			if($dirX !== null && $dirZ !== null){
				$ox = (int) round($dirX * mt_rand((int) ($range / 2), $range)) + mt_rand(-2, 2);
				$oz = (int) round($dirZ * mt_rand((int) ($range / 2), $range)) + mt_rand(-2, 2);
			}
			$x = $pos->getFloorX() + $ox;
			$z = $pos->getFloorZ() + $oz;
			if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
				continue;
			}
			$baseY = $pos->getFloorY();
			for($dy = $vertical; $dy >= -$vertical; --$dy){
				$y = $baseY + $dy;
				$cost = $finder->standCost($x, $y, $z);
				if($cost >= 0 && $cost < 4){
					return new Vector3($x + 0.5, $y, $z + 0.5);
				}
			}
		}
		return null;
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
			$verticalMotion = $jump ? max(\pocketmine\entity\ai\Navigator::JUMP_VELOCITY, $motion->y) : $motion->y;
			$this->setMotion(new Vector3($dx * $speed, $verticalMotion, $dz * $speed));
			$this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
			return true;
		}

		$motion = $this->getMotion();
		$this->setMotion(new Vector3(0.0, $motion->y, 0.0));
		return false;
	}
}
