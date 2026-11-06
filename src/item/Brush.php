<?php

/*
 * BetterPMMP - brush.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\VanillaSuspiciousBlock;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/** Brushes suspicious sand and gravel (see {@link VanillaSuspiciousBlock}). */
class Brush extends SimpleDurableItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if($blockClicked instanceof VanillaSuspiciousBlock){
			$blockClicked->brush($player);
			return ItemUseResult::SUCCESS;
		}
		return ItemUseResult::NONE;
	}
}
