<?php

/*
 * BetterPMMP - an item of the Bedrock palette that places a block (poplar door and signs, kelp, straw bed).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\ExtraVanillaBlocks;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;

/**
 * Some palette items have the same name as their block (or a sign item that places a standing or a wall
 * sign). Without this they could be held but not placed.
 */
class PlacingBedrockItem extends GenericBedrockItem{

	/**
	 * @param string      $blockName block placed on top of a block (and by default on its sides)
	 * @param string|null $wallBlockName block placed on the side of a block (wall signs)
	 */
	public function __construct(ItemIdentifier $identifier, string $name, string $bedrockName, int $maxStackSize, private string $blockName, private ?string $wallBlockName = null){
		parent::__construct($identifier, $name, $bedrockName, $maxStackSize);
	}

	public function getBlock(?int $clickedFace = null) : Block{
		$name = $this->wallBlockName !== null && $clickedFace !== null && Facing::axis($clickedFace) !== \pocketmine\math\Axis::Y ? $this->wallBlockName : $this->blockName;
		return ExtraVanillaBlocks::get($name) ?? VanillaBlocks::AIR();
	}
}
