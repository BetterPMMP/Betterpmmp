<?php

/*
 * BetterPMMP - sculk shrieker.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\world\SculkShriekers;

/** Shrieking is driven by {@link SculkShriekers}; this only ends the shriek animation. */
class VanillaSculkShrieker extends GenericBedrockBlock{

	public function onScheduledUpdate() : void{
		SculkShriekers::deactivate($this->position->getWorld(), $this->position);
	}
}
