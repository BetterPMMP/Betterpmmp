<?php

/*
 * BetterPMMP - minecart variants placed on rails.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\entity\object\MinecartEntity;
use pocketmine\entity\object\TntMinecartEntity;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/** Minecart with TNT (and the other variants as plain carts until they get their own entity). */
class VariantMinecart extends GenericBedrockItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$class = $this->getBedrockName() === "minecraft:tnt_minecart" ? TntMinecartEntity::class : MinecartEntity::class;
		if(MinecartEntity::placeOnRail($player, $player->getWorld(), $blockClicked->getPosition(), $class)){
			if($player->hasFiniteResources()){
				$this->pop();
			}
			return ItemUseResult::SUCCESS;
		}
		return ItemUseResult::NONE;
	}
}
