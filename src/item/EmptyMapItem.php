<?php

/*
 * BetterPMMP - empty map.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\map\MapManager;

/** Using an empty map turns it into a filled map of the surrounding area. */
class EmptyMapItem extends GenericBedrockItem{

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$filled = MapManager::createFilledMap($player);
		if($filled === null){
			return ItemUseResult::NONE;
		}
		$this->pop();
		$returnedItems[] = $filled;
		return ItemUseResult::SUCCESS;
	}
}
