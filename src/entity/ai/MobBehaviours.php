<?php

/*
 * BetterPMMP - behaviours shared by several vanilla mobs.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\entity\BreedableAnimal;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function atan2;
use function in_array;
use function mt_rand;
use function rad2deg;
use function sqrt;

/**
 * Neutral animals that fight back (polar bear, panda, llama, fox...): adults
 * attack whoever hurt them instead of fleeing, babies still flee. Animals
 * with prey (foxes hunt chickens and rabbits) attack it now and then.
 */
trait AnimalCombatAI{
	use CombatAI {
		findCombatTarget as private findRevengeTarget;
	}

	protected function isNeutralMob() : bool{
		return true;
	}

	/** @return list<string> entity ids this animal hunts */
	protected function preyIds() : array{
		return [];
	}

	protected function isBabyMob() : bool{
		return $this instanceof BreedableAnimal && $this->isBaby();
	}

	protected function findCombatTarget() : ?Living{
		$target = $this->findRevengeTarget();
		if($target !== null || $this->isBabyMob()){
			return $target;
		}
		$prey = $this->preyIds();
		if($prey === [] || mt_rand(1, 8) !== 1){
			return null;
		}
		$found = MobBehaviours::nearest($this, 10.0, fn(Entity $e) : bool => $e instanceof Living && $e->isAlive() && in_array($e::getNetworkTypeId(), $prey, true));
		return $found instanceof Living ? $found : null;
	}

	protected function shouldFleeFromAttacker(EntityDamageByEntityEvent $source) : bool{
		return $this->isBabyMob();
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($this->isAlive() && !$this->isBabyMob()){
			$this->onCombatHurt($source);
		}
	}

	protected function tickWandering(int $tickDiff) : void{
		if(!$this->isBabyMob() && !$this->isPanicking()){
			$target = $this->updateCombatTarget($tickDiff);
			if($target !== null){
				$this->tickCombat($target, $tickDiff);
				return;
			}
		}
		parent::tickWandering($tickDiff);
	}

	/** How the animal fights; melee by default. */
	protected function tickCombat(Living $target, int $tickDiff) : void{
		$this->tickMelee($target, $tickDiff);
	}
}

final class MobBehaviours{

	private function __construct(){
		//NOOP
	}

	/**
	 * The nearest entity within $range matching $filter.
	 * @phpstan-param \Closure(Entity) : bool $filter
	 */
	public static function nearest(Entity $self, float $range, \Closure $filter) : ?Entity{
		$best = null;
		$bestDistance = $range * $range;
		foreach($self->getWorld()->getNearbyEntities($self->getBoundingBox()->expandedCopy($range, $range / 2, $range), $self) as $entity){
			if(!$filter($entity)){
				continue;
			}
			$distance = $entity->getPosition()->distanceSquared($self->getPosition());
			if($distance < $bestDistance){
				$best = $entity;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	/** The nearest survival player within range (optionally ignoring sneaking players). */
	public static function nearestPlayer(Entity $self, float $range, bool $ignoreSneaking = false) : ?Player{
		$found = self::nearest($self, $range, fn(Entity $e) : bool => $e instanceof Player && $e->isAlive() && !$e->isCreative() && !$e->isSpectator() && (!$ignoreSneaking || !$e->isSneaking()));
		return $found instanceof Player ? $found : null;
	}

	/**
	 * Replaces a mob with another kind at the same place (piglins and hoglins
	 * outside the Nether, mooshroom shearing). Keeps name tag, health share and
	 * persistence.
	 *
	 * @phpstan-param class-string<Living> $class
	 */
	public static function transform(Living $mob, string $class) : ?Living{
		if($mob->isClosed() || $mob->isFlaggedForDespawn()){
			return null;
		}
		$location = Location::fromObject($mob->getPosition(), $mob->getWorld(), $mob->getLocation()->getYaw(), $mob->getLocation()->getPitch());
		$new = new $class($location);
		if($mob->getNameTag() !== ""){
			$new->setNameTag($mob->getNameTag());
			$new->setNameTagAlwaysVisible($mob->isNameTagAlwaysVisible());
		}
		if($mob instanceof BreedableAnimal && $new instanceof \pocketmine\entity\GenericAnimalMob && $mob->isBaby()){
			$new->setBaby(true);
		}
		$new->spawnToAll();
		$mob->flagForDespawn();
		return $new;
	}

	/** Pushes a flying or swimming mob toward a point. */
	public static function flyToward(Entity $mob, Vector3 $target, float $speed) : float{
		$pos = $mob->getPosition();
		$dx = $target->x - $pos->x;
		$dy = $target->y - $pos->y;
		$dz = $target->z - $pos->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance > 0.01){
			$mob->setMotion(new Vector3($dx / $distance * $speed, $dy / $distance * $speed, $dz / $distance * $speed));
			$mob->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
		}
		return $distance;
	}

	/** Piglins, piglin brutes and hoglins outside the Nether turn into zombified versions after 15 s. */
	public static function outsideNether(Entity $mob) : bool{
		return $mob->getWorld()->getDimensionId() !== \pocketmine\network\mcpe\protocol\types\DimensionIds::NETHER;
	}

	public static function isZombifiable(string $id) : bool{
		return in_array($id, [EntityIds::PIGLIN, EntityIds::PIGLIN_BRUTE, EntityIds::HOGLIN], true);
	}

	/** Damage helper for mobs that hit without the melee goal (spit, bites while swimming). */
	public static function hit(Living $attacker, Living $target, float $damage, int $cause = EntityDamageEvent::CAUSE_ENTITY_ATTACK) : bool{
		$event = new EntityDamageByEntityEvent($attacker, $target, $cause, $damage);
		$target->attack($event);
		return !$event->isCancelled();
	}

	public static function isInWater(Entity $entity) : bool{
		$pos = $entity->getPosition();
		return $entity->getWorld()->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) instanceof \pocketmine\block\Water;
	}
}
