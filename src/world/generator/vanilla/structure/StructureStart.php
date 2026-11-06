<?php

/*
 * BetterPMMP - a placed structure instance.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use function max;
use function min;

final class StructureStart{
	public int $minX = PHP_INT_MAX;
	public int $minY = PHP_INT_MAX;
	public int $minZ = PHP_INT_MAX;
	public int $maxX = PHP_INT_MIN;
	public int $maxY = PHP_INT_MIN;
	public int $maxZ = PHP_INT_MIN;
	/** @var StructurePiece[] */
	public array $pieces = [];
	/** @var array<string, array{int, int, int}> named points of interest (e.g. the stronghold portal room) */
	public array $markers = [];

	public function __construct(
		public readonly string $type,
		public readonly int $chunkX,
		public readonly int $chunkZ,
		public readonly bool $surface
	){}

	public function add(StructurePiece $piece) : StructurePiece{
		$this->pieces[] = $piece;
		$this->minX = min($this->minX, $piece->minX);
		$this->minY = min($this->minY, $piece->minY);
		$this->minZ = min($this->minZ, $piece->minZ);
		$this->maxX = max($this->maxX, $piece->maxX);
		$this->maxY = max($this->maxY, $piece->maxY);
		$this->maxZ = max($this->maxZ, $piece->maxZ);
		return $piece;
	}

	/** Whether a new piece would overlap an existing one (with a margin). */
	public function collides(StructurePiece $piece, int $margin = 0) : bool{
		foreach($this->pieces as $other){
			if($other->intersects($piece, $margin)){
				return true;
			}
		}
		return false;
	}

	public function isEmpty() : bool{
		return count($this->pieces) === 0;
	}

	public function intersectsColumn(int $minX, int $minZ, int $maxX, int $maxZ) : bool{
		return !$this->isEmpty() && $this->maxX >= $minX && $this->minX <= $maxX && $this->maxZ >= $minZ && $this->minZ <= $maxZ;
	}

	public function containsColumn(int $x, int $z, int $margin = 0) : bool{
		return !$this->isEmpty() && $x >= $this->minX - $margin && $x <= $this->maxX + $margin && $z >= $this->minZ - $margin && $z <= $this->maxZ + $margin;
	}
}
