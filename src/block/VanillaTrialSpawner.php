<?php

/*
 * BetterPMMP - trial spawner block (logic in TrialManager).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;

/** Drops nothing when mined (vanilla); the spawning is done by {@link \pocketmine\world\trial\TrialManager}. */
class VanillaTrialSpawner extends GenericBedrockBlock{

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function getLightLevel() : int{
		return 8;
	}
}
