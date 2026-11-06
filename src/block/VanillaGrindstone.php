<?php

/*
 * BetterPMMP - grindstone block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

class VanillaGrindstone extends GenericBedrockBlock{

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$player->setCurrentWindow(new GrindstoneInventory($this->position));
		}
		return true;
	}
}
