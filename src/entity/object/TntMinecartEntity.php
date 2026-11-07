<?php

/*
 * BetterPMMP - minecart with TNT.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\ActivatorRail;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function sqrt;

/**
 * Vanilla TNT minecart: a powered activator rail lights it (4 s fuse), fire,
 * explosions and arrows on fire light it too; breaking it while it moves fast
 * makes it explode at once. The blast grows with its speed.
 */
class TntMinecartEntity extends MinecartEntity{

	private int $fuse = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::TNT_MINECART; }
	protected function dropItemName() : ?string{ return "minecraft:tnt_minecart"; }
	public function getMaxRiders() : int{ return 0; }

	public function ignite() : void{
		if($this->fuse < 0){
			$this->fuse = 80;
			$this->networkPropertiesDirty = true;
		}
	}

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		if(in_array($cause, [EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK, EntityDamageEvent::CAUSE_LAVA, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION], true)){
			$this->ignite();
			return;
		}
		$m = $this->getMotion();
		if(sqrt($m->x * $m->x + $m->z * $m->z) > 0.3){
			$this->explode();
			return;
		}
		parent::attack($source);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		$rail = $this->getWorld()->getBlock($this->location);
		if($rail instanceof ActivatorRail && $rail->isPowered()){
			$this->ignite();
		}
		if($this->isOnFire()){
			$this->ignite();
		}
		if($this->fuse >= 0 && ($this->fuse -= $tickDiff) <= 0){
			$this->explode();
		}
		return $update;
	}

	public function explode() : void{
		if($this->isFlaggedForDespawn()){
			return;
		}
		$this->flagForDespawn();
		$m = $this->getMotion();
		$speed = min(5.0, sqrt($m->x * $m->x + $m->z * $m->z) * 20);
		$explosion = new Explosion(Position::fromObject($this->location->add(0, 0.5, 0), $this->getWorld()), 4 + mt_rand(0, 15) / 10 * $speed, $this);
		$explosion->explodeA();
		$explosion->explodeB();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->fuse >= 0);
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, max(0, $this->fuse));
	}
}
