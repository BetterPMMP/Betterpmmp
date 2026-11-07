<?php

/*
 * BetterPMMP - blue and brown eggs (cold / warm chicken variants).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Egg as EggEntity;
use pocketmine\entity\projectile\Throwable;
use pocketmine\player\Player;

/** Thrown like a normal egg: 1 in 8 hatches a chick. */
class ColoredEgg extends ProjectileItem{

	public function getMaxStackSize() : int{ return 16; }
	public function getThrowForce() : float{ return 1.5; }

	protected function createEntity(Location $location, Player $thrower) : Throwable{
		return new EggEntity($location, $thrower);
	}
}
