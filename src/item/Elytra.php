<?php

/*
 * BetterPMMP - elytra.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

/**
 * Worn in the chest slot. Bedrock clients glide on their own once they wear
 * it; the server accepts the glide action (see Player::toggleGlide) and wears
 * the elytra down by one point per second of flight.
 */
class Elytra extends Armor{

	public function getMaxStackSize() : int{
		return 1;
	}

	/** An elytra with one durability point left no longer works. */
	public function isUsable() : bool{
		return $this->getDamage() < $this->getMaxDurability() - 1;
	}
}
