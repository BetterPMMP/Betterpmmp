<?php

/*
 * BetterPMMP - woodland mansion.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Two or three storey dark oak mansion in dark forests, built from a grid of
 * 8x8 rooms on a cobblestone foundation: corridors with carpet runners,
 * bedrooms, libraries, storage rooms with chests, a spider room with cobwebs,
 * dining halls, glass pane windows and a dark oak roof. Placement: spacing 80,
 * separation 20, triangular, like vanilla.
 */
final class WoodlandMansion extends SimpleStructure{

	private const CELL = 8;

	public function __construct(){
		parent::__construct("woodland_mansion", 80, 20, 10387319, 4, [B::ROOFED_FOREST, B::ROOFED_FOREST_MUTATED], true, true);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$cellsX = 5 + $random->nextBoundedInt(3);
		$cellsZ = 5 + $random->nextBoundedInt(2);
		$x0 = $x - intdiv($cellsX * self::CELL, 2);
		$z0 = $z - intdiv($cellsZ * self::CELL, 2);
		$y = self::averageLevel($ctx, $x0, $z0, $x0 + $cellsX * self::CELL, $z0 + $cellsZ * self::CELL) + 1;
		$floors = 2 + ($random->nextBoundedInt(3) === 0 ? 1 : 0);
		//which grid cells are part of the building on each floor (upper floors shrink)
		$cells = [];
		for($f = 0; $f < $floors; ++$f){
			for($cx = 0; $cx < $cellsX; ++$cx){
				for($cz = 0; $cz < $cellsZ; ++$cz){
					$edge = $cx === 0 || $cz === 0 || $cx === $cellsX - 1 || $cz === $cellsZ - 1;
					$present = $f === 0 || !$edge || $random->nextBoundedInt(3) !== 0;
					if($f === 2 && $edge){
						$present = false;
					}
					if($present){
						$types = ["bedroom", "library", "storage", "dining", "spider", "corridor", "corridor", "bedroom"];
						$cells[$f][$cx][$cz] = $types[$random->nextBoundedInt(count($types))];
					}
				}
			}
		}
		$cells[0][intdiv($cellsX, 2)][0] = "entrance";
		$floorHeight = 6;
		for($cx = 0; $cx < $cellsX; ++$cx){
			for($cz = 0; $cz < $cellsZ; ++$cz){
				$px = $x0 + $cx * self::CELL;
				$pz = $z0 + $cz * self::CELL;
				$column = [];
				for($f = 0; $f < $floors; ++$f){
					$column[$f] = $cells[$f][$cx][$cz] ?? null;
				}
				$neighbours = function(int $f, int $dx, int $dz) use ($cells, $cx, $cz) : bool{
					return isset($cells[$f][$cx + $dx][$cz + $dz]);
				};
				$start->add(new StructurePiece($px, $y - 10, $pz, $px + self::CELL, $y + $floors * $floorHeight + 6, $pz + self::CELL, function(StructureCanvas $c, Random $r) use ($px, $y, $pz, $column, $floors, $floorHeight, $neighbours) : void{
					$this->drawCell($c, $r, $px, $y, $pz, $column, $floors, $floorHeight, $neighbours);
				}, $random->nextInt()));
			}
		}
		return true;
	}

	/**
	 * @param (string|null)[] $column room type per floor
	 */
	private function drawCell(StructureCanvas $c, Random $r, int $px, int $y, int $pz, array $column, int $floors, int $floorHeight, \Closure $neighbours) : void{
		$s = self::CELL;
		$air = VanillaBlocks::AIR();
		$wall = VanillaBlocks::DARK_OAK_PLANKS();
		$log = VanillaBlocks::DARK_OAK_LOG();
		$inner = VanillaBlocks::BIRCH_PLANKS();
		$cobble = VanillaBlocks::COBBLESTONE();
		$glass = VanillaBlocks::GLASS_PANE();
		$carpet = VanillaBlocks::CARPET();
		//foundation
		if($column[0] !== null){
			for($dx = 0; $dx <= $s; ++$dx){
				for($dz = 0; $dz <= $s; ++$dz){
					$c->set($px + $dx, $y - 1, $pz + $dz, $cobble);
					$c->foundation($px + $dx, $y - 1, $pz + $dz, $cobble, 10);
				}
			}
		}
		$topFloor = -1;
		foreach($column as $f => $type){
			if($type === null){
				continue;
			}
			$topFloor = $f;
			$fy = $y + $f * $floorHeight;
			//clear, floor, ceiling
			$c->fill($px, $fy, $pz, $px + $s, $fy + $floorHeight, $pz + $s, $air);
			$c->fill($px, $fy, $pz, $px + $s, $fy, $pz + $s, $f === 0 ? $cobble : $wall);
			//outer walls where there is no neighbour cell on this floor
			foreach([[-1, 0, 0], [1, 0, $s], [0, -1, 0], [0, 1, $s]] as [$dx, $dz, $edge]){
				$outer = !$neighbours($f, $dx, $dz);
				for($i = 0; $i <= $s; ++$i){
					$wx = $dx !== 0 ? $px + $edge : $px + $i;
					$wz = $dz !== 0 ? $pz + $edge : $pz + $i;
					for($ly = 1; $ly < $floorHeight; ++$ly){
						if($outer){
							$corner = $i === 0 || $i === $s;
							$window = !$corner && ($i === 3 || $i === 5) && ($ly === 2 || $ly === 3);
							$c->set($wx, $fy + $ly, $wz, $corner ? $log : ($window ? $glass : $wall));
						}elseif($i === 0 || $i === $s){
							$c->set($wx, $fy + $ly, $wz, $inner);
						}elseif(!($i >= 3 && $i <= 5 && $ly <= 3)){
							$c->set($wx, $fy + $ly, $wz, $inner); //doorway in internal walls
						}
					}
				}
			}
			$this->furnish($c, $r, $type, $px, $fy, $pz, $f, $carpet);
			//stairs between floors: ladder in corridor cells
			if($type === "corridor" && $f + 1 < $floors && ($column[$f + 1] ?? null) !== null){
				$c->fill($px + 1, $fy + 1, $pz + 1, $px + 1, $fy + $floorHeight, $pz + 1, VanillaBlocks::LADDER()->setFacing(Facing::SOUTH));
				$c->set($px + 1, $fy + $floorHeight, $pz + 1, VanillaBlocks::LADDER()->setFacing(Facing::SOUTH));
			}
		}
		//roof over the topmost room of this column
		if($topFloor >= 0){
			$ry = $y + ($topFloor + 1) * $floorHeight;
			$c->fill($px, $ry, $pz, $px + $s, $ry, $pz + $s, $wall);
			$c->fill($px + 1, $ry + 1, $pz + 1, $px + $s - 1, $ry + 1, $pz + $s - 1, VanillaBlocks::DARK_OAK_SLAB());
			if($topFloor + 1 < $floors){
				//terrace fence where the floor above is missing
				for($i = 0; $i <= $s; ++$i){
					$c->set($px + $i, $ry + 1, $pz, VanillaBlocks::DARK_OAK_FENCE());
					$c->set($px + $i, $ry + 1, $pz + $s, VanillaBlocks::DARK_OAK_FENCE());
				}
			}
		}
	}

