<?php

/*
 * BetterPMMP - vanilla-style overworld biome placement.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\data\bedrock\BiomeIds as B;

/**
 * Picks an overworld biome from the five climate parameters, following the
 * structure of vanilla's multi-noise overworld biome table (1.18+):
 * temperature / humidity bands, continentalness zones, erosion levels and
 * weirdness slices (valleys, low, mid, high, peaks) with weirdness variants.
 */
final class OverworldBiomeBuilder{

	private const N = -1; //"no variant"

	/** [temperature][humidity] */
	private const MIDDLE = [
		[B::ICE_PLAINS, B::ICE_PLAINS, B::ICE_PLAINS, B::COLD_TAIGA, B::TAIGA],
		[B::PLAINS, B::PLAINS, B::FOREST, B::TAIGA, B::REDWOOD_TAIGA_MUTATED],
		[B::FLOWER_FOREST, B::PLAINS, B::FOREST, B::BIRCH_FOREST, B::ROOFED_FOREST],
		[B::SAVANNA, B::SAVANNA, B::FOREST, B::JUNGLE, B::JUNGLE],
		[B::DESERT, B::DESERT, B::DESERT, B::DESERT, B::DESERT],
	];
	private const MIDDLE_VARIANT = [
		[B::ICE_PLAINS_SPIKES, self::N, B::COLD_TAIGA, self::N, self::N],
		[self::N, self::N, self::N, self::N, B::MEGA_TAIGA],
		[B::SUNFLOWER_PLAINS, self::N, self::N, B::BIRCH_FOREST_MUTATED, self::N],
		[self::N, self::N, B::PLAINS, B::JUNGLE_EDGE, B::BAMBOO_JUNGLE],
		[self::N, self::N, self::N, self::N, self::N],
	];
	private const PLATEAU = [
		[B::ICE_PLAINS, B::ICE_PLAINS, B::ICE_PLAINS, B::COLD_TAIGA, B::COLD_TAIGA],
		[B::MEADOW, B::MEADOW, B::FOREST, B::TAIGA, B::REDWOOD_TAIGA_MUTATED],
		[B::MEADOW, B::MEADOW, B::MEADOW, B::MEADOW, B::ROOFED_FOREST],
		[B::SAVANNA_PLATEAU, B::SAVANNA_PLATEAU, B::FOREST, B::FOREST, B::JUNGLE],
		[B::MESA, B::MESA, B::MESA, B::MESA_PLATEAU_STONE, B::MESA_PLATEAU_STONE],
	];
	private const PLATEAU_VARIANT = [
		[B::ICE_PLAINS_SPIKES, self::N, self::N, self::N, self::N],
		[B::CHERRY_GROVE, self::N, B::MEADOW, B::MEADOW, B::MEGA_TAIGA],
		[B::CHERRY_GROVE, B::CHERRY_GROVE, B::FOREST, B::BIRCH_FOREST, B::PALE_GARDEN], // 1.21.4: pale garden replaces dark forest in variant plateaus
		[self::N, self::N, self::N, self::N, self::N],
		[B::MESA_BRYCE, B::MESA_BRYCE, self::N, self::N, self::N],
	];
	private const SHATTERED = [
		[B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS, B::EXTREME_HILLS_PLUS_TREES, B::EXTREME_HILLS_PLUS_TREES],
		[B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS, B::EXTREME_HILLS_PLUS_TREES, B::EXTREME_HILLS_PLUS_TREES],
		[B::EXTREME_HILLS, B::EXTREME_HILLS, B::EXTREME_HILLS, B::EXTREME_HILLS_PLUS_TREES, B::EXTREME_HILLS_PLUS_TREES],
		[self::N, self::N, self::N, self::N, self::N],
		[self::N, self::N, self::N, self::N, self::N],
	];
	private const OCEANS = [
		[B::DEEP_FROZEN_OCEAN, B::DEEP_COLD_OCEAN, B::DEEP_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::WARM_OCEAN],
		[B::FROZEN_OCEAN, B::COLD_OCEAN, B::OCEAN, B::LUKEWARM_OCEAN, B::WARM_OCEAN],
	];

	//continentalness zones
	public const C_MUSHROOM = 0;
	public const C_DEEP_OCEAN = 1;
	public const C_OCEAN = 2;
	public const C_COAST = 3;
	public const C_NEAR = 4;
	public const C_MID = 5;
	public const C_FAR = 6;

	public static function temperatureIndex(float $t) : int{
		return $t < -0.45 ? 0 : ($t < -0.15 ? 1 : ($t < 0.2 ? 2 : ($t < 0.55 ? 3 : 4)));
	}

	public static function humidityIndex(float $h) : int{
		return $h < -0.35 ? 0 : ($h < -0.1 ? 1 : ($h < 0.1 ? 2 : ($h < 0.3 ? 3 : 4)));
	}

