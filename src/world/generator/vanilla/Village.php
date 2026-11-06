<?php

/*
 * BetterPMMP - village placement record.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

final class Village{
	public function __construct(
		public readonly int $chunkX,
		public readonly int $chunkZ,
		public readonly int $centerX,
		public readonly int $centerZ,
		public readonly string $group
	){}

	/** Whether a block column belongs to the village area (no trees are placed there). */
	public function covers(int $x, int $z) : bool{
		$dx = $x - $this->centerX;
		$dz = $z - $this->centerZ;
		return $dx * $dx + $dz * $dz <= (VillageGenerator::RADIUS + 3) ** 2;
	}
}
