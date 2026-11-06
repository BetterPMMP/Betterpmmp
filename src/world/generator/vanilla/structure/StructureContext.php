<?php

/*
 * BetterPMMP - structure generation context.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

/**
 * Deterministic world information structures may use to decide placement
 * without reading terrain (which may not be generated yet): the seed, the
 * biome at a position and an estimate of the surface height.
 */
final class StructureContext{
	/**
	 * @phpstan-param \Closure(int, int, int) : int $biomeAt
	 * @phpstan-param \Closure(int, int) : int $heightAt
	 */
	public function __construct(
		public readonly int $seed,
		private \Closure $biomeAt,
		private \Closure $heightAt
	){}

	public function biomeAt(int $x, int $y, int $z) : int{
		return ($this->biomeAt)($x, $y, $z);
	}

	public function heightAt(int $x, int $z) : int{
		return ($this->heightAt)($x, $z);
	}
}
