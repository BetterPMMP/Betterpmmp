<?php

/*
 * BetterPMMP - swapping the main hand and off hand items.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\event\player;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\item\Item;
use pocketmine\player\Player;

/**
 * Called when a player swaps the items in the main hand and the off hand
 * ({@link Player::swapHandItems()}, the /swaphands command or the double-sneak
 * gesture). Any item may be swapped; cancel the event to forbid it.
 */
class PlayerSwapHandItemsEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(Player $player, private Item $mainHandItem, private Item $offHandItem){
		$this->player = $player;
	}

	/** The item that is in the main hand now and goes to the off hand. */
	public function getMainHandItem() : Item{ return clone $this->mainHandItem; }

	/** The item that is in the off hand now and goes to the main hand. */
	public function getOffHandItem() : Item{ return clone $this->offHandItem; }
}
