<?php

/*
 * BetterPMMP - jungle temple (jungle pyramid).
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
 * 12x15 mossy cobblestone temple with three levels, a stair hall down to
 * the basement, a lever puzzle wall (decorative), a hidden chest behind the
 * puzzle and a second chest at the end of the trap corridor. Vines grow on
 * the outside walls.
 */
final class JungleTemple extends SimpleStructure{

	public function __construct(){
		parent::__construct("jungle_temple", 32, 8, 14357619, 1, [B::JUNGLE, B::BAMBOO_JUNGLE, B::JUNGLE_EDGE]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$rot = $random->nextBoundedInt(4);
		[$minX, $minZ, $maxX, $maxZ] = Transform::bounds($x - 6, $z - 7, $rot, 12, 15);
		$y = self::groundLevel($ctx, $minX, $minZ, $maxX, $maxZ) - 1;
		$ox = $x - 6;
		$oz = $z - 7;
		$start->add(new StructurePiece($minX, $y - 4, $minZ, $maxX, $y + 14, $maxZ, function(StructureCanvas $c, Random $r) use ($ox, $y, $oz, $rot) : void{
			$t = new Transform($c, $ox, $y, $oz, $rot);
			$air = VanillaBlocks::AIR();
			$stone = function() use ($r){
				return $r->nextFloat() < 0.4 ? VanillaBlocks::MOSSY_COBBLESTONE() : VanillaBlocks::COBBLESTONE();
			};
			//clear the area and build a solid shell
			$t->fill(0, 0, 0, 11, 13, 14, $air);
			for($lx = 0; $lx <= 11; ++$lx){
				for($lz = 0; $lz <= 14; ++$lz){
					for($ly = -4; $ly <= 13; ++$ly){
						$edge = $lx === 0 || $lx === 11 || $lz === 0 || $lz === 14;
						$floor = $ly === -4 || $ly === 0 || $ly === 4 || $ly === 9;
						$inside = $ly < 9 || ($lx >= 2 && $lx <= 9 && $lz >= 2 && $lz <= 12);
						if(!$inside){
							continue;
						}
						if($ly >= 9){
							//upper storey is smaller
							$edge = $lx === 2 || $lx === 9 || $lz === 2 || $lz === 12;
							$floor = $ly === 9 || $ly === 13;
							if($lx < 2 || $lx > 9 || $lz < 2 || $lz > 12){
								continue;
							}
						}
						if($edge || $floor){
							$t->set($lx, $ly, $lz, $stone());
						}
					}
					$t->foundation($lx, -4, $lz, VanillaBlocks::COBBLESTONE(), 10);
				}
			}
			//doorway and windows
			$t->fill(5, 1, 0, 6, 3, 0, $air);
			foreach([3, 8] as $wz){
				$t->set(0, 2, $wz, $air);
				$t->set(11, 2, $wz, $air);
				$t->set(0, 6, $wz, $air);
				$t->set(11, 6, $wz, $air);
			}
			$t->fill(5, 10, 2, 6, 11, 2, $air);
			//stairs from the ground floor down to the basement
			for($i = 0; $i < 4; ++$i){
				$t->set(5, -$i, 6 + $i, VanillaBlocks::COBBLESTONE_STAIRS()->setFacing($t->facing(Facing::NORTH)));
				$t->set(6, -$i, 6 + $i, VanillaBlocks::COBBLESTONE_STAIRS()->setFacing($t->facing(Facing::NORTH)));
				$t->fill(5, -$i + 1, 6 + $i, 6, 3, 6 + $i, $air);
			}
			//stairs to the upper floor
			for($i = 0; $i < 4; ++$i){
				$t->set(2, 1 + $i, 3 + $i, VanillaBlocks::COBBLESTONE_STAIRS()->setFacing($t->facing(Facing::SOUTH)));
				$t->fill(2, 2 + $i, 3 + $i, 2, 4, 3 + $i, $air);
			}
			$t->fill(1, 4, 3, 3, 4, 7, $air);
			//lever puzzle (decorative) and chests in the basement
			foreach([7, 8, 9] as $lx){
				$t->set($lx, -2, 12, VanillaBlocks::LEVER()->setFacing(\pocketmine\block\utils\LeverFacing::SOUTH));
			}
			$t->set(9, -3, 13, VanillaBlocks::CHEST(), LootTables::chestNbt("jungle_temple", $r));
			$t->set(2, -3, 12, VanillaBlocks::CHEST(), LootTables::chestNbt("jungle_temple", $r));
			//tripwire corridor (tripwire with hooks; the core has no dispensers)
			$t->set(4, -3, 3, VanillaBlocks::TRIPWIRE_HOOK()->setFacing($t->facing(Facing::EAST)));
			$t->set(5, -3, 3, VanillaBlocks::TRIPWIRE());
			$t->set(6, -3, 3, VanillaBlocks::TRIPWIRE());
			$t->set(7, -3, 3, VanillaBlocks::TRIPWIRE_HOOK()->setFacing($t->facing(Facing::WEST)));
			//vines on the outside
			for($lz = 1; $lz < 14; ++$lz){
				for($ly = 1; $ly < 9; ++$ly){
					if($r->nextBoundedInt(3) === 0){
						$t->set(-1, $ly, $lz, VanillaBlocks::VINES()->setFace($t->facing(Facing::EAST), true));
					}
					if($r->nextBoundedInt(3) === 0){
						$t->set(12, $ly, $lz, VanillaBlocks::VINES()->setFace($t->facing(Facing::WEST), true));
					}
				}
			}
		}, $random->nextInt()));
		return true;
	}
}
