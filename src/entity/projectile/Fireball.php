<?php

/*
 * BetterPMMP - fireballs of ghasts and blazes.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\Explosion;
use pocketmine\world\Position;

/**
 * Vanilla fireballs. The ghast's large one explodes (power 1, with fire) and
 * can be hit back; the blaze's small one does 5 damage, sets its target on
 * fire for 5 s and lights a fire where it lands.
 */
class Fireball extends Throwable implements NeverSavedWithChunkEntity{

	protected bool $large = true;

	public static function getNetworkTypeId() : string{ return EntityIds::FIREBALL; }

	protected function getInitialGravity() : float{ return 0.0; }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	public function canBeCollidedWith() : bool{ return $this->large; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->ticksLived > 200){
			$this->flagForDespawn();
		}
		return $update;
	}

	/** A player can hit a ghast fireball back. */
	public function attack(EntityDamageEvent $source) : void{
		if($this->large && $source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living){
			$this->setOwningEntity($damager);
			$this->setMotion($damager->getDirectionVector()->multiply(0.9));
		}
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$owner = $this->getOwningEntity();
		$damage = $this->large ? 6 : 5;
		$entityHit->attack($owner !== null
			? new EntityDamageByChildEntityEvent($owner, $this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage)
			: new EntityDamageByEntityEvent($this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage));
		if(!$this->large){
			$entityHit->setOnFire(5);
		}
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$world = $this->getWorld();
		$at = $event->getRayTraceResult()->getHitVector();
		if($this->large){
			$explosion = new Explosion(Position::fromObject($at, $world), 1.0, $this, 0.33);
			$explosion->explodeA();
			$explosion->explodeB();
		}else{
			$pos = $at->floor();
			foreach([$pos, $pos->up()] as $p){
				if($world->getBlock($p)->getTypeId() === BlockTypeIds::AIR && $world->getBlock($p->down())->isSolid()){
					$world->setBlock($p, VanillaBlocks::FIRE());
					break;
				}
			}
		}
		$this->flagForDespawn();
	}
}
