<?php

/*
 * BetterPMMP - payment slot of the beacon screen.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\inventory;

use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\TemporaryInventory;
use pocketmine\world\Position;

class BeaconInventory extends SimpleInventory implements BlockInventory, TemporaryInventory{
	use BlockInventoryTrait;

	public const SLOT_PAYMENT = 0;

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(1);
	}
}
