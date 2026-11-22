<?php

/*
 * BetterPMMP - an item defined in custom_items.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\custom;

use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;

class CustomItem extends Item{

	public function __construct(ItemIdentifier $identifier, string $name, private int $maxStackSize){
		parent::__construct($identifier, $name);
	}

	public function getMaxStackSize() : int{
		return $this->maxStackSize;
	}
}
