<?php

/*
 * BetterPMMP - crafter grid.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\inventory;

use pocketmine\crafting\CraftingGrid;
use pocketmine\item\Item;
use pocketmine\world\Position;

/** 3x3 crafting grid of a crafter; disabled slots stay empty. */
final class CrafterInventory extends CraftingGrid implements BlockInventory{
	use BlockInventoryTrait;

	/** @var array<int, true> */
	private array $disabled = [];

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(CraftingGrid::SIZE_BIG);
	}

	public function isSlotDisabled(int $slot) : bool{
		return isset($this->disabled[$slot]);
	}

	public function setSlotDisabled(int $slot, bool $disabled) : void{
		if($disabled){
			$this->disabled[$slot] = true;
		}else{
			unset($this->disabled[$slot]);
		}
	}

	public function getDisabledMask() : int{
		$mask = 0;
		foreach($this->disabled as $slot => $_){
			$mask |= 1 << $slot;
		}
		return $mask;
	}

	public function setDisabledMask(int $mask) : void{
		$this->disabled = [];
		for($slot = 0; $slot < 9; ++$slot){
			if(($mask & (1 << $slot)) !== 0){
				$this->disabled[$slot] = true;
			}
		}
	}

	/** Recomputes the recipe bounds (they are only updated on setItem). */
	public function refresh() : void{
		foreach($this->getContents(true) as $slot => $item){
			parent::setItem($slot, $item);
		}
	}

	public function setItem(int $index, Item $item) : void{
		if($this->isSlotDisabled($index) && !$item->isNull()){
			return;
		}
		parent::setItem($index, $item);
	}
}
