<?php

/*
 * BetterPMMP - plain item from the Bedrock palette.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

class GenericBedrockItem extends Item{

	public function __construct(ItemIdentifier $identifier, string $name, private string $bedrockName, private int $maxStackSize = 64){
		parent::__construct($identifier, $name);
	}

	public function getBedrockName() : string{
		return $this->bedrockName;
	}

	public function getMaxStackSize() : int{
		return $this->maxStackSize;
	}
}
