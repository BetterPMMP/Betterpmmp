<?php

/*
 * BetterPMMP - the settings of one entity from custom_entities.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\custom;

final class CustomEntityType{

	/**
	 * @param list<array{string, int, int}> $drops item name, min count, max count
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly string $name,
		public readonly bool $hostile,
		public readonly int $health,
		public readonly float $speed,
		public readonly float $damage,
		public readonly float $followRange,
		public readonly float $width,
		public readonly float $height,
		public readonly int $experience,
		public readonly array $drops,
		public readonly bool $fireproof
	){}
}
