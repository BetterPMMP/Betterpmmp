<?php

declare(strict_types=1);

namespace leondev\betterwe;

use function abs;
use function in_array;
use function max;
use function min;
use function str_contains;

final class Region{

	public static function bounds(array $a, array $b) : array{
		return [min($a[0], $b[0]), min($a[1], $b[1]), min($a[2], $b[2]), max($a[0], $b[0]), max($a[1], $b[1]), max($a[2], $b[2])];
	}

	public static function volume(array $bounds) : int{
		return ($bounds[3] - $bounds[0] + 1) * ($bounds[4] - $bounds[1] + 1) * ($bounds[5] - $bounds[2] + 1);
	}

	public static function validate(array $bounds, int $minY, int $maxY) : void{
		if($bounds[1] < $minY || $bounds[4] >= $maxY || max(abs($bounds[0]), abs($bounds[2]), abs($bounds[3]), abs($bounds[5])) > 29999983){
			throw new \DomainException("Auswahl außerhalb der Weltgrenzen.");
		}
	}

	public static function positions(array $bounds, string $shape = "box") : \Generator{
		$centerX = ($bounds[0] + $bounds[3]) / 2;
		$centerY = ($bounds[1] + $bounds[4]) / 2;
		$centerZ = ($bounds[2] + $bounds[5]) / 2;
		$radius = ($bounds[3] - $bounds[0]) / 2;
		$round = in_array($shape, ["sphere", "hsphere", "cyl", "hcyl"], true);
		for($x = $bounds[0]; $x <= $bounds[3]; ++$x){
			for($z = $bounds[2]; $z <= $bounds[5]; ++$z){
				for($y = $bounds[1]; $y <= $bounds[4]; ++$y){
					$edgeX = $x === $bounds[0] || $x === $bounds[3];
					$edgeY = $y === $bounds[1] || $y === $bounds[4];
					$edgeZ = $z === $bounds[2] || $z === $bounds[5];
					if($shape === "walls" && !$edgeX && !$edgeZ){
						continue;
					}
					if($shape === "outline" && !$edgeX && !$edgeY && !$edgeZ){
						continue;
					}
					if($round){
						$distance = ($x - $centerX) ** 2 + ($z - $centerZ) ** 2 + (str_contains($shape, "sphere") ? ($y - $centerY) ** 2 : 0);
						$hollow = $shape[0] === "h";
						if($distance > ($radius + 0.25) ** 2 || ($hollow && $distance < max(0, $radius - 0.8) ** 2)){
							continue;
						}
					}
					yield [$x, $y, $z, null];
				}
			}
		}
	}

	public static function rotate(array $position, int $turns) : array{
		[$x, $y, $z] = $position;
		for($i = 0; $i < $turns; ++$i){
			[$x, $z] = [-$z, $x];
		}
		return [$x, $y, $z];
	}
}
