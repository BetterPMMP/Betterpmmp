<?php

/*
 * BetterPMMP - pillager outpost.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Dark oak watchtower (4 floors, birch/dark oak, cobblestone base) with a
 * loot chest on the top floor, plus small features around it: a broken cage,
 * a tent and log piles. Like vanilla, only 20% of candidate spots are used
 * and outposts keep away from villages.
 */
final class PillagerOutpost extends SimpleStructure{

	public function __construct(private ?\Closure $nearVillage = null){
		parent::__construct("pillager_outpost", 32, 8, 165745296, 2, [B::PLAINS, B::SUNFLOWER_PLAINS, B::DESERT, B::SAVANNA, B::SAVANNA_PLATEAU, B::TAIGA, B::ICE_PLAINS, B::COLD_TAIGA, B::MEADOW, B::GROVE, B::SNOWY_SLOPES, B::CHERRY_GROVE, B::FROZEN_PEAKS, B::JAGGED_PEAKS]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		if($random->nextBoundedInt(5) !== 0){
			return false;
		}
		if($this->nearVillage !== null && ($this->nearVillage)($x >> 4, $z >> 4)){
			return false;
		}
		$y = self::groundLevel($ctx, $x - 5, $z - 5, $x + 5, $z + 5);
		$start->add(new StructurePiece($x - 5, $y - 8, $z - 5, $x + 5, $y + 26, $z + 5, function(StructureCanvas $c, Random $r) use ($x, $y, $z) : void{
			$log = VanillaBlocks::DARK_OAK_LOG();
			$planks = VanillaBlocks::DARK_OAK_PLANKS();
			$birch = VanillaBlocks::BIRCH_PLANKS();
			$cobble = VanillaBlocks::COBBLESTONE();
			$air = VanillaBlocks::AIR();
			$fence = VanillaBlocks::DARK_OAK_FENCE();
			for($dx = -4; $dx <= 4; ++$dx){
				for($dz = -4; $dz <= 4; ++$dz){
					$c->foundation($x + $dx, $y, $z + $dz, $cobble, 10);
					$c->fill($x + $dx, $y + 1, $z + $dz, $x + $dx, $y + 24, $z + $dz, $air);
				}
			}
			$c->fill($x - 3, $y, $z - 3, $x + 3, $y, $z + 3, $cobble);
			$floors = [0, 6, 12, 18];
			for($ly = 1; $ly <= 20; ++$ly){
				foreach([[-3, -3], [3, -3], [-3, 3], [3, 3]] as [$dx, $dz]){
					$c->set($x + $dx, $y + $ly, $z + $dz, $log);
				}
				if($ly % 6 !== 0){
					//walls with openings
					for($i = -2; $i <= 2; ++$i){
						$open = abs($i) <= 0 && ($ly % 6 === 2 || $ly % 6 === 3);
						foreach([[-3, $i], [3, $i], [$i, -3], [$i, 3]] as [$dx, $dz]){
							$c->set($x + $dx, $y + $ly, $z + $dz, $open ? $air : ($ly > 12 ? $birch : $planks));
						}
					}
				}
			}
			foreach($floors as $f){
				$c->fill($x - 2, $y + $f, $z - 2, $x + 2, $y + $f, $z + 2, $planks);
				$c->set($x + 2, $y + $f, $z + 2, $air); //ladder hole
			}
			$c->fill($x + 2, $y + 1, $z + 2, $x + 2, $y + 18, $z + 2, VanillaBlocks::LADDER()->setFacing(Facing::NORTH));
			//door
			$c->set($x, $y + 1, $z + 3, $air);
			$c->set($x, $y + 2, $z + 3, $air);
			//roof platform with fence
			$c->fill($x - 4, $y + 21, $z - 4, $x + 4, $y + 21, $z + 4, $planks);
			for($i = -4; $i <= 4; ++$i){
				foreach([[-4, $i], [4, $i], [$i, -4], [$i, 4]] as [$dx, $dz]){
					$c->set($x + $dx, $y + 22, $z + $dz, $fence);
				}
			}
			$c->set($x + 2, $y + 21, $z + 2, $air);
			$c->set($x - 1, $y + 19, $z - 1, VanillaBlocks::CHEST(), LootTables::chestNbt("pillager_outpost", $r));
			$c->set($x - 2, $y + 2, $z - 2, VanillaBlocks::TORCH());
			$c->set($x - 2, $y + 14, $z - 2, VanillaBlocks::TORCH());
		}, $random->nextInt()));
		//surrounding features
		for($i = 0; $i < 3; ++$i){
			$fx = $x + $random->nextRange(-14, 14);
			$fz = $z + $random->nextRange(-14, 14);
			if(abs($fx - $x) < 8 && abs($fz - $z) < 8){
				continue;
			}
			$fy = $ctx->heightAt($fx, $fz);
			$kind = $random->nextBoundedInt(3);
			$start->add(new StructurePiece($fx - 2, $fy - 3, $fz - 2, $fx + 2, $fy + 5, $fz + 2, function(StructureCanvas $c, Random $r) use ($fx, $fy, $fz, $kind) : void{
				$fence = VanillaBlocks::DARK_OAK_FENCE();
				if($kind === 0){
					//cage
					for($dx = -1; $dx <= 1; ++$dx){
						for($dz = -1; $dz <= 1; ++$dz){
							$c->foundation($fx + $dx, $fy + 1, $fz + $dz, VanillaBlocks::DARK_OAK_PLANKS(), 4);
							$c->set($fx + $dx, $fy + 4, $fz + $dz, VanillaBlocks::DARK_OAK_PLANKS());
							if(abs($dx) === 1 || abs($dz) === 1){
								for($dy = 1; $dy <= 3; ++$dy){
									if($r->nextBoundedInt(4) !== 0){
										$c->set($fx + $dx, $fy + $dy, $fz + $dz, $fence);
									}
								}
							}
						}
					}
				}elseif($kind === 1){
					//tent
					$wool = VanillaBlocks::WOOL();
					for($dz = -2; $dz <= 2; ++$dz){
						$c->set($fx - 1, $fy + 1, $fz + $dz, $wool);
						$c->set($fx + 1, $fy + 1, $fz + $dz, $wool);
						$c->set($fx, $fy + 2, $fz + $dz, $wool);
					}
				}else{
					//log pile
					$log = VanillaBlocks::DARK_OAK_LOG()->setAxis(\pocketmine\math\Axis::X);
					for($dx = -2; $dx <= 2; ++$dx){
						$c->set($fx + $dx, $fy + 1, $fz, $log);
						$c->set($fx + $dx, $fy + 1, $fz + 1, $log);
						if(abs($dx) < 2){
							$c->set($fx + $dx, $fy + 2, $fz, $log);
						}
					}
					$c->set($fx, $fy + 1, $fz - 1, VanillaBlocks::PUMPKIN());
				}
			}, $random->nextInt()));
		}
		return true;
	}
}
