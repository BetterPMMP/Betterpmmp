<?php

/*
 * BetterPMMP - an edible item defined in custom_items.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\custom;

use pocketmine\item\Food;
use pocketmine\item\ItemIdentifier;

class CustomFood extends Food{

	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		private int $maxStackSize,
		private int $nutrition,
		private float $saturation,
		private bool $canAlwaysEat
	){
		parent::__construct($identifier, $name);
	}

	public function getMaxStackSize() : int{
		return $this->maxStackSize;
	}

	public function getFoodRestore() : int{
		return $this->nutrition;
	}

	public function getSaturationRestore() : float{
		return $this->saturation;
	}

	public function requiresHunger() : bool{
		return !$this->canAlwaysEat;
	}
}
