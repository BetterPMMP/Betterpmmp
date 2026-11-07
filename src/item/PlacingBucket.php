<?php

/*
 * BetterPMMP - powder snow bucket.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\ExtraVanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;

/** Places powder snow and leaves an empty bucket. */
class PlacingBucket extends GenericBedrockItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$block = ExtraVanillaBlocks::get("minecraft:powder_snow");
		if($block === null || !$blockReplace->canBeReplaced()){
			return ItemUseResult::NONE;
		}
		$player->getWorld()->setBlock($blockReplace->getPosition(), $block);
		$player->getWorld()->addSound($blockReplace->getPosition(), new VanillaSound(LevelSoundEvent::BUCKET_EMPTY_POWDER_SNOW));
		if($player->hasFiniteResources()){
			$this->pop();
			$returnedItems[] = VanillaItems::BUCKET();
		}
		return ItemUseResult::SUCCESS;
	}
}
