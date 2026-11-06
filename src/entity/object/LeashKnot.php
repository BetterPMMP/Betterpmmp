<?php

/*
 * BetterPMMP - lead knot on a fence.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\Fence;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function count;

/** Mobs tied to a fence. Hitting or using the knot frees them; it vanishes with the fence. */
class LeashKnot extends Entity implements NeverSavedWithChunkEntity{

	public static function getNetworkTypeId() : string{ return EntityIds::LEASH_KNOT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.5, 0.375); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.0; }
	public function canBeCollidedWith() : bool{ return true; }

	/** The knot on this fence, created if needed. */
	public static function at(\pocketmine\world\World $world, Vector3 $fence) : self{
		$center = new Vector3($fence->getFloorX() + 0.5, $fence->getFloorY() + 0.25, $fence->getFloorZ() + 0.5);
		foreach($world->getNearbyEntities(new \pocketmine\math\AxisAlignedBB($center->x - 0.5, $center->y - 0.5, $center->z - 0.5, $center->x + 0.5, $center->y + 0.5, $center->z + 0.5)) as $entity){
			if($entity instanceof self && !$entity->isFlaggedForDespawn()){
				return $entity;
			}
		}
		$knot = new self(\pocketmine\entity\Location::fromObject($center, $world));
		$knot->spawnToAll();
		$world->addSound($center, new VanillaSound(LevelSoundEvent::LEASHKNOT_PLACE));
		return $knot;
	}

	/** @return Mob[] */
	private function tiedMobs() : array{
		$mobs = [];
		foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(12, 12, 12)) as $entity){
			if($entity instanceof Mob && $entity->getLeashHolder() === $this){
				$mobs[] = $entity;
			}
		}
		return $mobs;
	}

	private function release(bool $dropLeads) : void{
		foreach($this->tiedMobs() as $mob){
			$mob->unleash($dropLeads);
		}
		$this->getWorld()->addSound($this->location, new VanillaSound(LevelSoundEvent::LEASHKNOT_BREAK));
		$this->flagForDespawn();
	}

	public function attack(EntityDamageEvent $source) : void{
		$this->release(true);
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$this->release(true);
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		parent::entityBaseTick($tickDiff);
		if($this->ticksLived % 20 === 0){
			if(!$this->getWorld()->getBlock($this->location) instanceof Fence){
				$this->release(true);
			}elseif($this->ticksLived > 40 && count($this->tiedMobs()) === 0){
				$this->flagForDespawn();
			}
		}
		return true;
	}
}
