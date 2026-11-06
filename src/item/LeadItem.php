<?php

/*
 * BetterPMMP - lead.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\Fence;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\entity\object\LeashKnot;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/** Put a mob on a lead; use it on a fence to tie up all mobs you lead. */
class LeadItem extends GenericBedrockItem{

	public function onInteractEntity(Player $player, Entity $entity, Vector3 $clickVector) : bool{
		if(!$entity instanceof Mob || !$entity->canBeLeashed() || $entity->getLeashHolder() !== null || !$entity->isAlive()){
			return false;
		}
		$entity->setLeashHolder($player);
		if($player->hasFiniteResources()){
			$this->pop();
		}
		return true;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		return self::tieToFence($player, $blockClicked) ? ItemUseResult::SUCCESS : ItemUseResult::NONE;
	}

	/** Moves every mob the player leads to a knot on the fence (also works with an empty hand in vanilla). */
	public static function tieToFence(Player $player, Block $block) : bool{
		if(!$block instanceof Fence){
			return false;
		}
		$knot = null;
		foreach($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(10, 10, 10)) as $entity){
			if($entity instanceof Mob && $entity->getLeashHolder() === $player){
				$knot ??= LeashKnot::at($player->getWorld(), $block->getPosition());
				$entity->setLeashHolder($knot);
			}
		}
		return $knot !== null;
	}
}
