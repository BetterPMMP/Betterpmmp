<?php

/*
 * BetterPMMP - wind charge item.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Throwable;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\player\Player;

/** Thrown like a snowball (launch power 1.5), half a second cooldown. */
class WindChargeItem extends ProjectileItem{

	public function getMaxStackSize() : int{ return 64; }
	public function getThrowForce() : float{ return 1.5; }
	public function getCooldownTicks() : int{ return 10; }
	public function getCooldownTag() : ?string{ return "wind_charge"; }

	protected function createEntity(Location $location, Player $thrower) : Throwable{
		return new WindCharge($location, $thrower);
	}
}
