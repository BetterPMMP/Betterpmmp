<?php

/*
 * BetterPMMP - vanilla-style village generation.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\utils\BellAttachmentType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use function abs;
use function array_sum;
use function count;
use function dirname;
use function floor;
use function intdiv;
use function max;
use function min;

/**
 * Places villages on a region grid (like vanilla's structure spacing).
 * A village is a central well with a bell, four roads, houses from the
 * bundled templates (rotated to face the road), farms and lamp posts.
 * The whole village fits the 3x3 chunk area available during population of
 * its centre chunk; neighbouring chunks skip trees inside the village.
 */
final class VillageGenerator{

	private const REGION_CHUNKS = 24;
	private const SPREAD_CHUNKS = 16;
	public const RADIUS = 23;

	/** @var array<string, ?Village> */
	private array $regionCache = [];

	public function __construct(
		private int $seed,
		private OverworldClimate $climate
	){}

	public static function biomeGroup(int $biome) : ?string{
		return match($biome){
			B::PLAINS, B::SUNFLOWER_PLAINS, B::MEADOW => "plains",
			B::DESERT => "desert",
			B::SAVANNA, B::SAVANNA_PLATEAU => "savanna",
			B::TAIGA, B::MEGA_TAIGA, B::REDWOOD_TAIGA_MUTATED => "taiga",
			B::ICE_PLAINS, B::COLD_TAIGA => "snowy",
			default => null
		};
	}

	private function regionVillage(int $regionX, int $regionZ) : ?Village{
		$key = $regionX . ":" . $regionZ;
		if(array_key_exists($key, $this->regionCache)){
			return $this->regionCache[$key];
		}
		$r = new Random($this->seed ^ ($regionX * 0x2F0B3A49) ^ ($regionZ * 0x6C1B0E8D) ^ 0x5E3779B1);
		$chunkX = $regionX * self::REGION_CHUNKS + $r->nextBoundedInt(self::SPREAD_CHUNKS);
		$chunkZ = $regionZ * self::REGION_CHUNKS + $r->nextBoundedInt(self::SPREAD_CHUNKS);
		$cx = ($chunkX << 4) + 8;
		$cz = ($chunkZ << 4) + 8;
		$village = null;
		[$t, $h, $c, $e, $w] = $this->climate->sample($cx, $cz);
		$group = self::biomeGroup(OverworldBiomeBuilder::pick($t, $h, $c, $e, $w));
		if($group !== null){
			$heights = [];
			foreach([[0, 0], [16, 0], [-16, 0], [0, 16], [0, -16], [12, 12], [-12, -12], [12, -12], [-12, 12]] as [$dx, $dz]){
				$s = $this->climate->sample($cx + $dx, $cz + $dz);
				$biome = OverworldBiomeBuilder::pick(...$s);
				if($biome === B::RIVER || $biome === B::FROZEN_RIVER || $biome === B::BEACH){
					$heights = [];
					break;
				}
				[$height] = $this->climate->terrain($cx + $dx, $cz + $dz, $s[2], $s[3], $s[4]);
				$heights[] = $height;
			}
			if(count($heights) > 0 && min($heights) > OverworldClimate::SEA_LEVEL + 1 && max($heights) < 120 && max($heights) - min($heights) < 14){
				$village = new Village($chunkX, $chunkZ, $cx, $cz, $group);
			}
		}
		return $this->regionCache[$key] = $village;
	}

