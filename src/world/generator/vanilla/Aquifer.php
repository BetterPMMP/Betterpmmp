<?php

/*
 * BetterPMMP - underground water and lava levels (aquifers), modelled on vanilla 1.18+.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\utils\Random;
use pocketmine\world\generator\noise\Simplex;
use function abs;
use function array_key_exists;
use function count;
use function floor;
use function intdiv;
use function round;
use function sqrt;
use const PHP_INT_MAX;

/**
 * Decides what fills an open cave block below the surface: air, water or lava.
 *
 * Like vanilla, the underground is split into cells of 16 x 12 x 16 blocks. Every cell has one
 * randomly placed point with its own fluid level: dry (air), a local water level, or lava deep
 * down. A cave block takes the level of the nearest point, so caves are partly flooded with flat
 * water surfaces at different heights. Where the two nearest points have different levels, a thin
 * wall of stone (a "barrier") separates them, so the water does not pour into the dry cave next to it.
 */
final class Aquifer{

	public const AIR = 0;
	public const WATER = 1;
	public const LAVA = 2;
	public const BARRIER = 3;

	private const CELL_XZ = 16;
	private const CELL_Y = 12;
	private const DRY = -10000;
	/** deeper than this a flooded cell may hold lava instead of water (vanilla: -10) */
	private const LAVA_BELOW = -10;
	/** the walls between different levels are about this thick */
	private const BARRIER_WIDTH = 1.6;

	private Simplex $floodedness;
	private Simplex $spread;
	private Simplex $lavaNoise;

	/** @var array<int, array{int, int, int, int, bool}> cell => [x, y, z, level, lava] */
	private array $cells = [];

	public function __construct(private int $seed){
		$r = new Random($seed ^ 0x2A9F1C3B);
		$this->floodedness = new Simplex($r, 2, 0.5, 1 / 96);
		$this->spread = new Simplex($r, 1, 0.5, 1 / 48);
		$this->lavaNoise = new Simplex($r, 1, 0.5, 1 / 64);
	}

	/** Forget cached cells (call once per chunk to keep memory small). */
	public function reset() : void{
		if(count($this->cells) > 4096){
			$this->cells = [];
			$this->windows = [];
		}
	}

	/** @return array{int, int, int, int, bool} point x, y, z, fluid level (fluid below it), lava */
	private function cell(int $cx, int $cy, int $cz) : array{
		$key = (($cx & 0xffff) << 32) | (($cy & 0xff) << 16) | ($cz & 0xffff);
		if(isset($this->cells[$key])){
			return $this->cells[$key];
		}
		$h = self::hash($cx, $cy, $cz, $this->seed);
		$px = $cx * self::CELL_XZ + ($h & 0xff) % 10 + 3;
		$py = $cy * self::CELL_Y + (($h >> 8) & 0xff) % 9 + 1;
		$pz = $cz * self::CELL_XZ + (($h >> 16) & 0xff) % 10 + 3;

		$level = self::DRY;
		$lava = false;
		//deeper cells are flooded more often, like vanilla
		$flood = $this->floodedness->noise3D($px, $py * 0.67, $pz, true) + ($py < 0 ? 0.1 : 0.0);
		if($flood > 0.22){
			//a local level near the cell, in steps of 3 blocks like vanilla
			$offset = (int) round($this->spread->noise3D($px, $py, $pz, true) * 3) * 3;
			$level = $cy * self::CELL_Y + 6 + $offset;
			if($level < self::LAVA_BELOW && abs($this->lavaNoise->noise3D($px, $py, $pz, true)) > 0.25){
				$lava = true;
			}
		}
		return $this->cells[$key] = [$px, $py, $pz, $level, $lava];
	}

	/** @var array<int, list<array{int, int, int, int, bool}>|null> */
	private array $windows = [];

	/**
	 * The 27 cells around a cell, or null if none of them holds water or lava.
	 * @return list<array{int, int, int, int, bool}>|null
	 */
	private function window(int $gx, int $gy, int $gz) : ?array{
		$key = (($gx & 0xffff) << 32) | (($gy & 0xff) << 16) | ($gz & 0xffff);
		if(array_key_exists($key, $this->windows)){
			return $this->windows[$key];
		}
		$cells = [];
		$wet = false;
		for($ox = -1; $ox <= 1; ++$ox){
			for($oy = -1; $oy <= 1; ++$oy){
				for($oz = -1; $oz <= 1; ++$oz){
					$cell = $this->cell($gx + $ox, $gy + $oy, $gz + $oz);
					$cells[] = $cell;
					$wet = $wet || $cell[3] !== self::DRY;
				}
			}
		}
		return $this->windows[$key] = $wet ? $cells : null;
	}

	/** What fills the open block at x, y, z (one of the constants). */
	public function at(int $x, int $y, int $z) : int{
		$gx = $x >> 4;
		$gy = intdiv($y - ($y < 0 ? self::CELL_Y - 1 : 0), self::CELL_Y);
		$gz = $z >> 4;
		$window = $this->window($gx, $gy, $gz);
		if($window === null){
			return self::AIR; //no water or lava anywhere near: a dry cave
		}
		$candidates = [];
		$best = PHP_INT_MAX;
		$a = null;
		foreach($window as $cell){
			$dx = $cell[0] - $x;
			$dy = $cell[1] - $y;
			$dz = $cell[2] - $z;
			$d = $dx * $dx + $dy * $dy + $dz * $dz;
			$candidates[] = [$d, $cell];
			if($d < $best){
				$best = $d;
				$a = $cell;
			}
		}
		if($a === null){
			return self::AIR;
		}
		//between different levels: a wall near the border keeps them apart (also when the other level is only the
		//third or fourth nearest, otherwise water would touch a dry cave there)
		$limit = sqrt($best) + self::BARRIER_WIDTH;
		$limitSquared = $limit * $limit;
		foreach($candidates as [$d, $cell]){
			if($d <= $limitSquared && $cell !== $a && ($cell[3] !== $a[3] || $cell[4] !== $a[4]) && ($y < $a[3] || $y < $cell[3])){
				return self::BARRIER;
			}
		}
		if($y >= $a[3]){
			return self::AIR;
		}
		return $a[4] ? self::LAVA : self::WATER;
	}

	private static function hash(int $x, int $y, int $z, int $seed) : int{
		$h = ($x * 0x2F0B3A5 + $y * 0x6EB17 + $z * 0x1B873593 + $seed) & 0x7fffffff;
		$h = (($h ^ ($h >> 13)) * 0x5BD1E995) & 0x7fffffff;
		return ($h ^ ($h >> 15)) & 0x7fffffff;
	}
}
