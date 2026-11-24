<?php

/*
 * BetterPMMP - end city assembly (which template goes where).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Random;
use function max;
use function min;

/**
 * Puts an end city together like vanilla does: a base house, towers on top, bridges leaving the towers to
 * further houses, fat towers and at most one end ship. Every part is one of the vanilla templates, placed
 * relative to its parent; a branch that would run into an older part is dropped.
 *
 * A part is [template name, x, y, z, rotation (clockwise quarter turns), overwrite, generation id].
 * "overwrite" parts also place their air (they carve their room), the others only add blocks.
 *
 * @phpstan-type Part array{name: string, x: int, y: int, z: int, rot: int, overwrite: bool, gen: int, box: array{int, int, int, int, int, int}}
 */
final class EndCityLayout{
	private const MAX_DEPTH = 8;

	/** [rotation, x, y, z] where bridges leave a tower */
	private const TOWER_BRIDGES = [[0, 1, -1, 0], [1, 6, -1, 1], [3, 0, -1, 5], [2, 5, -1, 6]];
	private const FAT_TOWER_BRIDGES = [[0, 4, -1, 0], [1, 12, -1, 4], [3, 0, -1, 8], [2, 8, -1, 12]];

	private bool $shipCreated = false;

	/**
	 * @param array<string, array{int, int, int}> $sizes template name => size
	 */
	private function __construct(private array $sizes, private Random $random){}

	/**
	 * @param array<string, array{int, int, int}> $sizes
	 * @return list<array<string, mixed>>
	 * @phpstan-return list<Part>
	 */
	public static function generate(array $sizes, Random $random, int $x, int $y, int $z, int $rotation) : array{
		$layout = new self($sizes, $random);
		$parts = [];
		$part = $layout->add($parts, $layout->part("base_floor", $x, $y, $z, $rotation, true));
		$part = $layout->add($parts, $layout->attach($part, -1, 0, -1, "second_floor_1", $rotation, false));
		$part = $layout->add($parts, $layout->attach($part, -1, 4, -1, "third_floor_1", $rotation, false));
		$part = $layout->add($parts, $layout->attach($part, -1, 8, -1, "third_roof", $rotation, true));
		$layout->children("tower", 1, $part, null, $parts);
		return $parts;
	}

	/** @return int[] offset rotated clockwise by quarter turns */
	public static function rotate(int $x, int $z, int $rotation) : array{
		return match($rotation & 3){
			0 => [$x, $z],
			1 => [-$z, $x],
			2 => [-$x, -$z],
			default => [$z, -$x],
		};
	}

	/**
	 * @phpstan-return Part
	 */
	private function part(string $name, int $x, int $y, int $z, int $rotation, bool $overwrite) : array{
		[$sx, $sy, $sz] = $this->sizes[$name];
		[$ex, $ez] = self::rotate($sx - 1, $sz - 1, $rotation);
		return [
			"name" => $name, "x" => $x, "y" => $y, "z" => $z, "rot" => $rotation & 3, "overwrite" => $overwrite, "gen" => 0,
			"box" => [min($x, $x + $ex), $y, min($z, $z + $ez), max($x, $x + $ex), $y + $sy - 1, max($z, $z + $ez)]
		];
	}

	/**
	 * A part whose origin is the parent's origin moved by the offset (turned with the parent).
	 * @phpstan-param Part $parent
	 * @phpstan-return Part
	 */
	private function attach(array $parent, int $dx, int $dy, int $dz, string $name, int $rotation, bool $overwrite) : array{
		[$rx, $rz] = self::rotate($dx, $dz, $parent["rot"]);
		return $this->part($name, $parent["x"] + $rx, $parent["y"] + $dy, $parent["z"] + $rz, $rotation, $overwrite);
	}

	/**
	 * @phpstan-param list<Part> $list
	 * @phpstan-param Part $part
	 * @phpstan-return Part
	 */
	private function add(array &$list, array $part) : array{
		$list[] = $part;
		return $part;
	}

	/**
	 * Lets a generator build a branch and keeps it only if none of its parts runs into a part of another branch.
	 * @phpstan-param Part $parent
	 * @phpstan-param array{int, int, int}|null $offset
	 * @phpstan-param list<Part> $parts
	 */
	private function children(string $generator, int $depth, array $parent, ?array $offset, array &$parts) : bool{
		if($depth > self::MAX_DEPTH){
			return false;
		}
		$list = [];
		$ok = match($generator){
			"house" => $this->houseTower($depth, $parent, $offset ?? [0, 0, 0], $list),
			"tower" => $this->tower($depth, $parent, $list),
			"bridge" => $this->towerBridge($depth, $parent, $list),
			default => $this->fatTower($depth, $parent, $list),
		};
		if(!$ok){
			return false;
		}
		$gen = $this->random->nextInt();
		foreach($list as $k => $part){
			$list[$k]["gen"] = $gen;
			foreach($parts as $other){
				$a = $part["box"];
				$b = $other["box"];
				if($a[3] >= $b[0] && $a[0] <= $b[3] && $a[5] >= $b[2] && $a[2] <= $b[5] && $a[4] >= $b[1] && $a[1] <= $b[4]){
					//only the first part hit counts (like vanilla); touching the own parent's branch is fine
					if($other["gen"] !== $parent["gen"]){
						return false;
					}
					break;
				}
			}
		}
		foreach($list as $part){
			$parts[] = $part;
		}
		return true;
	}

