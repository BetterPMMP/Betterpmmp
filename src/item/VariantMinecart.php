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

/** Minecarts with TNT, chest or hopper (other variants are placed as plain carts). */
class VariantMinecart extends GenericBedrockItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$class = match($this->getBedrockName()){
			"minecraft:tnt_minecart" => TntMinecartEntity::class,
			"minecraft:chest_minecart" => \pocketmine\entity\object\ChestMinecartEntity::class,
			"minecraft:hopper_minecart" => \pocketmine\entity\object\HopperMinecartEntity::class,
			default => MinecartEntity::class,
		};
		if(MinecartEntity::placeOnRail($player, $player->getWorld(), $blockClicked->getPosition(), $class)){
			if($player->hasFiniteResources()){
				$this->pop();
			}
			return ItemUseResult::SUCCESS;
		}
		return ItemUseResult::NONE;
	}
}
