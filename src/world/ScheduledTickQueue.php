<?php

/*
 * BetterPMMP - scheduled block ticks in a fixed order.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

/**
 * Block ticks ordered by due tick, then by the order they were scheduled in, like vanilla. A plain
 * priority queue returns ticks that are due in the same game tick in an arbitrary order, so the same
 * redstone circuit could behave differently from one run to the next.
 *
 * Priorities are [due tick, schedule number].
 * @phpstan-extends \SplPriorityQueue<array{int, int}, mixed>
 */
final class ScheduledTickQueue extends \SplPriorityQueue{

	/**
	 * @param mixed $priority1
	 * @param mixed $priority2
	 */
	#[\ReturnTypeWillChange]
	public function compare($priority1, $priority2){
		return $priority2 <=> $priority1; //smallest [due, number] first
	}
}
