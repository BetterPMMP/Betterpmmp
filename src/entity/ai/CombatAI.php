<?php

/*
 * BetterPMMP - vanilla-style target selection and melee combat.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Villager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;
use function abs;
use function atan2;
use function in_array;
use function max;
use function mt_rand;
use function rad2deg;

/**
 * Target selection like vanilla's NearestAttackableTargetGoal and
 * HurtByTargetGoal, plus MeleeAttackGoal driven by the path {@link Navigator}.
 *
 * - Targets need line of sight to be acquired and are dropped beyond the
 *   follow range.
 * - Mobs that were hurt turn on their attacker.
 * - Zombies also hunt villagers and wandering traders; illagers hunt villagers
 *   and iron golems.
 * - Endermen and zombified piglins are neutral until provoked; endermen are
 *   provoked by being looked at. Spiders do not start fights in bright light.
 * - Damage follows the world difficulty; peaceful removes hostile mobs.
 */
trait CombatAI{

	private ?Living $combatTarget = null;
	private ?Living $revengeTarget = null;
	private int $revengeTicks = 0;
	private int $angerTicks = 0;
	private int $targetScanTicks = 0;

	protected function combatId() : string{
		return static::getNetworkTypeId();
	}

	protected function followRange() : float{
		$stats = MobStats::get($this->combatId());
		return $stats !== null ? (float) $stats[3] : 16.0;
	}

	protected function meleeDamage() : float{
		$stats = MobStats::get($this->combatId());
		$base = $stats !== null ? $stats[2] : 3.0;
		return MobStats::scaleDamage($base, $this->getWorld()->getDifficulty());
	}

	protected function isNeutralMob() : bool{
		return in_array($this->combatId(), [EntityIds::ENDERMAN, EntityIds::ZOMBIE_PIGMAN], true);
	}

	protected function huntsVillagers() : bool{
		return in_array($this->combatId(), [EntityIds::ZOMBIE, EntityIds::HUSK, EntityIds::DROWNED, EntityIds::ZOMBIE_VILLAGER,
			EntityIds::VINDICATOR, EntityIds::PILLAGER, EntityIds::EVOCATION_ILLAGER, EntityIds::RAVAGER], true);
	}

	public function getCombatTarget() : ?Living{
		return $this->combatTarget;
	}