	/**
	 * @phpstan-param Part $parent
	 * @phpstan-param array{int, int, int} $offset
	 * @phpstan-param list<Part> $list
	 */
	private function houseTower(int $depth, array $parent, array $offset, array &$list) : bool{
		if($depth > self::MAX_DEPTH){
			return false;
		}
		$rot = $parent["rot"];
		$part = $this->add($list, $this->attach($parent, $offset[0], $offset[1], $offset[2], "base_floor", $rot, true));
		$kind = $this->random->nextBoundedInt(3);
		if($kind === 0){
			$this->add($list, $this->attach($part, -1, 4, -1, "base_roof", $rot, true));
		}elseif($kind === 1){
			$part = $this->add($list, $this->attach($part, -1, 0, -1, "second_floor_2", $rot, false));
			$part = $this->add($list, $this->attach($part, -1, 8, -1, "second_roof", $rot, false));
			$this->children("tower", $depth + 1, $part, null, $list);
		}else{
			$part = $this->add($list, $this->attach($part, -1, 0, -1, "second_floor_2", $rot, false));
			$part = $this->add($list, $this->attach($part, -1, 4, -1, "third_floor_2", $rot, false));
			$part = $this->add($list, $this->attach($part, -1, 8, -1, "third_roof", $rot, true));
			$this->children("tower", $depth + 1, $part, null, $list);
		}
		return true;
	}

	/**
	 * @phpstan-param Part $parent
	 * @phpstan-param list<Part> $list
	 */
	private function tower(int $depth, array $parent, array &$list) : bool{
		$rot = $parent["rot"];
		$part = $this->add($list, $this->attach($parent, 3 + $this->random->nextBoundedInt(2), -3, 3 + $this->random->nextBoundedInt(2), "tower_base", $rot, true));
		$part = $this->add($list, $this->attach($part, 0, 7, 0, "tower_piece", $rot, true));
		$bridgeFloor = $this->random->nextBoundedInt(3) === 0 ? $part : null;
		$floors = 1 + $this->random->nextBoundedInt(3);
		for($i = 0; $i < $floors; ++$i){
			$part = $this->add($list, $this->attach($part, 0, 4, 0, "tower_piece", $rot, true));
			if($i < $floors - 1 && $this->random->nextBoolean()){
				$bridgeFloor = $part;
			}
		}
		if($bridgeFloor !== null){
			foreach(self::TOWER_BRIDGES as [$turn, $dx, $dy, $dz]){
				if($this->random->nextBoolean()){
					$end = $this->add($list, $this->attach($bridgeFloor, $dx, $dy, $dz, "bridge_end", $rot + $turn, true));
					$this->children("bridge", $depth + 1, $end, null, $list);
				}
			}
			$this->add($list, $this->attach($part, -1, 4, -1, "tower_top", $rot, true));
		}elseif($depth !== 7){
			return $this->children("fat", $depth + 1, $part, null, $list);
		}else{
			$this->add($list, $this->attach($part, -1, 4, -1, "tower_top", $rot, true));
		}
		return true;
	}

	/**
	 * @phpstan-param Part $parent
	 * @phpstan-param list<Part> $list
	 */
	private function towerBridge(int $depth, array $parent, array &$list) : bool{
		$rot = $parent["rot"];
		$length = $this->random->nextBoundedInt(4) + 1;
		$part = $this->add($list, $this->attach($parent, 0, 0, -4, "bridge_piece", $rot, true));
		$rise = 0;
		for($i = 0; $i < $length; ++$i){
			if($this->random->nextBoolean()){
				$part = $this->add($list, $this->attach($part, 0, $rise, -4, "bridge_piece", $rot, true));
				$rise = 0;
			}else{
				if($this->random->nextBoolean()){
					$part = $this->add($list, $this->attach($part, 0, $rise, -4, "bridge_steep_stairs", $rot, true));
				}else{
					$part = $this->add($list, $this->attach($part, 0, $rise, -8, "bridge_gentle_stairs", $rot, true));
				}
				$rise = 4;
			}
		}
		if(!$this->shipCreated && $this->random->nextBoundedInt(10 - $depth) === 0){
			$this->add($list, $this->attach($part, -8 + $this->random->nextBoundedInt(8), $rise, -70 + $this->random->nextBoundedInt(10), "ship", $rot, true));
			$this->shipCreated = true;
		}elseif(!$this->children("house", $depth + 1, $part, [-3, $rise + 1, -11], $list)){
			return false;
		}
		$this->add($list, $this->attach($part, 4, $rise, 0, "bridge_end", $rot + 2, true));
		return true;
	}

	/**
	 * @phpstan-param Part $parent
	 * @phpstan-param list<Part> $list
	 */
	private function fatTower(int $depth, array $parent, array &$list) : bool{
		$rot = $parent["rot"];
		$part = $this->add($list, $this->attach($parent, -3, 4, -3, "fat_tower_base", $rot, true));
		$part = $this->add($list, $this->attach($part, 0, 4, 0, "fat_tower_middle", $rot, true));
		for($i = 0; $i < 2 && $this->random->nextBoundedInt(3) !== 0; ++$i){
			$part = $this->add($list, $this->attach($part, 0, 8, 0, "fat_tower_middle", $rot, true));
			foreach(self::FAT_TOWER_BRIDGES as [$turn, $dx, $dy, $dz]){
				if($this->random->nextBoolean()){
					$end = $this->add($list, $this->attach($part, $dx, $dy, $dz, "bridge_end", $rot + $turn, true));
					$this->children("bridge", $depth + 1, $end, null, $list);
				}
			}
		}
		$this->add($list, $this->attach($part, -2, 8, -2, "fat_tower_top", $rot, true));
		return true;
	}
}
