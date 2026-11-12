<?php

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/** BetterPMMP: the blaze's fireball (see {@link Fireball}). */
class SmallFireball extends Fireball{
	protected bool $large = false;

	public static function getNetworkTypeId() : string{ return EntityIds::SMALL_FIREBALL; }
}
