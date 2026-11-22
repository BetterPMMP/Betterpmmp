<?php

/*
 * BetterPMMP - a custom entity that hunts players like a zombie (melee).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\custom;

use pocketmine\entity\ai\MobStats;
use pocketmine\entity\GenericHostileMob;

final class CustomHostileEntity extends GenericHostileMob{
	use CustomEntityTrait;

	protected function followRange() : float{
		return $this->type->followRange;
	}

	protected function meleeDamage() : float{
		return MobStats::scaleDamage($this->type->damage, $this->getWorld()->getDifficulty());
	}
}
