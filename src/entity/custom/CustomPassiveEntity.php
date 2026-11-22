<?php

/*
 * BetterPMMP - a custom entity that wanders around and flees when hit.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\custom;

use pocketmine\entity\GenericVanillaMob;

final class CustomPassiveEntity extends GenericVanillaMob{
	use CustomEntityTrait;

	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{
		return true;
	}
}
