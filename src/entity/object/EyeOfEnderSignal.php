<?php

/*
 * BetterPMMP - thrown eye of ender.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\particle\PortalParticle;
use pocketmine\world\sound\EndermanTeleportSound;
use function mt_rand;
use function sqrt;

/**
 * Flies up to 12 blocks towards the nearest stronghold, climbing when the
 * stronghold is further away, hovers, and after 80 ticks drops back as an
 * item (4 in 5 times) or shatters, like vanilla.
 */
class EyeOfEnderSignal extends Entity{

	public static function getNetworkTypeId() : string{ return EntityIds::EYE_OF_ENDER_SIGNAL; }

	private Vector3 $target;
	private int $life = 0;
	private bool $survives;

	public function __construct(Location $location, Vector3 $stronghold){
		parent::__construct($location);
		$dx = $stronghold->x - $location->x;
		$dz = $stronghold->z - $location->z;
		$distance = sqrt($dx * $dx + $dz * $dz);
		if($distance > 12){
			$this->target = new Vector3($location->x + $dx / $distance * 12, $location->y + 8, $location->z + $dz / $distance * 12);
		}else{
			$this->target = new Vector3($stronghold->x, $location->y, $stronghold->z);
		}
		$this->survives = mt_rand(0, 4) > 0;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.0; }
	public function canSaveWithChunk() : bool{ return false; }
	public function canBeCollidedWith() : bool{ return false; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->life += $tickDiff;
		$pos = $this->getPosition();
		$dx = $this->target->x - $pos->x;
		$dz = $this->target->z - $pos->z;
		$horizontal = sqrt($dx * $dx + $dz * $dz);
		$speed = $horizontal > 1 ? 0.25 : $horizontal * 0.2;
		$vy = ($this->target->y - $pos->y) * 0.06;
		if($horizontal > 0.001){
			$this->setMotion(new Vector3($dx / $horizontal * $speed, $vy, $dz / $horizontal * $speed));
		}else{
			$this->setMotion(new Vector3(0, $vy, 0));
		}
		if($this->life % 2 === 0){
			$this->getWorld()->addParticle($pos, new PortalParticle());
		}
		if($this->life >= 80){
			if($this->survives){
				$this->getWorld()->dropItem($pos, VanillaItems::ENDER_EYE());
			}else{
				$this->getWorld()->addSound($pos, new EndermanTeleportSound());
			}
			$this->flagForDespawn();
		}
		return true;
	}
}
