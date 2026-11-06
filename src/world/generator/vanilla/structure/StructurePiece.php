<?php

/*
 * BetterPMMP - one part of a multi-chunk structure.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Random;

/**
 * A structure part with a bounding box. Its drawer is called once for every
 * chunk the box overlaps; the canvas drops writes outside that chunk and the
 * random is re-seeded identically each time, so all chunks agree.
 */
final class StructurePiece{
	/**
	 * @phpstan-param \Closure(StructureCanvas, Random) : void $drawer
	 */
	public function __construct(
		public readonly int $minX,
		public readonly int $minY,
		public readonly int $minZ,
		public readonly int $maxX,
		public readonly int $maxY,
		public readonly int $maxZ,
		private \Closure $drawer,
		public readonly int $seed = 0
	){}

	public function intersectsColumn(int $minX, int $minZ, int $maxX, int $maxZ) : bool{
		return $this->maxX >= $minX && $this->minX <= $maxX && $this->maxZ >= $minZ && $this->minZ <= $maxZ;
	}

	public function intersects(StructurePiece $other, int $margin = 0) : bool{
		return $this->maxX + $margin >= $other->minX && $this->minX - $margin <= $other->maxX
			&& $this->maxY + $margin >= $other->minY && $this->minY - $margin <= $other->maxY
			&& $this->maxZ + $margin >= $other->minZ && $this->minZ - $margin <= $other->maxZ;
	}

	public function draw(StructureCanvas $canvas) : void{
		($this->drawer)($canvas, new Random($this->seed ^ ($this->minX * 73428767) ^ ($this->minY * 912931) ^ ($this->minZ * 1299709)));
	}
}