	public static function erosionIndex(float $e) : int{
		return $e < -0.78 ? 0 : ($e < -0.375 ? 1 : ($e < -0.2225 ? 2 : ($e < 0.05 ? 3 : ($e < 0.45 ? 4 : ($e < 0.55 ? 5 : 6)))));
	}

	public static function continentalnessZone(float $c) : int{
		return $c < -1.05 ? self::C_MUSHROOM : ($c < -0.455 ? self::C_DEEP_OCEAN : ($c < -0.19 ? self::C_OCEAN : ($c < -0.11 ? self::C_COAST : ($c < 0.03 ? self::C_NEAR : ($c < 0.3 ? self::C_MID : self::C_FAR)))));
	}

	/** Peaks-and-valleys value derived from weirdness, as in vanilla. */
	public static function peaksAndValleys(float $w) : float{
		return 1.0 - abs(3.0 * abs($w) - 2.0);
	}

	public static function pick(float $temperature, float $humidity, float $continentalness, float $erosion, float $weirdness) : int{
		$t = self::temperatureIndex($temperature);
		$h = self::humidityIndex($humidity);
		$c = self::continentalnessZone($continentalness);
		$e = self::erosionIndex($erosion);
		$w = $weirdness;

		if($c === self::C_MUSHROOM){
			return B::MUSHROOM_ISLAND;
		}
		if($c === self::C_DEEP_OCEAN){
			return self::OCEANS[0][$t];
		}
		if($c === self::C_OCEAN){
			return self::OCEANS[1][$t];
		}

		$aw = abs($w);
		if($aw < 0.05){
			return self::valley($t, $h, $c, $e, $w);
		}
		if($aw < 0.26667){
			return self::lowSlice($t, $h, $c, $e, $w);
		}
		if($aw < 0.4 || $aw >= 0.93333){
			return self::midSlice($t, $h, $c, $e, $w);
		}
		if($aw < 0.56667 || $aw >= 0.76667){
			return self::highSlice($t, $h, $c, $e, $w);
		}
		return self::peaks($t, $h, $c, $e, $w);
	}

	private static function peaks(int $t, int $h, int $c, int $e, float $w) : int{
		$inland = $c >= self::C_MID;
		return match($e){
			0 => self::peak($t, $h, $w),
			1 => $inland ? self::peak($t, $h, $w) : self::middleOrBadlandsOrSlope($t, $h, $w),
			2 => $inland ? self::plateau($t, $h, $w) : self::middle($t, $h, $w),
			3 => $c === self::C_MID ? self::middleOrBadlands($t, $h, $w) : ($c === self::C_FAR ? self::plateau($t, $h, $w) : self::middle($t, $h, $w)),
			5 => $inland ? self::shattered($t, $h, $w) : self::windsweptSavanna($t, $h, $w, self::shattered($t, $h, $w)),
			default => self::middle($t, $h, $w),
		};
	}

	private static function highSlice(int $t, int $h, int $c, int $e, float $w) : int{
		$inland = $c >= self::C_MID;
		return match($e){
			0 => $c === self::C_COAST ? self::middle($t, $h, $w) : ($c === self::C_NEAR ? self::slope($t, $h, $w) : self::peak($t, $h, $w)),
			1 => $c === self::C_COAST ? self::middle($t, $h, $w) : ($c === self::C_NEAR ? self::middleOrBadlandsOrSlope($t, $h, $w) : self::slope($t, $h, $w)),
			2 => $inland ? self::plateau($t, $h, $w) : self::middle($t, $h, $w),
			3 => $c === self::C_MID ? self::middleOrBadlands($t, $h, $w) : ($c === self::C_FAR ? self::plateau($t, $h, $w) : self::middle($t, $h, $w)),
			5 => $inland ? self::shattered($t, $h, $w) : self::windsweptSavanna($t, $h, $w, self::shattered($t, $h, $w)),
			default => self::middle($t, $h, $w),
		};
	}

	private static function midSlice(int $t, int $h, int $c, int $e, float $w) : int{
		if($c === self::C_COAST && $e <= 2){
			return B::STONE_BEACH;
		}
		if($e === 6 && $c !== self::C_COAST){
			return $t === 0 ? self::middle($t, $h, $w) : ($t >= 3 ? B::MANGROVE_SWAMP : B::SWAMPLAND);
		}
		return match($e){
			0 => self::slope($t, $h, $w),
			1 => $c === self::C_FAR ? ($t === 0 ? self::slope($t, $h, $w) : self::plateau($t, $h, $w)) : self::middleOrBadlandsOrSlope($t, $h, $w),
			2 => $c === self::C_NEAR ? self::middle($t, $h, $w) : ($c === self::C_MID ? self::middleOrBadlands($t, $h, $w) : self::plateau($t, $h, $w)),
			3 => $c <= self::C_NEAR ? self::middle($t, $h, $w) : self::middleOrBadlands($t, $h, $w),
			4 => $c === self::C_COAST && $w < 0 ? self::beach($t) : self::middle($t, $h, $w),
			5 => $c === self::C_COAST ? self::shatteredCoast($t, $h, $w) : ($c === self::C_NEAR ? self::windsweptSavanna($t, $h, $w, self::shattered($t, $h, $w)) : self::shattered($t, $h, $w)),
			default => $w < 0 ? self::beach($t) : self::middle($t, $h, $w), //coast, erosion 6
		};
	}

