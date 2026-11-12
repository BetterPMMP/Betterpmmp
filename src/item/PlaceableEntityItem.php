<?php

/*
 * BetterPMMP - items that place an entity (armor stand, cushions).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\Cushion;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function floor;
use function str_ends_with;
use function strlen;
use function substr;

class PlaceableEntityItem extends GenericBedrockItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(!$blockReplace->canBeReplaced()){
			return ItemUseResult::NONE;
		}
		$world = $player->getWorld();
		$pos = $blockReplace->getPosition()->add(0.5, 0, 0.5);
		// faces the player, in steps of 45 degrees
		$yaw = floor(($player->getLocation()->getYaw() + 180 + 22.5) / 45) * 45;
		$location = Location::fromObject($pos, $world, $yaw, 0);
		$name = $this->getBedrockName();
		if($name === "minecraft:armor_stand"){
			if(!$world->getBlock($blockReplace->getPosition()->up())->canBeReplaced()){
				return ItemUseResult::NONE;
			}
			$entity = new ArmorStand($location);
		}elseif(str_ends_with($name, "_cushion")){
			$entity = new Cushion($location);
			$entity->setColor(substr($name, strlen("minecraft:"), -strlen("_cushion")));
		}else{
			return ItemUseResult::NONE;
		}
		$entity->spawnToAll();
		if($player->hasFiniteResources()){
			$this->pop();
		}
		return ItemUseResult::SUCCESS;
	}
}
