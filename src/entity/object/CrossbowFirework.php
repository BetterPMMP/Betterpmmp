<?php

/*
 * BetterPMMP - firework rocket shot from a crossbow.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\Living;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use function count;

/**
 * Flies straight (no rising like a launched rocket) and explodes when it hits
 * a mob or a block or its flight time is up. A rocket without stars still
 * does 5 damage to what it hits, like vanilla.
 */
class CrossbowFirework extends FireworkRocket implements NeverSavedWithChunkEntity{

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed || $this->isFlaggedForDespawn()){
			return false;
		}
		\pocketmine\entity\Entity::entityBaseTick($tickDiff); // skip the rising of a launched rocket
		$hit = null;
		foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(0.3, 0.3, 0.3), $this) as $entity){
			if($entity instanceof Living && $entity->isAlive() && $entity !== $this->getOwningEntity()){
				$hit = $entity;
				break;
			}
		}
		if($hit !== null || $this->isCollided || $this->ticksLived >= $this->getMaxFlightTimeTicks()){
			if($hit !== null && count($this->getExplosions()) === 0){
				$owner = $this->getOwningEntity();
				$hit->attack($owner !== null
					? new EntityDamageByEntityEvent($owner, $hit, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 5)
					: new EntityDamageEvent($hit, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 5));
			}
			$this->flagForDespawn();
			$this->explode();
		}
		return true;
	}
}