	private static function lowSlice(int $t, int $h, int $c, int $e, float $w) : int{
		if($c === self::C_COAST && $e <= 2){
			return B::STONE_BEACH;
		}
		if($e === 6 && $c !== self::C_COAST){
			return $t === 0 ? self::middle($t, $h, $w) : ($t >= 3 ? B::MANGROVE_SWAMP : B::SWAMPLAND);
		}
		return match($e){
			0, 1 => $c === self::C_NEAR ? self::middleOrBadlands($t, $h, $w) : self::middleOrBadlandsOrSlope($t, $h, $w),
			2, 3 => $c === self::C_COAST ? self::beach($t) : ($c === self::C_NEAR ? self::middle($t, $h, $w) : self::middleOrBadlands($t, $h, $w)),
			4 => $c === self::C_COAST ? self::beach($t) : self::middle($t, $h, $w),
			5 => $c === self::C_COAST ? self::shatteredCoast($t, $h, $w) : ($c === self::C_NEAR ? self::windsweptSavanna($t, $h, $w, self::middle($t, $h, $w)) : self::shattered($t, $h, $w)),
			default => self::beach($t), //coast, erosion 6
		};
	}

	private static function valley(int $t, int $h, int $c, int $e, float $w) : int{
		$river = $t === 0 ? B::FROZEN_RIVER : B::RIVER;
		if($e <= 1){
			if($c === self::C_COAST){
				return $w < 0 ? B::STONE_BEACH : $river;
			}
			return $c === self::C_NEAR ? $river : self::middleOrBadlands($t, $h, $w);
		}
		if($e === 6 && $c !== self::C_COAST){
			return $t === 0 ? B::FROZEN_RIVER : ($t >= 3 ? B::MANGROVE_SWAMP : B::SWAMPLAND);
		}
		return $river;
	}

	private static function variantOr(array $variant, array $base, int $t, int $h, float $w) : int{
		if($w >= 0 && $variant[$t][$h] !== self::N){
			return $variant[$t][$h];
		}
		return $base[$t][$h];
	}

	private static function middle(int $t, int $h, float $w) : int{
		return self::variantOr(self::MIDDLE_VARIANT, self::MIDDLE, $t, $h, $w);
	}

	private static function middleOrBadlands(int $t, int $h, float $w) : int{
		return $t === 4 ? self::badlands($h, $w) : self::middle($t, $h, $w);
	}

	private static function middleOrBadlandsOrSlope(int $t, int $h, float $w) : int{
		return $t === 0 ? self::slope($t, $h, $w) : self::middleOrBadlands($t, $h, $w);
	}

	private static function plateau(int $t, int $h, float $w) : int{
		return self::variantOr(self::PLATEAU_VARIANT, self::PLATEAU, $t, $h, $w);
	}

	private static function shattered(int $t, int $h, float $w) : int{
		$b = self::SHATTERED[$t][$h];
		return $b === self::N ? self::middle($t, $h, $w) : $b;
	}

	private static function shatteredCoast(int $t, int $h, float $w) : int{
		return self::windsweptSavanna($t, $h, $w, $w >= 0 ? self::middle($t, $h, $w) : self::beach($t));
	}

	private static function windsweptSavanna(int $t, int $h, float $w, int $fallback) : int{
		return $t > 1 && $h < 4 && $w >= 0 ? B::SAVANNA_MUTATED : $fallback;
	}

	private static function badlands(int $h, float $w) : int{
		if($h < 2){
			return $w < 0 ? B::MESA : B::MESA_BRYCE;
		}
		return $h < 3 ? B::MESA : B::MESA_PLATEAU_STONE;
	}

	private static function peak(int $t, int $h, float $w) : int{
		if($t <= 2){
			return $w < 0 ? B::JAGGED_PEAKS : B::FROZEN_PEAKS;
		}
		return $t === 3 ? B::STONY_PEAKS : self::badlands($h, $w);
	}

	private static function slope(int $t, int $h, float $w) : int{
		if($t >= 3){
			return self::plateau($t, $h, $w);
		}
		return $h <= 1 ? B::SNOWY_SLOPES : B::GROVE;
	}

	private static function beach(int $t) : int{
		return $t === 0 ? B::COLD_BEACH : ($t === 4 ? B::DESERT : B::BEACH);
	}
}
