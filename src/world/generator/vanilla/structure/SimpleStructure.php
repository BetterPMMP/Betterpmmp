<?php

/*
 * BetterPMMP - base for biome-bound structures with vanilla spacing.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Random;
use function in_array;

abstract class SimpleStructure extends Structure{

	/**
	 * @param int[] $biomes allowed biomes at the start position (empty = any)
	 */
	public function __construct(
		private string $name,
		private int $spacing,
		private int $separation,
		private int $salt,
		private int $radius,
		private array $biomes,
		private bool $surface = true,
		private bool $triangular = false
	){}

	public function getName() : string{ return $this->name; }
	protected function getSpacing() : int{ return $this->spacing; }
	protected function getSeparation() : int{ return $this->separation; }
	protected function getSalt() : int{ return $this->salt; }
	public function getMaxRadiusChunks() : int{ return $this->radius; }
	public function isSurface() : bool{ return $this->surface; }
	protected function isTriangular() : bool{ return $this->triangular; }

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$x = ($chunkX << 4) + 8;
		$z = ($chunkZ << 4) + 8;
		if(count($this->biomes) > 0 && !in_array($ctx->biomeAt($x, 64, $z), $this->biomes, true)){
			return null;
		}
		$start = new StructureStart($this->name, $chunkX, $chunkZ, $this->surface);
		return $this->layout($ctx, $x, $z, $random, $start) ? $start : null;
	}

	abstract protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool;

	/** Lowest estimated surface height over a rectangle (sampled at corners and centre). */
	protected static function groundLevel(StructureContext $ctx, int $x1, int $z1, int $x2, int $z2) : int{
		return min(
			$ctx->heightAt($x1, $z1), $ctx->heightAt($x2, $z1), $ctx->heightAt($x1, $z2), $ctx->heightAt($x2, $z2),
			$ctx->heightAt(($x1 + $x2) >> 1, ($z1 + $z2) >> 1)
		);
	}

	protected static function averageLevel(StructureContext $ctx, int $x1, int $z1, int $x2, int $z2) : int{
		return (int) round((
			$ctx->heightAt($x1, $z1) + $ctx->heightAt($x2, $z1) + $ctx->heightAt($x1, $z2) + $ctx->heightAt($x2, $z2) +
			$ctx->heightAt(($x1 + $x2) >> 1, ($z1 + $z2) >> 1)
		) / 5);
	}
}
