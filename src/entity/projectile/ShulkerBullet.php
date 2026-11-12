<?php

/*
 * BetterPMMP - shulker bullet.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/** Homes in on its target; 4 damage and 10 s of levitation. A hit destroys it. */
class ShulkerBullet extends Throwable implements NeverSavedWithChunkEntity{

	private ?Living $target = null;

	public static function getNetworkTypeId() : string{ return EntityIds::SHULKER_BULLET; }

	protected function getInitialGravity() : float{ return 0.0; }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	public function canBeCollidedWith() : bool{ return true; }

	public function setTarget(Living $target) : void{ $this->target = $target; }

	public function attack(EntityDamageEvent $source) : void{
		$this->flagForDespawn();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		$target = $this->target;
		if($this->ticksLived > 400 || $target === null || $target->isClosed() || !$target->isAlive() || $target->getWorld() !== $this->getWorld()){
			$this->flagForDespawn();
			return $update;
		}
		$to = $target->getPosition()->add(0, $target->getSize()->getHeight() * 0.5, 0)->subtractVector($this->location);
		if($to->lengthSquared() > 0.01){
			$this->setMotion($this->motion->multiply(0.8)->addVector($to->normalize()->multiply(0.06)));
		}
		return true;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$owner = $this->getOwningEntity();
		$entityHit->attack($owner !== null
			? new EntityDamageByChildEntityEvent($owner, $this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, 4)
			: new EntityDamageByEntityEvent($this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, 4));
		if($entityHit instanceof Living){
			$entityHit->getEffects()->add(new EffectInstance(VanillaEffects::LEVITATION(), 200, 0));
		}
		$this->flagForDespawn();
	}
}
