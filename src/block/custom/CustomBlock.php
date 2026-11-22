<?php

/*
 * BetterPMMP - a block defined in custom_blocks.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\custom;

use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\Opaque;

class CustomBlock extends Opaque{

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private int $lightLevel){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getLightLevel() : int{
		return $this->lightLevel;
	}
}