	/** Called when the mob is hurt: remember the attacker (HurtByTargetGoal). */
	protected function onCombatHurt(EntityDamageEvent $source) : void{
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent){
			return;
		}
		$attacker = $source->getDamager();
		if($attacker instanceof Living && $attacker !== $this && !($attacker instanceof Player && ($attacker->isCreative() || $attacker->isSpectator()))){
			$this->revengeTarget = $attacker;
			$this->revengeTicks = 400;
			$this->angerTicks = 400 + mt_rand(0, 400);
			$this->combatTarget = $attacker;
			if($this->combatId() === EntityIds::ZOMBIE_PIGMAN){
				$this->alertPack($attacker);
			}
		}
	}

	/** Zombified piglins call nearby piglins of their kind for help. */
	private function alertPack(Living $attacker) : void{
		foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(20, 10, 20), $this) as $entity){
			if($entity instanceof self && $entity->combatId() === $this->combatId()){
				$entity->angerAt($attacker);
			}
		}
	}

	public function angerAt(Living $target) : void{
		$this->combatTarget = $target;
		$this->revengeTarget = $target;
		$this->revengeTicks = 400;
		$this->angerTicks = 400 + mt_rand(0, 400);
	}

	protected function isValidCombatTarget(Living $target, bool $keep) : bool{
		if(!$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->getWorld()){
			return false;
		}
		if($target instanceof Player && (!$target->isConnected() || $target->isCreative() || $target->isSpectator())){
			return false;
		}
		$range = $this->followRange();
		return $target->getPosition()->distanceSquared($this->getPosition()) <= $range * $range;
	}

	protected function canSeeEntity(Entity $target) : bool{
		$world = $this->getWorld();
		$start = $this->getEyePos();
		$end = $target->getEyePos();
		if($start->distanceSquared($end) > 128 * 128){
			return false;
		}
		$startBlock = $start->floor();
		$endBlock = $end->floor();
		foreach(VoxelRayTrace::betweenPoints($start, $end) as $blockPos){
			if($blockPos->equals($startBlock) || $blockPos->equals($endBlock)){
				continue;
			}
			$x = $blockPos->getFloorX();
			$z = $blockPos->getFloorZ();
			if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
				return false;
			}
			$block = $world->getBlockAt($x, $blockPos->getFloorY(), $z);
			if($block->isSolid() && !$block->isTransparent()){
				return false;
			}
		}
		return true;
	}

	/** Whether an enderman is being stared at by this player. */
	private function isLookedAtBy(Player $player) : bool{
		if(!$player->getArmorInventory()->getHelmet()->isNull() && $player->getArmorInventory()->getHelmet()->getName() === "Carved Pumpkin"){
			return false;
		}
		$look = $player->getDirectionVector();
		$eye = $player->getEyePos();
		$to = $this->getEyePos()->subtractVector($eye);
		$distance = $to->length();
		if($distance < 0.001 || $distance > 64){
			return false;
		}
		$to = $to->divide($distance);
		return $look->dot($to) > 1.0 - 0.025 / $distance && $this->canSeeEntity($player);
	}

	protected function findCombatTarget() : ?Living{
		if($this->revengeTarget !== null && $this->revengeTicks > 0 && $this->isValidCombatTarget($this->revengeTarget, true)){
			return $this->revengeTarget;
		}
		$neutral = $this->isNeutralMob();
		if($neutral && $this->angerTicks <= 0){
			if($this->combatId() === EntityIds::ENDERMAN){
				foreach($this->getWorld()->getPlayers() as $player){
					if($this->isValidCombatTarget($player, false) && $this->isLookedAtBy($player)){
						$this->angerTicks = 400 + mt_rand(0, 400);
						return $player;
					}
				}
			}
			return null;
		}
		if(($this->combatId() === EntityIds::SPIDER || $this->combatId() === EntityIds::CAVE_SPIDER) && $this->angerTicks <= 0){
			$pos = $this->getPosition();
			$light = $this->getWorld()->getFullLightAt($pos->getFloorX(), $pos->getFloorY() + 1, $pos->getFloorZ());
			if($light >= 12){
				return null; // spiders are neutral in daylight
			}
		}

		$best = null;
		$bestDistance = PHP_INT_MAX;
		$range = $this->followRange();
		$candidates = $this->getWorld()->getPlayers();
		if($this->huntsVillagers()){
			foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy($range, $range / 2, $range), $this) as $entity){
				if($entity instanceof Villager || ($entity instanceof Living && $entity::getNetworkTypeId() === EntityIds::WANDERING_TRADER)){
					$candidates[] = $entity;
				}
			}
		}
		foreach($candidates as $candidate){
			if(!$this->isValidCombatTarget($candidate, false)){
				continue;
			}
			$distance = $candidate->getPosition()->distanceSquared($this->getPosition());
			if($candidate instanceof Player && $candidate->isSneaking()){
				$distance /= 0.64; // sneaking players are noticed at 80% range
				if($distance > $range * $range){
					continue;
				}
			}
			if($distance < $bestDistance && $this->canSeeEntity($candidate)){
				$best = $candidate;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	/**
	 * Runs target selection. Returns the current target (or null).
	 */
	protected function updateCombatTarget(int $tickDiff) : ?Living{
		$this->revengeTicks = max(0, $this->revengeTicks - $tickDiff);
		$this->angerTicks = max(0, $this->angerTicks - $tickDiff);
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL && !$this->isNeutralMob()){
			$this->flagForDespawn();
			return null;
		}
		if($this->combatTarget !== null && !$this->isValidCombatTarget($this->combatTarget, true)){
			$this->combatTarget = null;
			$this->getNavigator()->stop();
		}
		$this->targetScanTicks -= $tickDiff;
		if($this->targetScanTicks <= 0){
			$this->targetScanTicks = mt_rand(8, 12);
			$found = $this->findCombatTarget();
			if($found !== null && ($this->combatTarget === null || !($this->combatTarget instanceof Player) || $found === $this->revengeTarget)){
				$this->combatTarget = $found;
			}
		}
		return $this->combatTarget;
	}

	/** MeleeAttackGoal: path to the target and hit it when in reach. */
	protected function tickMelee(Living $target, int $tickDiff) : void{
		$navigator = $this->getNavigator();
		$targetPos = $target->getPosition();
		$distanceSq = $this->getPosition()->distanceSquared($targetPos);
		$reachSq = MobStats::reachSq($this->getSize()->getWidth(), $target->getSize()->getWidth());
		$navigator->moveTo($targetPos, $this->getWalkSpeed(true), 0.8);
		$navigator->tick($tickDiff);
		$dx = $targetPos->x - $this->getPosition()->x;
		$dz = $targetPos->z - $this->getPosition()->z;
		if($dx * $dx + $dz * $dz > 0.0001){
			$this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
		}
		if($distanceSq <= $reachSq && $this->attackTime <= 0 && abs($targetPos->y - $this->getPosition()->y) < 2.5){
			$damage = $this->meleeDamage();
			if($damage <= 0){
				return;
			}
			$this->attackTime = 20;
			$this->broadcastAnimation(new ArmSwingAnimation($this));
			$event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
			$target->attack($event);
			if(!$event->isCancelled()){
				$this->onMeleeHit($target);
			}
		}
	}

	/** Extra effects on a successful hit (husk hunger, wither skeleton wither, cave spider poison...). */
	protected function onMeleeHit(Living $target) : void{
		$effect = match($this->combatId()){
			EntityIds::HUSK => [\pocketmine\entity\effect\VanillaEffects::HUNGER(), 140 * max(1, $this->getWorld()->getDifficulty())],
			EntityIds::WITHER_SKELETON => [\pocketmine\entity\effect\VanillaEffects::WITHER(), 200],
			EntityIds::CAVE_SPIDER => $this->getWorld()->getDifficulty() >= World::DIFFICULTY_NORMAL ? [\pocketmine\entity\effect\VanillaEffects::POISON(), $this->getWorld()->getDifficulty() === World::DIFFICULTY_HARD ? 300 : 140] : null,
			default => null,
		};
		if($effect !== null){
			$target->getEffects()->add(new \pocketmine\entity\effect\EffectInstance($effect[0], $effect[1]));
		}
		if($this->combatId() === EntityIds::ZOMBIE || $this->combatId() === EntityIds::HUSK || $this->combatId() === EntityIds::DROWNED){
			// zombies set the target on fire when they burn themselves
			if($this->isOnFire() && mt_rand(0, 2) < $this->getWorld()->getDifficulty()){
				$target->setOnFire(2 * $this->getWorld()->getDifficulty());
			}
		}
	}
}