	/** The village whose area overlaps the given chunk, if any. */
	public function villageNear(int $chunkX, int $chunkZ) : ?Village{
		$seen = [];
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				$rx = (int) floor(($chunkX + $dx) / self::REGION_CHUNKS);
				$rz = (int) floor(($chunkZ + $dz) / self::REGION_CHUNKS);
				if(isset($seen["$rx:$rz"])){
					continue;
				}
				$seen["$rx:$rz"] = true;
				$village = $this->regionVillage($rx, $rz);
				if($village !== null && abs($village->chunkX - $chunkX) <= 2 && abs($village->chunkZ - $chunkZ) <= 2){
					return $village;
				}
			}
		}
		return null;
	}

	/** Ground y of a column: top solid block that isn't leaves/plants. */
	private function groundY(BlockWriter $w, int $x, int $z) : ?int{
		$y = $w->highest($x, $z);
		if($y === null){
			return null;
		}
		for(; $y > Overworld::MIN_Y; --$y){
			$block = $w->getBlock($x, $y, $z);
			if($block->isSolid() && !$block instanceof \pocketmine\block\Leaves && !$block instanceof \pocketmine\block\Wood){
				return $y;
			}
			if($block instanceof \pocketmine\block\Water){
				return null;
			}
		}
		return null;
	}

	public function build(BlockWriter $w, Village $v, Random $r) : void{
		$centerY = $this->groundY($w, $v->centerX, $v->centerZ);
		if($centerY === null){
			return;
		}
		$desert = $v->group === "desert";
		$path = $desert ? VanillaBlocks::SMOOTH_SANDSTONE() : VanillaBlocks::GRASS_PATH();
		$fence = match($v->group){
			"taiga", "snowy" => VanillaBlocks::SPRUCE_FENCE(),
			"savanna" => VanillaBlocks::ACACIA_FENCE(),
			default => VanillaBlocks::OAK_FENCE()
		};

		//roads
		$roadLength = self::RADIUS - 1;
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			for($d = 3; $d <= $roadLength; ++$d){
				for($wd = -1; $wd <= 1; ++$wd){
					$x = $v->centerX + $ox * $d + ($oz !== 0 ? $wd : 0);
					$z = $v->centerZ + $oz * $d + ($ox !== 0 ? $wd : 0);
					$this->pave($w, $x, $z, $path);
				}
			}
		}

		$this->well($w, $v->centerX, $centerY, $v->centerZ, $desert);

		//houses and farms along the roads
		$variants = self::houseVariants($v->group);
		$dir = dirname(__DIR__, 4) . "/resources/structures/village/" . $v->group . "/houses/";
		$slot = 0;
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			foreach([7, 15] as $dist){
				foreach([true, false] as $leftSide){
					++$slot;
					if($r->nextBoundedInt(5) === 0){
						continue; //some empty plots, like vanilla
					}
					$side = Facing::rotateY($face, $leftSide);
					[$sx, , $sz] = Facing::OFFSET[$side];
					//plot anchor: 3 blocks off the road centre line, on $side
					$ax = $v->centerX + $ox * $dist + $sx * 3;
					$az = $v->centerZ + $oz * $dist + $sz * 3;
					if($r->nextBoundedInt(6) === 0){
						$this->farm($w, $ax, $az, $side, $face, $r, $v->group);
						continue;
					}
					$template = StructureTemplate::load($dir . $variants[$r->nextBoundedInt(count($variants))] . ".nbt");
					if($template !== null){
						$this->house($w, $template, $ax, $az, $side, $face, $path);
					}
				}
			}
		}

		//lamp posts at road ends
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			$x = $v->centerX + $ox * 11 + ($oz !== 0 ? 2 : 0);
			$z = $v->centerZ + $oz * 11 + ($ox !== 0 ? 2 : 0);
			$this->lamp($w, $x, $z, $fence);
		}
	}

	private function pave(BlockWriter $w, int $x, int $z, Block $path) : void{
		$y = $this->groundY($w, $x, $z);
		if($y === null){
			$top = $w->highest($x, $z);
			if($top !== null && $w->getBlock($x, $top, $z) instanceof \pocketmine\block\Water && $top >= OverworldClimate::SEA_LEVEL - 2){
				$w->setBlock($x, $top, $z, VanillaBlocks::OAK_PLANKS()); //small bridges over water
			}
			return;
		}
		$w->setBlock($x, $y, $z, $path);
		for($yy = 1; $yy <= 3; ++$yy){
			$above = $w->getBlock($x, $y + $yy, $z);
			if(!$above->isSolid() || $above instanceof \pocketmine\block\Leaves){
				$w->setBlock($x, $y + $yy, $z, VanillaBlocks::AIR());
			}
		}
	}

	private function well(BlockWriter $w, int $x, int $y, int $z, bool $desert) : void{
		$wall = $desert ? VanillaBlocks::SANDSTONE() : VanillaBlocks::COBBLESTONE();
		$slab = $desert ? VanillaBlocks::SANDSTONE_SLAB() : VanillaBlocks::COBBLESTONE_SLAB();
		$post = $desert ? VanillaBlocks::SANDSTONE_WALL() : VanillaBlocks::OAK_FENCE();
		$water = VanillaBlocks::WATER();
		$air = VanillaBlocks::AIR();
		for($dx = -2; $dx <= 3; ++$dx){
			for($dz = -2; $dz <= 3; ++$dz){
				$ring = $dx === -2 || $dx === 3 || $dz === -2 || $dz === 3;
				for($yy = -4; $yy <= 0; ++$yy){
					$w->setBlock($x + $dx, $y + $yy, $z + $dz, $wall);
				}
				for($yy = 1; $yy <= 5; ++$yy){
					$w->setBlock($x + $dx, $y + $yy, $z + $dz, $air);
				}
				if($ring){
					continue;
				}
				$edge = $dx === -1 || $dx === 2 || $dz === -1 || $dz === 2;
				if($edge){
					$w->setBlock($x + $dx, $y + 1, $z + $dz, $wall);
				}else{
					for($yy = -3; $yy <= 0; ++$yy){
						$w->setBlock($x + $dx, $y + $yy, $z + $dz, $water);
					}
				}
			}
		}
		foreach([[-1, -1], [-1, 2], [2, -1], [2, 2]] as [$dx, $dz]){
			$w->setBlock($x + $dx, $y + 2, $z + $dz, $post);
			$w->setBlock($x + $dx, $y + 3, $z + $dz, $post);
		}
		for($dx = -1; $dx <= 2; ++$dx){
			for($dz = -1; $dz <= 2; ++$dz){
				$w->setBlock($x + $dx, $y + 4, $z + $dz, $slab);
			}
		}
		//meeting point: bell and hay bales (the hay also marks the village for villager spawning)
		$w->setBlock($x + 3, $y + 1, $z + 3, VanillaBlocks::BELL()->setAttachmentType(BellAttachmentType::FLOOR));
		$w->setBlock($x - 2, $y + 1, $z + 3, VanillaBlocks::HAY_BALE());
		$w->setBlock($x - 2, $y + 1, $z + 2, VanillaBlocks::HAY_BALE());
	}

	private function lamp(BlockWriter $w, int $x, int $z, Block $fence) : void{
		$y = $this->groundY($w, $x, $z);
		if($y === null){
			return;
		}
		for($h = 1; $h <= 3; ++$h){
			$w->setBlock($x, $y + $h, $z, $fence);
		}
		$w->setBlock($x, $y + 4, $z, VanillaBlocks::WOOL());
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			$w->setBlock($x + $ox, $y + 4, $z + $oz, VanillaBlocks::TORCH()->setFacing($face));
		}
	}

	/**
	 * Footprint rectangle of a plot: starts at the anchor and extends away from
	 * the road on $side, centred along the road direction.
	 * @return int[] [minX, minZ]
	 */
	private static function plotOrigin(int $ax, int $az, int $side, int $roadDir, int $sizeX, int $sizeZ) : array{
		[$sx, , $sz] = Facing::OFFSET[$side];
		$minX = $sx > 0 ? $ax : ($sx < 0 ? $ax - $sizeX + 1 : $ax - intdiv($sizeX, 2));
		$minZ = $sz > 0 ? $az : ($sz < 0 ? $az - $sizeZ + 1 : $az - intdiv($sizeZ, 2));
		return [$minX, $minZ];
	}

	/** Raises/lowers terrain under a footprint and returns the build level. */
	private function prepareFootprint(BlockWriter $w, int $minX, int $minZ, int $sizeX, int $sizeZ) : ?int{
		$heights = [];
		for($x = $minX; $x < $minX + $sizeX; ++$x){
			for($z = $minZ; $z < $minZ + $sizeZ; ++$z){
				if(!$w->isAvailable($x, $z)){
					return null;
				}
				$y = $this->groundY($w, $x, $z);
				if($y === null){
					return null; //water
				}
				$heights[] = $y;
			}
		}
		if(max($heights) - min($heights) > 6){
			return null;
		}
		$level = (int) floor(array_sum($heights) / count($heights));
		$fill = VanillaBlocks::DIRT();
		$air = VanillaBlocks::AIR();
		for($x = $minX - 1; $x <= $minX + $sizeX; ++$x){
			for($z = $minZ - 1; $z <= $minZ + $sizeZ; ++$z){
				$y = $this->groundY($w, $x, $z);
				if($y === null){
					continue;
				}
				for($yy = $y + 1; $yy < $level; ++$yy){
					$w->setBlock($x, $yy, $z, $fill);
				}
				for($yy = $level + 1; $yy <= max($y, $level) + 8; ++$yy){
					$w->setBlock($x, $yy, $z, $air);
				}
			}
		}
		return $level;
	}

	private function house(BlockWriter $w, StructureTemplate $t, int $ax, int $az, int $side, int $roadDir, Block $path) : void{
		$turns = $t->turnsToFace(Facing::opposite($side));
		[$sizeX, $sizeZ] = $t->rotatedSize($turns);
		[$minX, $minZ] = self::plotOrigin($ax, $az, $side, $roadDir, $sizeX, $sizeZ);
		$level = $this->prepareFootprint($w, $minX, $minZ, $sizeX, $sizeZ);
		if($level === null){
			return;
		}
		//template layer 0 is the foundation/floor
		$t->place($w, $minX, $level, $minZ, $turns);
		//foundation pillars under the floor where the ground is lower
		$cobble = VanillaBlocks::COBBLESTONE();
		for($x = $minX; $x < $minX + $sizeX; ++$x){
			for($z = $minZ; $z < $minZ + $sizeZ; ++$z){
				for($yy = $level - 1; $yy > $level - 6 && !$w->getBlock($x, $yy, $z)->isSolid(); --$yy){
					$w->setBlock($x, $yy, $z, $cobble);
				}
			}
		}
		//path from the road to the door side
		[$sx, , $sz] = Facing::OFFSET[$side];
		for($d = -2; $d <= 0; ++$d){
			$this->pave($w, $ax + $sx * $d, $az + $sz * $d, $path);
		}
	}

	private function farm(BlockWriter $w, int $ax, int $az, int $side, int $roadDir, Random $r, string $group) : void{
		$sizeX = 7;
		$sizeZ = 7;
		[$minX, $minZ] = self::plotOrigin($ax, $az, $side, $roadDir, $sizeX, $sizeZ);
		$level = $this->prepareFootprint($w, $minX, $minZ, $sizeX, $sizeZ);
		if($level === null){
			return;
		}
		$border = $group === "taiga" || $group === "snowy" ? VanillaBlocks::SPRUCE_LOG() : ($group === "savanna" ? VanillaBlocks::ACACIA_LOG() : VanillaBlocks::OAK_LOG());
		$crops = [VanillaBlocks::WHEAT(), VanillaBlocks::CARROTS(), VanillaBlocks::POTATOES(), VanillaBlocks::BEETROOTS()];
		$crop = $crops[$r->nextBoundedInt(count($crops))];
		$waterAlongX = ($side === Facing::NORTH || $side === Facing::SOUTH);
		for($x = $minX; $x < $minX + $sizeX; ++$x){
			for($z = $minZ; $z < $minZ + $sizeZ; ++$z){
				$edge = $x === $minX || $x === $minX + $sizeX - 1 || $z === $minZ || $z === $minZ + $sizeZ - 1;
				$middle = $waterAlongX ? $z === $minZ + 3 : $x === $minX + 3;
				if($edge){
					$w->setBlock($x, $level, $z, $border);
				}elseif($middle){
					$w->setBlock($x, $level, $z, VanillaBlocks::WATER());
				}else{
					$w->setBlock($x, $level, $z, VanillaBlocks::FARMLAND()->setWetness(7));
					$w->setBlock($x, $level + 1, $z, (clone $crop)->setAge(min($crop::MAX_AGE, $r->nextBoundedInt($crop::MAX_AGE + 1))));
				}
			}
		}
	}

	/** @return string[] */
	private static function houseVariants(string $group) : array{
		return match($group){
			"desert" => ["desert_small_house_1", "desert_small_house_2", "desert_small_house_3"],
			"savanna" => ["savanna_small_house_1", "savanna_small_house_2", "savanna_small_house_3"],
			"taiga" => ["taiga_small_house_1", "taiga_small_house_2", "taiga_small_house_3", "taiga_small_house_4"],
			"snowy" => ["snowy_small_house_1", "snowy_small_house_2", "snowy_small_house_3"],
			default => ["plains_small_house_1", "plains_small_house_2", "plains_small_house_3"]
		};
	}
}