	private function furnish(StructureCanvas $c, Random $r, string $type, int $px, int $fy, int $pz, int $floor, \pocketmine\block\Carpet $carpet) : void{
		switch($type){
			case "entrance":
				$c->fill($px + 3, $fy + 1, $pz, $px + 5, $fy + 3, $pz, VanillaBlocks::AIR());
				$c->set($px + 4, $fy + 1, $pz, VanillaBlocks::DARK_OAK_DOOR()->setFacing(Facing::SOUTH));
				$c->set($px + 4, $fy + 2, $pz, VanillaBlocks::DARK_OAK_DOOR()->setFacing(Facing::SOUTH)->setTop(true));
				$c->fill($px + 3, $fy + 1, $pz, $px + 3, $fy + 2, $pz, VanillaBlocks::DARK_OAK_PLANKS());
				$c->fill($px + 5, $fy + 1, $pz, $px + 5, $fy + 2, $pz, VanillaBlocks::DARK_OAK_PLANKS());
				break;
			case "corridor":
				$red = (clone $carpet)->setColor(DyeColor::RED);
				$c->fill($px + 3, $fy + 1, $pz + 1, $px + 5, $fy + 1, $pz + 7, $red);
				$c->fill($px + 1, $fy + 1, $pz + 3, $px + 7, $fy + 1, $pz + 5, $red);
				break;
			case "bedroom":
				$color = DyeColor::cases()[$r->nextBoundedInt(16)];
				$c->fill($px + 1, $fy + 1, $pz + 1, $px + 7, $fy + 1, $pz + 7, (clone $carpet)->setColor($color));
				$c->set($px + 2, $fy + 1, $pz + 6, VanillaBlocks::BED()->setFacing(Facing::SOUTH)->setColor($color));
				$c->set($px + 2, $fy + 1, $pz + 7, VanillaBlocks::BED()->setFacing(Facing::SOUTH)->setHead(true)->setColor($color));
				$c->set($px + 6, $fy + 1, $pz + 7, VanillaBlocks::CHEST(), LootTables::chestNbt("woodland_mansion", $r));
				$c->set($px + 6, $fy + 1, $pz + 1, VanillaBlocks::FLOWER_POT());
				break;
			case "library":
				for($i = 1; $i <= 7; ++$i){
					for($ly = 1; $ly <= 3; ++$ly){
						$c->set($px + $i, $fy + $ly, $pz + 1, VanillaBlocks::BOOKSHELF());
						$c->set($px + $i, $fy + $ly, $pz + 7, VanillaBlocks::BOOKSHELF());
					}
				}
				$c->set($px + 4, $fy + 1, $pz + 4, VanillaBlocks::LECTERN());
				break;
			case "storage":
				for($i = 0; $i < 3; ++$i){
					$c->set($px + 1 + $i * 2, $fy + 1, $pz + 7, VanillaBlocks::CHEST(), LootTables::chestNbt("woodland_mansion", $r));
				}
				$c->set($px + 7, $fy + 1, $pz + 1, VanillaBlocks::HAY_BALE());
				$c->set($px + 7, $fy + 1, $pz + 2, VanillaBlocks::HAY_BALE());
				break;
			case "dining":
				$c->fill($px + 2, $fy + 1, $pz + 3, $px + 6, $fy + 1, $pz + 5, VanillaBlocks::DARK_OAK_FENCE());
				$c->fill($px + 2, $fy + 2, $pz + 3, $px + 6, $fy + 2, $pz + 5, (clone $carpet)->setColor(DyeColor::WHITE));
				break;
			case "spider":
				for($i = 0; $i < 20; ++$i){
					$c->set($px + $r->nextRange(1, 7), $fy + $r->nextRange(1, 5), $pz + $r->nextRange(1, 7), VanillaBlocks::COBWEB());
				}
				$c->set($px + 4, $fy + 1, $pz + 4, VanillaBlocks::CHEST(), LootTables::chestNbt("woodland_mansion", $r));
				break;
		}
		$c->set($px + 4, $fy + 5, $pz + 4, VanillaBlocks::LANTERN()->setHanging(true));
	}
}
