<?php

/*
 * BetterPMMP - wind charge (thrown by players and shot by breezes).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\block\Button;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Lever;
use pocketmine\block\Trapdoor;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function floor;
use function max;
use function min;

/**
 * Vanilla wind charge: flies straight (no gravity), 1 damage on a direct hit,
 * then bursts: knocks back every entity within 2.4 blocks (stronger the
 * closer; lets players launch themselves), resets their fall distance and
 * toggles doors, trapdoors, fence gates, levers and buttons it touches.
 */
class WindCharge extends Throwable implements NeverSavedWithChunkEntity{

	protected float $burstRadius = 2.4;
	protected float $knockback = 1.1;

	public static function getNetworkTypeId() : string{ return EntityIds::WIND_CHARGE_PROJECTILE; }

	protected function getInitialGravity() : float{ return 0.0; }
	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->ticksLived > 200 || $this->location->y > $this->getWorld()->getMaxY() + 16){
			$this->flagForDespawn();
		}
		return $update;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$owner = $this->getOwningEntity();
		$entityHit->attack($owner !== null
			? new EntityDamageByChildEntityEvent($owner, $this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, 1)
			: new EntityDamageByEntityEvent($this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, 1));
		$this->flagForDespawn();
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$this->burst($event->getRayTraceResult()->getHitVector());
		$this->flagForDespawn();
	}

	protected function burstParticle() : int{ return LevelEvent::PARTICLE_WIND_EXPLOSION; }
	protected function burstSound() : string{ return LevelSoundEvent::WIND_CHARGE_BURST; }

	public function burst(Vector3 $center) : void{
		$world = $this->getWorld();
		$world->broadcastPacketToViewers($center, LevelEventPacket::create($this->burstParticle(), 0, $center));
		$world->addSound($center, new VanillaSound($this->burstSound()));
		$r = $this->burstRadius;
		foreach($world->getNearbyEntities($this->getBoundingBox()->expandedCopy($r, $r, $r), $this) as $entity){
			if(!$entity instanceof Living && !$entity instanceof \pocketmine\entity\object\ItemEntity){
				continue;
			}
			$pos = $entity->getPosition()->add(0, $entity->getSize()->getHeight() / 2, 0);
			$d = $pos->subtractVector($center);
			$distance = $d->length();
			if($distance > $r){
				continue;
			}
			$strength = (1 - $distance / $r) * $this->knockback;
			$dir = $distance < 0.01 ? new Vector3(0, 1, 0) : $d->divide($distance);
			$motion = $entity->getMotion()->addVector($dir->multiply($strength))->add(0, $strength * 0.6, 0);
			$entity->setMotion(new Vector3($motion->x, min(1.6, max($motion->y, $strength)), $motion->z));
			$entity->resetFallDistance();
			if($entity instanceof Player){
				$entity->resetFallDistance();
			}
		}
		// interactive blocks around the burst
		for($x = (int) floor($center->x - 1); $x <= (int) floor($center->x + 1); ++$x){
			for($y = (int) floor($center->y - 1); $y <= (int) floor($center->y + 1); ++$y){
				for($z = (int) floor($center->z - 1); $z <= (int) floor($center->z + 1); ++$z){
					$block = $world->getBlockAt($x, $y, $z);
					if($block instanceof Door && !$block->isTop() && $block->getTypeId() !== \pocketmine\block\BlockTypeIds::IRON_DOOR){
						$block->setOpen(!$block->isOpen());
						$world->setBlock($block->getPosition(), $block);
						$top = $world->getBlockAt($x, $y + 1, $z);
						if($top instanceof Door){
							$world->setBlock($top->getPosition(), $top->setOpen($block->isOpen()));
						}
					}elseif(($block instanceof Trapdoor && $block->getTypeId() !== \pocketmine\block\BlockTypeIds::IRON_TRAPDOOR) || $block instanceof FenceGate){
						$block->setOpen(!$block->isOpen());
						$world->setBlock($block->getPosition(), $block);
					}elseif($block instanceof Lever){
						$block->onInteract(\pocketmine\item\VanillaItems::AIR(), 0, Vector3::zero());
					}elseif($block instanceof Button && !$block->isPressed()){
						$block->onInteract(\pocketmine\item\VanillaItems::AIR(), 0, Vector3::zero());
					}
				}
			}
		}
	}
}
