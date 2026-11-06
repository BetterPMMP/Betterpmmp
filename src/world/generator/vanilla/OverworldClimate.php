<?php

/*
 * BetterPMMP - vanilla-style overworld climate and terrain shaping.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\utils\Random;
use pocketmine\world\generator\noise\Simplex;
use function abs;
use function max;
use function min;

/**
 * Samples the five vanilla climate parameters (temperature, humidity,
 * continentalness, erosion, weirdness) and derives the terrain shape from
 * them: a base surface height and a "squash" factor controlling how much 3D
 * noise (overhangs, cliffs) is applied around that height.
 */
final class OverworldClimate{

	public const SEA_LEVEL = 63; //water fills y <= 62, as in vanilla

	private Simplex $temperature;
	private Simplex $humidity;
	private Simplex $continentalness;
	private Simplex $erosion;
	private Simplex $weirdness;
	private Simplex $shift;
	private Simplex $detail;
	private Simplex $ridge;

	/** Output scale factors calibrated so each parameter covers roughly [-1, 1] like vanilla. */
	private const T_SCALE = 1.9;
	private const H_SCALE = 1.9;
	private const C_SCALE = 2.1;
	private const E_SCALE = 1.9;
	private const W_SCALE = 1.9;
	private const C_OFFSET = 0.12; //bias towards land

	public function __construct(int $seed){
		$this->temperature = new Simplex(new Random($seed ^ 0x1A2B3C), 4, 0.5, 1 / 2600);
		$this->humidity = new Simplex(new Random($seed ^ 0x4D5E6F), 4, 0.5, 1 / 2200);
		$this->continentalness = new Simplex(new Random($seed ^ 0x7A8B9C), 6, 0.5, 1 / 2400);
		$this->erosion = new Simplex(new Random($seed ^ 0x0D1E2F), 5, 0.5, 1 / 1100);
		$this->weirdness = new Simplex(new Random($seed ^ 0x3C4B5A), 4, 0.5, 1 / 700);
		$this->shift = new Simplex(new Random($seed ^ 0x6F7E8D), 3, 0.5, 1 / 200);
		$this->detail = new Simplex(new Random($seed ^ 0x9C0BAF), 4, 0.5, 1 / 110);
		$this->ridge = new Simplex(new Random($seed ^ 0x2468AC), 3, 0.45, 1 / 90);
	}

	private static function clamp(float $v) : float{
		return max(-1.2, min(1.2, $v));
	}

	/**
	 * @return float[] [temperature, humidity, continentalness, erosion, weirdness]
	 * @phpstan-return array{float, float, float, float, float}
	 */
	public function sample(float $x, float $z) : array{
		//domain warp ("shift" noise in vanilla) breaks up the regular noise look
		$sx = $x + $this->shift->noise2D($x, $z, true) * 48;
		$sz = $z + $this->shift->noise2D($z + 913, $x - 377, true) * 48;
		return [
			self::clamp($this->temperature->noise2D($sx, $sz, true) * self::T_SCALE),
			self::clamp($this->humidity->noise2D($sx, $sz, true) * self::H_SCALE),
			self::clamp($this->continentalness->noise2D($sx, $sz, true) * self::C_SCALE + self::C_OFFSET),
			self::clamp($this->erosion->noise2D($sx, $sz, true) * self::E_SCALE),
			self::clamp($this->weirdness->noise2D($sx, $sz, true) * self::W_SCALE),
		];
	}

	public static function smoothstep(float $edge0, float $edge1, float $x) : float{
		$t = ($x - $edge0) / ($edge1 - $edge0);
		$t = $t < 0 ? 0.0 : ($t > 1 ? 1.0 : $t);
		return $t * $t * (3 - 2 * $t);
	}

	/** Piecewise-smooth spline through the continentalness height points. */
	private static function continentalHeight(float $c) : float{
		static $points = [
			[-1.2, 66.0], [-1.1, 64.0], [-1.05, 34.0], [-0.75, 30.0], [-0.455, 36.0], [-0.3, 44.0],
			[-0.19, 54.0], [-0.11, 63.5], [0.03, 66.0], [0.3, 72.0], [0.7, 80.0], [1.2, 86.0],
		];
		if($c <= $points[0][0]){
			return $points[0][1];
		}
		for($i = 1, $n = count($points); $i < $n; ++$i){
			if($c <= $points[$i][0]){
				[$x0, $y0] = $points[$i - 1];
				[$x1, $y1] = $points[$i];
				return $y0 + ($y1 - $y0) * self::smoothstep($x0, $x1, $c);
			}
		}
		return $points[$n - 1][1];
	}

	/**
	 * Terrain shape for a column.
	 * @return float[] [surface height, squash (vertical spread of the 3D noise)]
	 * @phpstan-return array{float, float}
	 */
	public function terrain(float $x, float $z, float $c, float $e, float $w) : array{
		$height = self::continentalHeight($c);
		$pv = OverworldBiomeBuilder::peaksAndValleys($w);

		$inland = self::smoothstep(-0.11, 0.3, $c);
		$rugged = self::smoothstep(0.45, -0.8, $e); //1 at low erosion
		$mountain = $inland * $rugged;

		//peaks and high slices lift the land, strongest at low erosion far inland
		$lift = self::smoothstep(-0.25, 1.0, $pv);
		$height += $mountain * $rugged * $lift * 150 + $mountain * 18 + $inland * $lift * 10;

		//jagged ridges on the highest peaks
		if($mountain > 0.3 && $pv > 0.3){
			$ridge = 1 - abs($this->ridge->noise2D($x, $z, true));
			$height += ($mountain - 0.3) * self::smoothstep(0.3, 1.0, $pv) * $ridge * $ridge * 38;
		}

		//hills and small bumps
		$detail = $this->detail->noise2D($x, $z, true);
		$height += $detail * (2.5 + 9 * $inland * (1 - self::smoothstep(0.45, 0.6, $e)) * (0.4 + $rugged));

		//valleys carve rivers, except through the highest mountains
		if($c > -0.19){
			$river = self::smoothstep(-0.55, -0.9, $pv) * (1 - $mountain * 0.85);
			if($river > 0){
				$bed = 57 + max(0, $detail) * 2;
				$height += (min($height, $bed) - $height) * $river;
			}
			//swamps: very high erosion inland flattens to sea level
			$swamp = self::smoothstep(0.5, 0.62, $e) * self::smoothstep(-0.11, 0.0, $c);
			if($swamp > 0){
				$height += (62.6 + $detail * 1.2 - $height) * $swamp;
			}
		}

		//how far around the surface the 3D noise reaches: small on plains,
		//larger in mountains (cliffs, occasional overhangs)
		$squash = 3.0 + 7 * $mountain * (0.4 + 0.6 * $lift) + 2.5 * $inland;
		return [$height, $squash];
	}
}
