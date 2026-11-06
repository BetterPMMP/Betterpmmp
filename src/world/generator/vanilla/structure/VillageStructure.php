<?php

/*
 * BetterPMMP - vanilla-style villages.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\utils\BellAttachmentType;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\SlabType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use pocketmine\world\generator\vanilla\StructureTemplate;
use function count;
use function dirname;

/**
 * Villages like vanilla's jigsaw villages: a meeting point (well or bell
 * plaza) with streets branching out, and along the streets houses of several
 * sizes and job site buildings (weaponsmith, toolsmith, armorer, butcher,
 * cartographer, fletcher, fisher, library, temple, mason, shepherd, tannery),
 * farms, animal pens and lamp posts. Every building faces the street and
 * uses the material palette of its biome (plains, desert, savanna, taiga,
 * snowy). Placement: spacing 34, separation 8.
 */
final class VillageStructure extends Structure{

	private const SPACING = 34;
	private const SEPARATION = 8;
	private const MAX_EXTENT = 64;

	public function getName() : string{ return "village"; }
	protected function getSpacing() : int{ return self::SPACING; }
	protected function getSeparation() : int{ return self::SEPARATION; }
	protected function getSalt() : int{ return 10387312; }
	public function getMaxRadiusChunks() : int{ return 5; }

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

	/** Cheap check used by pillager outposts to keep their distance. */
	public function hasVillageNear(StructureContext $ctx, int $chunkX, int $chunkZ, int $radiusChunks) : bool{
		foreach($this->regionsNear($chunkX, $chunkZ) as [$rx, $rz]){
			[$cx, $cz] = $this->candidateChunk($ctx->seed, $rx, $rz);
			if(abs($cx - $chunkX) <= $radiusChunks && abs($cz - $chunkZ) <= $radiusChunks
				&& self::biomeGroup($ctx->biomeAt(($cx << 4) + 8, 64, ($cz << 4) + 8)) !== null){
				return true;
			}
		}
		return false;
	}

