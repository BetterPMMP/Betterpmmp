<?php

/*
 * BetterPMMP - vanilla redstone components.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\utils;

/**
 * A redstone source whose output depends on direction.
 * $towards is the direction from the source to the receiving block.
 */
interface DirectionalRedstoneSource extends RedstoneSignalEmitter{

	/** Power seen by a component or wire directly next to this source. */
	public function getWeakPowerTowards(int $towards) : int;

	/** Power put into a solid block on that side (which then powers its own neighbours). */
	public function getStrongPowerTowards(int $towards) : int;
}
