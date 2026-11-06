<?php

/*
 * BetterPMMP - dispenser / dropper inventory.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\inventory;

use pocketmine\inventory\SimpleInventory;
use pocketmine\world\Position;

class DispenserInventory extends SimpleInventory implements BlockInventory{
	use BlockInventoryTrait;

	public function __construct(Position $holder, private bool $dropper = false){
		$this->holder = $holder;
		parent::__construct(9);
	}

	public function isDropper() : bool{
		return $this->dropper;
	}
}
