<?php

/*
 * BetterPMMP - dropper block entity.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

class Dropper extends Dispenser{

	protected function isDropper() : bool{
		return true;
	}
}
