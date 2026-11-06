<?php

/*
 * BetterPMMP - boats of the palette the core lacks (cherry, pale oak, chest boats, rafts).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\entity\object\BoatEntity;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function array_search;
use function str_contains;
use function str_replace;

class VehicleBoatItem extends GenericBedrockItem{

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$name = str_replace("minecraft:", "", $this->getBedrockName());
		$chest = str_contains($name, "chest");
		$wood = str_replace(["_chest_boat", "_boat", "_chest_raft", "_raft"], "", $name);
		$variant = array_search($wood, BoatEntity::WOODS, true);
		if(BoatEntity::placeFromItem($player, $variant === false ? 0 : $variant, $chest)){
			$this->pop();
			return ItemUseResult::SUCCESS;
		}
		return ItemUseResult::NONE;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		return $this->onClickAir($player, $player->getDirectionVector(), $returnedItems);
	}
}
