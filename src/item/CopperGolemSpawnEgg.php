<?php

/*
 * BetterPMMP - copper golem spawn egg.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\CopperGolem;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\World;

final class CopperGolemSpawnEgg extends SpawnEgg{

	protected function createEntity(World $world, Vector3 $pos, float $yaw, float $pitch) : Entity{
		return new CopperGolem(Location::fromObject($pos, $world, $yaw, $pitch));
	}
}