	/**
	 * Material palette of a biome group.
	 * @return array<string, Block>
	 */
	private static function palette(string $group) : array{
		return match($group){
			"desert" => [
				"foundation" => VanillaBlocks::SMOOTH_SANDSTONE(), "wall" => VanillaBlocks::CUT_SANDSTONE(), "frame" => VanillaBlocks::SMOOTH_SANDSTONE(),
				"floor" => VanillaBlocks::SMOOTH_SANDSTONE(), "roof" => VanillaBlocks::SMOOTH_SANDSTONE_SLAB(), "roofStairs" => VanillaBlocks::SMOOTH_SANDSTONE_STAIRS(),
				"door" => VanillaBlocks::JUNGLE_DOOR(), "fence" => VanillaBlocks::SANDSTONE_WALL(), "path" => VanillaBlocks::SMOOTH_SANDSTONE(),
				"accent" => VanillaBlocks::STAINED_CLAY()->setColor(DyeColor::ORANGE), "planks" => VanillaBlocks::JUNGLE_PLANKS(), "flatRoof" => VanillaBlocks::SMOOTH_SANDSTONE(),
			],
			"savanna" => [
				"foundation" => VanillaBlocks::COBBLESTONE(), "wall" => VanillaBlocks::ACACIA_PLANKS(), "frame" => VanillaBlocks::ACACIA_LOG(),
				"floor" => VanillaBlocks::ACACIA_PLANKS(), "roof" => VanillaBlocks::ACACIA_SLAB(), "roofStairs" => VanillaBlocks::ACACIA_STAIRS(),
				"door" => VanillaBlocks::ACACIA_DOOR(), "fence" => VanillaBlocks::ACACIA_FENCE(), "path" => VanillaBlocks::GRASS_PATH(),
				"accent" => VanillaBlocks::STAINED_CLAY()->setColor(DyeColor::ORANGE), "planks" => VanillaBlocks::ACACIA_PLANKS(),
			],
			"taiga" => [
				"foundation" => VanillaBlocks::COBBLESTONE(), "wall" => VanillaBlocks::SPRUCE_PLANKS(), "frame" => VanillaBlocks::SPRUCE_LOG(),
				"floor" => VanillaBlocks::SPRUCE_PLANKS(), "roof" => VanillaBlocks::SPRUCE_SLAB(), "roofStairs" => VanillaBlocks::SPRUCE_STAIRS(),
				"door" => VanillaBlocks::SPRUCE_DOOR(), "fence" => VanillaBlocks::SPRUCE_FENCE(), "path" => VanillaBlocks::GRASS_PATH(),
				"accent" => VanillaBlocks::MOSSY_COBBLESTONE(), "planks" => VanillaBlocks::SPRUCE_PLANKS(),
			],
			"snowy" => [
				"foundation" => VanillaBlocks::STONE_BRICKS(), "wall" => VanillaBlocks::SPRUCE_PLANKS(), "frame" => VanillaBlocks::SPRUCE_LOG(),
				"floor" => VanillaBlocks::SPRUCE_PLANKS(), "roof" => VanillaBlocks::SNOW(), "roofStairs" => VanillaBlocks::SPRUCE_STAIRS(),
				"door" => VanillaBlocks::SPRUCE_DOOR(), "fence" => VanillaBlocks::SPRUCE_FENCE(), "path" => VanillaBlocks::GRASS_PATH(),
				"accent" => VanillaBlocks::PACKED_ICE(), "planks" => VanillaBlocks::SPRUCE_PLANKS(),
			],
			default => [
				"foundation" => VanillaBlocks::COBBLESTONE(), "wall" => VanillaBlocks::OAK_PLANKS(), "frame" => VanillaBlocks::OAK_LOG(),
				"floor" => VanillaBlocks::OAK_PLANKS(), "roof" => VanillaBlocks::OAK_SLAB(), "roofStairs" => VanillaBlocks::OAK_STAIRS(),
				"door" => VanillaBlocks::OAK_DOOR(), "fence" => VanillaBlocks::OAK_FENCE(), "path" => VanillaBlocks::GRASS_PATH(),
				"accent" => VanillaBlocks::COBBLESTONE(), "planks" => VanillaBlocks::OAK_PLANKS(),
			],
		};
	}

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$x = ($chunkX << 4) + 8;
		$z = ($chunkZ << 4) + 8;
		$group = self::biomeGroup($ctx->biomeAt($x, 64, $z));
		if($group === null){
			return null;
		}
		$y = $ctx->heightAt($x, $z);
		if($y <= 62 || $y > 140){
			return null;
		}
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, true);
		$palette = self::palette($group);
		$this->meetingPoint($start, $x, $y, $z, $group, $palette, $random);

		//streets: breadth-first growth from the meeting point
		$queue = [];
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			$queue[] = [$x + $ox * 5, $z + $oz * 5, $face, 0];
		}
		$plots = [];
		$guard = 0;
		while(count($queue) > 0 && $guard++ < 40){
			[$sx, $sz, $face, $depth] = array_shift($queue);
			$length = 10 + $random->nextBoundedInt(10);
			[$ox, , $oz] = Facing::OFFSET[$face];
			$ex = $sx + $ox * $length;
			$ez = $sz + $oz * $length;
			if(abs($ex - $x) > self::MAX_EXTENT || abs($ez - $z) > self::MAX_EXTENT){
				$length = max(4, $length - 8);
				$ex = $sx + $ox * $length;
				$ez = $sz + $oz * $length;
			}
			$street = $this->street($sx, $sz, $ex, $ez, $face, $palette, $random);
			if($start->collides($street, -2) && $depth > 0){
				continue;
			}
			$start->add($street);
			//plots along both sides
			for($along = 3; $along < $length - 2; $along += 9){
				foreach([true, false] as $right){
					$side = Facing::rotateY($face, $right);
					[$px, , $pz] = Facing::OFFSET[$side];
					$plots[] = [$sx + $ox * $along + $px * 3, $sz + $oz * $along + $pz * 3, $side];
				}
			}
			if($depth < 3){
				$roll = $random->nextBoundedInt(10);
				if($roll < 6){
					$queue[] = [$ex + $ox, $ez + $oz, $face, $depth + 1];
				}
				if($roll >= 3){
					$queue[] = [$ex, $ez, Facing::rotateY($face, $random->nextBoolean()), $depth + 1];
				}
			}
		}

		//buildings on the plots
		$jobs = ["weaponsmith", "toolsmith", "armorer", "butcher", "cartographer", "fletcher", "fisher", "library", "temple", "mason", "shepherd", "tannery"];
		$jobIndex = $random->nextBoundedInt(count($jobs));
		$templateDir = dirname(__DIR__, 5) . "/resources/structures/village/" . $group . "/houses/";
		foreach($plots as [$px, $pz, $side]){
			$roll = $random->nextBoundedInt(100);
			if($roll < 12){
				continue; //empty plot
			}
			if($roll < 30){
				$type = "farm";
			}elseif($roll < 38){
				$type = "pen";
			}elseif($roll < 66){
				$type = $jobs[$jobIndex++ % count($jobs)];
			}elseif($roll < 82){
				$type = "house_medium";
			}else{
				$type = "house_small";
			}
			$this->building($ctx, $start, $type, $px, $pz, $side, $group, $palette, $random, $templateDir);
		}
		return $start;
	}

	/** @param array<string, Block> $p */
	private function meetingPoint(StructureStart $start, int $x, int $y, int $z, string $group, array $p, Random $random) : void{
		$start->add(new StructurePiece($x - 4, $y - 5, $z - 4, $x + 4, $y + 6, $z + 4, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $group, $p) : void{
			$air = VanillaBlocks::AIR();
			for($dx = -4; $dx <= 4; ++$dx){
				for($dz = -4; $dz <= 4; ++$dz){
					$c->set($x + $dx, $y, $z + $dz, $p["path"]);
					$c->foundation($x + $dx, $y, $z + $dz, VanillaBlocks::DIRT(), 8);
					$c->fill($x + $dx, $y + 1, $z + $dz, $x + $dx, $y + 8, $z + $dz, $air);
				}
			}
			$wall = $group === "desert" ? VanillaBlocks::SANDSTONE() : VanillaBlocks::COBBLESTONE();
			//well: 4x4 ring around 2x2 water, posts and roof
			for($dx = -2; $dx <= 1; ++$dx){
				for($dz = -2; $dz <= 1; ++$dz){
					$ring = $dx === -2 || $dx === 1 || $dz === -2 || $dz === 1;
					if($ring){
						$c->set($x + $dx, $y + 1, $z + $dz, $wall);
					}else{
						$c->fill($x + $dx, $y - 3, $z + $dz, $x + $dx, $y, $z + $dz, VanillaBlocks::WATER());
					}
					$c->set($x + $dx, $y + 4, $z + $dz, $group === "desert" ? VanillaBlocks::SANDSTONE_SLAB() : VanillaBlocks::COBBLESTONE_SLAB());
				}
			}
			foreach([[-2, -2], [-2, 1], [1, -2], [1, 1]] as [$dx, $dz]){
				$c->set($x + $dx, $y + 2, $z + $dz, $p["fence"]);
				$c->set($x + $dx, $y + 3, $z + $dz, $p["fence"]);
			}
			$c->set($x + 3, $y + 1, $z + 3, VanillaBlocks::BELL()->setAttachmentType(BellAttachmentType::FLOOR));
			//hay bales mark the village for resident spawning
			$c->set($x - 4, $y + 1, $z + 3, VanillaBlocks::HAY_BALE());
			$c->set($x - 4, $y + 1, $z + 4, VanillaBlocks::HAY_BALE());
		}, $random->nextInt()));
	}

	/** @param array<string, Block> $p */
	private function street(int $sx, int $sz, int $ex, int $ez, int $face, array $p, Random $random) : StructurePiece{
		[$ox, , $oz] = Facing::OFFSET[$face];
		$minX = min($sx, $ex) - ($oz !== 0 ? 1 : 0);
		$maxX = max($sx, $ex) + ($oz !== 0 ? 1 : 0);
		$minZ = min($sz, $ez) - ($ox !== 0 ? 1 : 0);
		$maxZ = max($sz, $ez) + ($ox !== 0 ? 1 : 0);
		$lampAt = $random->nextBoundedInt(3) === 0 ? 6 : -1;
		return new StructurePiece($minX, 40, $minZ, $maxX, 160, $maxZ, function(StructureCanvas $c, Random $r) use ($minX, $minZ, $maxX, $maxZ, $p, $sx, $sz, $ox, $oz, $lampAt) : void{
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					if(!$c->inside($x, $z)){
						continue;
					}
					$y = self::ground($c, $x, $z);
					if($y === null){
						//bridge over water
						$top = $c->writer->highest($x, $z);
						if($top !== null){
							$c->set($x, $top, $z, $p["planks"]);
						}
						continue;
					}
					$c->set($x, $y, $z, $p["path"]);
					for($h = 1; $h <= 4; ++$h){
						$b = $c->getBlock($x, $y + $h, $z);
						if(!$b->isSolid() || $b instanceof \pocketmine\block\Leaves || $b instanceof \pocketmine\block\Wood){
							$c->set($x, $y + $h, $z, VanillaBlocks::AIR());
						}
					}
				}
			}
			if($lampAt > 0){
				$lx = $sx + $ox * $lampAt + ($oz !== 0 ? 2 : 0);
				$lz = $sz + $oz * $lampAt + ($ox !== 0 ? 2 : 0);
				if($c->inside($lx, $lz) && ($ly = self::ground($c, $lx, $lz)) !== null){
					$c->fill($lx, $ly + 1, $lz, $lx, $ly + 3, $lz, $p["fence"]);
					$c->set($lx, $ly + 4, $lz, VanillaBlocks::WOOL());
					foreach(Facing::HORIZONTAL as $f){
						[$fx, , $fz] = Facing::OFFSET[$f];
						$c->set($lx + $fx, $ly + 4, $lz + $fz, VanillaBlocks::TORCH()->setFacing($f));
					}
				}
			}
		}, $random->nextInt());
	}

	/** Top solid ground of a column (ignores trees and plants), null over water. */
	private static function ground(StructureCanvas $c, int $x, int $z) : ?int{
		$y = $c->writer->highest($x, $z);
		if($y === null){
			return null;
		}
		for(; $y > 0; --$y){
			$b = $c->getBlock($x, $y, $z);
			$id = $b->getTypeId();
			if($id === BlockTypeIds::WATER || $id === BlockTypeIds::LAVA){
				return null;
			}
			if($b->isSolid() && !$b instanceof \pocketmine\block\Leaves && !$b instanceof \pocketmine\block\Wood && !$b instanceof \pocketmine\block\RedMushroomBlock && !$b instanceof \pocketmine\block\MushroomStem){
				return $y;
			}
		}
		return null;
	}

	/**
	 * @param array<string, Block> $p
	 */
	private function building(StructureContext $ctx, StructureStart $start, string $type, int $ax, int $az, int $side, string $group, array $p, Random $random, string $templateDir) : void{
		[$w, $d] = match($type){
			"house_small" => [5, 6],
			"house_medium" => [7, 8],
			"farm" => [9, 7],
			"pen" => [7, 7],
			"library", "temple" => [7, 9],
			"weaponsmith" => [9, 7],
			default => [7, 7],
		};
		$template = null;
		if($type === "house_small"){
			$variants = glob($templateDir . "*.nbt") ?: [];
			if(count($variants) > 0){
				$template = StructureTemplate::load($variants[$random->nextBoundedInt(count($variants))]);
				if($template !== null){
					[$w, $d] = $template->rotatedSize(0);
					$w = max($template->sizeX, $template->sizeZ);
					$d = $w;
				}
			}
		}
		//the building's front faces the street: forward (local +z) points away from the street
		$rotation = match($side){ Facing::SOUTH => 0, Facing::WEST => 1, Facing::NORTH => 2, default => 3 };
		//origin so that the building is centred on the anchor along the street
		$half = intdiv($w, 2);
		[$minX, $minZ, $maxX, $maxZ] = self::footprint($ax, $az, $rotation, $w, $d, $half);
		$y = $ctx->heightAt(($minX + $maxX) >> 1, ($minZ + $maxZ) >> 1);
		$piece = new StructurePiece($minX - 1, $y - 8, $minZ - 1, $maxX + 1, $y + 14, $maxZ + 1, function(StructureCanvas $c, Random $r) use ($ax, $az, $y, $rotation, $type, $w, $d, $half, $p, $group, $template, $side) : void{
			$origin = self::origin($ax, $az, $rotation, $half);
			$t = new Transform($c, $origin[0], $y, $origin[1], $rotation);
			$this->drawBuilding($t, $r, $type, $w, $d, $p, $group, $template, $side);
		}, $random->nextInt());
		if($start->collides($piece, 0)){
			return;
		}
		$start->add($piece);
	}

	/** @return int[] local origin (corner) for a building centred on the anchor */
	private static function origin(int $ax, int $az, int $rotation, int $half) : array{
		//right vector of the rotation
		$forward = Facing::SOUTH;
		for($i = 0; $i < $rotation; ++$i){
			$forward = Facing::rotateY($forward, true);
		}
		[$rx, , $rz] = Facing::OFFSET[Facing::rotateY($forward, false)];
		return [$ax - $rx * $half, $az - $rz * $half];
	}

	/** @return int[] [minX, minZ, maxX, maxZ] */
	private static function footprint(int $ax, int $az, int $rotation, int $w, int $d, int $half) : array{
		[$ox, $oz] = self::origin($ax, $az, $rotation, $half);
		return Transform::bounds($ox, $oz, $rotation, $w, $d);
	}

	/**
	 * Draws a building in local coordinates: x across the front (0..w-1),
	 * z from the street (0) to the back (d-1), y = 0 is the floor level.
	 * @param array<string, Block> $p
	 */
	private function drawBuilding(Transform $t, Random $r, string $type, int $w, int $d, array $p, string $group, ?StructureTemplate $template, int $side) : void{
		$air = VanillaBlocks::AIR();
		//level the plot: foundation below, clear above
		for($lx = -1; $lx <= $w; ++$lx){
			for($lz = -1; $lz <= $d; ++$lz){
				$t->foundation($lx, 0, $lz, $p["foundation"], 10);
				$t->fill($lx, 1, $lz, $lx, 12, $lz, $air);
				if($lx >= 0 && $lx < $w && $lz >= 0 && $lz < $d){
					$t->set($lx, 0, $lz, $type === "farm" || $type === "pen" ? VanillaBlocks::GRASS() : $p["foundation"]);
				}else{
					$t->set($lx, 0, $lz, VanillaBlocks::GRASS());
				}
			}
		}
		if($template !== null){
			//bundled vanilla house template, entrance towards the street
			$turns = $template->turnsToFace(Facing::opposite($side));
			[$tx, $ty, $tz] = $t->pos(0, 0, 0);
			[$bx, , $bz] = $t->pos($w - 1, 0, $d - 1);
			$template->placeOnCanvas($t->c, min($tx, $bx), $ty, min($tz, $bz), $turns);
			return;
		}
		switch($type){
			case "farm":
				$this->farm($t, $r, $w, $d, $p);
				return;
			case "pen":
				$this->pen($t, $r, $w, $d, $p);
				return;
		}
		$height = $type === "temple" ? 9 : ($type === "library" || $type === "house_medium" ? 5 : 4);
		$this->shell($t, $r, $w, $d, $height, $p, $group);
		$this->interior($t, $r, $type, $w, $d, $height, $p);
	}

	/** @param array<string, Block> $p */
	private function shell(Transform $t, Random $r, int $w, int $d, int $height, array $p, string $group) : void{
		$air = VanillaBlocks::AIR();
		$glass = VanillaBlocks::GLASS_PANE();
		for($lx = 0; $lx < $w; ++$lx){
			for($lz = 0; $lz < $d; ++$lz){
				$t->set($lx, 0, $lz, $p["floor"]);
				$corner = ($lx === 0 || $lx === $w - 1) && ($lz === 0 || $lz === $d - 1);
				$edge = $lx === 0 || $lx === $w - 1 || $lz === 0 || $lz === $d - 1;
				for($ly = 1; $ly <= $height; ++$ly){
					if(!$edge){
						$t->set($lx, $ly, $lz, $air);
						continue;
					}
					if($corner){
						$t->set($lx, $ly, $lz, $p["frame"]);
						continue;
					}
					$window = $ly === 2 && (($lz === 0 || $lz === $d - 1) ? ($lx % 3 === 1 && $lx !== intdiv($w, 2)) : $lz % 3 === 2);
					$t->set($lx, $ly, $lz, $window ? $glass : ($ly === 1 && $group !== "desert" ? $p["accent"] : $p["wall"]));
				}
			}
		}
		//roof
		if(isset($p["flatRoof"])){
			$t->fill(0, $height + 1, 0, $w - 1, $height + 1, $d - 1, $p["flatRoof"]);
			for($lx = 0; $lx < $w; $lx += 2){
				$t->set($lx, $height + 2, 0, $p["roof"]);
				$t->set($lx, $height + 2, $d - 1, $p["roof"]);
			}
		}else{
			//gabled roof of stairs along the depth, slab ridge
			$stairs = $p["roofStairs"];
			for($i = 0; $i <= intdiv($w, 2); ++$i){
				$ly = $height + 1 + $i;
				for($lz = -1; $lz <= $d; ++$lz){
					if($i === intdiv($w, 2) && $w % 2 === 1){
						$t->set($i, $ly, $lz, $p["roof"] instanceof \pocketmine\block\Slab ? (clone $p["roof"])->setSlabType(SlabType::BOTTOM) : $p["roof"]);
						continue;
					}
					if($stairs instanceof \pocketmine\block\Stair){
						$t->set($i - 1, $ly, $lz, (clone $stairs)->setFacing($t->facing(Facing::EAST)));
						$t->set($w - $i, $ly, $lz, (clone $stairs)->setFacing($t->facing(Facing::WEST)));
					}
				}
				//gable walls
				for($lx = $i; $lx < $w - $i; ++$lx){
					$t->set($lx, $ly, 0, $p["wall"]);
					$t->set($lx, $ly, $d - 1, $p["wall"]);
				}
			}
		}
		//door in the middle of the front wall, step path to the street
		$mid = intdiv($w, 2);
		$door = clone $p["door"];
		if($door instanceof \pocketmine\block\Door){
			$door->setFacing($t->facing(Facing::SOUTH));
			$t->set($mid, 1, 0, $door);
			$t->set($mid, 2, 0, (clone $door)->setTop(true));
		}
		$t->set($mid, 0, -1, $p["path"]);
		$t->set($mid, 3, -1, VanillaBlocks::TORCH()->setFacing($t->facing(Facing::NORTH)));
	}

	/** @param array<string, Block> $p */
	private function interior(Transform $t, Random $r, string $type, int $w, int $d, int $height, array $p) : void{
		$back = $d - 2;
		$bed = function(int $lx) use ($t, $r, $back) : void{
			$color = DyeColor::cases()[$r->nextBoundedInt(16)];
			$t->set($lx, 1, $back - 1, VanillaBlocks::BED()->setFacing($t->facing(Facing::SOUTH))->setColor($color));
			$t->set($lx, 1, $back, VanillaBlocks::BED()->setFacing($t->facing(Facing::SOUTH))->setHead(true)->setColor($color));
		};
		$chest = fn(int $lx, int $lz, string $table) => $t->set($lx, 1, $lz, VanillaBlocks::CHEST()->setFacing($t->facing(Facing::SOUTH)), LootTables::chestNbt($table, $r));
		switch($type){
			case "house_small":
				$bed(1);
				$t->set($w - 2, 1, $back, VanillaBlocks::CRAFTING_TABLE());
				break;
			case "house_medium":
				$bed(1);
				$bed($w - 2);
				$chest(intdiv($w, 2), $back, "village_house");
				$t->set($w - 2, 1, 1, VanillaBlocks::FURNACE()->setFacing($t->facing(Facing::WEST)));
				break;
			case "weaponsmith":
				//open forge with lava behind iron bars
				$t->fill(1, 1, $back - 1, 2, 1, $back, VanillaBlocks::COBBLESTONE());
				$t->set(1, 2, $back, VanillaBlocks::LAVA());
				$t->set(2, 2, $back, VanillaBlocks::IRON_BARS());
				$t->set(1, 2, $back - 1, VanillaBlocks::IRON_BARS());
				$t->set($w - 2, 1, $back, VanillaBlocks::FURNACE()->setFacing($t->facing(Facing::NORTH)));
				$t->set($w - 3, 1, $back, VanillaBlocks::FURNACE()->setFacing($t->facing(Facing::NORTH)));
				$t->set($w - 2, 1, 2, VanillaBlocks::ANVIL());
				$chest($w - 2, 1, "village_weaponsmith");
				break;
			case "toolsmith":
				$t->set(1, 1, $back, VanillaBlocks::SMITHING_TABLE());
				$t->set(2, 1, $back, VanillaBlocks::FURNACE()->setFacing($t->facing(Facing::NORTH)));
				$chest($w - 2, $back, "village_toolsmith");
				break;
			case "armorer":
				$t->set(1, 1, $back, VanillaBlocks::BLAST_FURNACE()->setFacing($t->facing(Facing::NORTH)));
				$chest($w - 2, $back, "village_armorer");
				break;
			case "butcher":
				$t->set(1, 1, $back, VanillaBlocks::SMOKER()->setFacing($t->facing(Facing::NORTH)));
				$t->set(2, 1, $back, VanillaBlocks::SMOOTH_STONE_SLAB());
				$chest($w - 2, $back, "village_butcher");
				break;
			case "cartographer":
				$t->set(1, 1, $back, VanillaBlocks::CARTOGRAPHY_TABLE());
				$chest($w - 2, $back, "village_cartographer");
				$bed($w - 3);
				break;
			case "fletcher":
				$t->set(1, 1, $back, VanillaBlocks::FLETCHING_TABLE());
				$chest($w - 2, $back, "village_fletcher");
				break;
			case "fisher":
				$t->set(1, 1, $back, VanillaBlocks::BARREL()->setFacing(Facing::UP));
				$t->set(2, 1, $back, VanillaBlocks::WATER());
				$chest($w - 2, $back, "village_fisher");
				break;
			case "library":
				for($lz = 1; $lz <= $back; ++$lz){
					for($ly = 1; $ly <= 3; ++$ly){
						$t->set(1, $ly, $lz, VanillaBlocks::BOOKSHELF());
						$t->set($w - 2, $ly, $lz, VanillaBlocks::BOOKSHELF());
					}
				}
				$t->set(intdiv($w, 2), 1, $back, VanillaBlocks::LECTERN()->setFacing($t->facing(Facing::NORTH)));
				break;
			case "temple":
				$t->set(intdiv($w, 2), 1, $back, VanillaBlocks::BREWING_STAND());
				$t->fill(1, 1, $back - 2, $w - 2, 1, $back - 2, VanillaBlocks::OAK_STAIRS()->setFacing($t->facing(Facing::NORTH)));
				$chest(1, $back, "village_temple");
				//bell tower windows
				$t->fill(intdiv($w, 2), 6, 0, intdiv($w, 2), 7, 0, VanillaBlocks::AIR());
				$t->set(intdiv($w, 2), 8, 1, VanillaBlocks::BELL()->setAttachmentType(BellAttachmentType::CEILING));
				break;
			case "mason":
				$t->set(1, 1, $back, VanillaBlocks::STONECUTTER()->setFacing($t->facing(Facing::NORTH)));
				$t->fill(2, 1, $back, 3, 1, $back, VanillaBlocks::CLAY());
				$chest($w - 2, $back, "village_mason");
				break;
			case "shepherd":
				$t->set(1, 1, $back, VanillaBlocks::LOOM()->setFacing($t->facing(Facing::NORTH)));
				foreach([DyeColor::WHITE, DyeColor::GRAY, DyeColor::BLACK] as $i => $color){
					$t->set(2 + $i, 1, $back, VanillaBlocks::WOOL()->setColor($color));
				}
				$chest($w - 2, $back, "village_shepherd");
				break;
			case "tannery":
				$t->set(1, 1, $back, VanillaBlocks::CAULDRON());
				$chest($w - 2, $back, "village_tannery");
				break;
		}
		$t->set(intdiv($w, 2), $height, intdiv($d, 2), VanillaBlocks::LANTERN()->setHanging(true));
	}

	/** @param array<string, Block> $p */
	private function farm(Transform $t, Random $r, int $w, int $d, array $p) : void{
		$crops = [VanillaBlocks::WHEAT(), VanillaBlocks::CARROTS(), VanillaBlocks::POTATOES(), VanillaBlocks::BEETROOTS()];
		$crop = $crops[$r->nextBoundedInt(count($crops))];
		$log = (clone $p["frame"]);
		if($log instanceof \pocketmine\block\utils\PillarRotation){
			$log->setAxis(Axis::X);
		}
		for($lx = 0; $lx < $w; ++$lx){
			for($lz = 0; $lz < $d; ++$lz){
				$edge = $lx === 0 || $lx === $w - 1 || $lz === 0 || $lz === $d - 1;
				if($edge){
					$t->set($lx, 0, $lz, $p["frame"]);
				}elseif($lx === intdiv($w, 2)){
					$t->set($lx, 0, $lz, VanillaBlocks::WATER());
				}else{
					$t->set($lx, 0, $lz, VanillaBlocks::FARMLAND()->setWetness(7));
					$t->set($lx, 1, $lz, (clone $crop)->setAge($r->nextBoundedInt(8)));
				}
			}
		}
	}

	/** @param array<string, Block> $p */
	private function pen(Transform $t, Random $r, int $w, int $d, array $p) : void{
		for($lx = 0; $lx < $w; ++$lx){
			for($lz = 0; $lz < $d; ++$lz){
				if($lx === 0 || $lx === $w - 1 || $lz === 0 || $lz === $d - 1){
					$t->set($lx, 1, $lz, $p["fence"]);
				}
			}
		}
		$mid = intdiv($w, 2);
		$t->set($mid, 1, 0, VanillaBlocks::OAK_FENCE_GATE()->setFacing($t->facing(Facing::SOUTH)));
		$t->set(1, 1, $d - 2, VanillaBlocks::HAY_BALE());
		$t->set(2, 1, $d - 2, VanillaBlocks::HAY_BALE());
		$t->set($w - 2, 1, $d - 2, VanillaBlocks::WATER());
	}
}
