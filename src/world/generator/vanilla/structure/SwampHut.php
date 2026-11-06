<?php

/*
 * BetterPMMP - swamp hut (witch hut).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;

/**
 * Spruce plank hut on four oak log stilts in a swamp, with a cauldron,
 * crafting table, flower pot and fence windows.
 */
final class SwampHut extends SimpleStructure{

	public function __construct(){
		parent::__construct("swamp_hut", 32, 8, 14357620, 1, [B::SWAMPLAND]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$rot = $random->nextBoundedInt(4);
		$ox = $x - 3;
		$oz = $z - 4;
		[$minX, $minZ, $maxX, $maxZ] = Transform::bounds($ox, $oz, $rot, 7, 9);
		$y = max(64, self::averageLevel($ctx, $minX, $minZ, $maxX, $maxZ) + 2);
		$start->add(new StructurePiece($minX, $y - 8, $minZ, $maxX, $y + 7, $maxZ, function(StructureCanvas $c, Random $r) use ($ox, $y, $oz, $rot) : void{
			$t = new Transform($c, $ox, $y, $oz, $rot);
			$planks = VanillaBlocks::SPRUCE_PLANKS();
			$log = VanillaBlocks::OAK_LOG();
			$air = VanillaBlocks::AIR();
			//stilts down to the ground
			foreach([[1, 2], [5, 2], [1, 7], [5, 7]] as [$lx, $lz]){
				$t->fill($lx, 0, $lz, $lx, 1, $lz, $log);
				$t->foundation($lx, 0, $lz, $log, 10);
			}
			//floor, walls, roof
			$t->fill(1, 1, 1, 5, 1, 7, $planks);
			$t->fill(1, 2, 2, 5, 4, 7, $planks);
			$t->fill(2, 2, 3, 4, 4, 6, $air);
			$t->fill(1, 2, 1, 5, 4, 1, $air); //porch
			$t->set(3, 2, 2, $air);
			$t->set(3, 3, 2, $air);
			foreach([2, 3, 4] as $ly){
				$t->set(1, $ly, 2, $log);
				$t->set(5, $ly, 2, $log);
				$t->set(1, $ly, 7, $log);
				$t->set(5, $ly, 7, $log);
			}
			$t->set(1, 3, 4, VanillaBlocks::OAK_FENCE());
			$t->set(5, 3, 4, VanillaBlocks::OAK_FENCE());
			$t->set(3, 3, 7, VanillaBlocks::OAK_FENCE());
			$t->set(1, 2, 1, VanillaBlocks::OAK_FENCE());
			$t->set(5, 2, 1, VanillaBlocks::OAK_FENCE());
			$stairs = VanillaBlocks::SPRUCE_STAIRS();
			for($lz = 0; $lz <= 8; ++$lz){
				$t->set(0, 4, $lz, (clone $stairs)->setFacing($t->facing(Facing::EAST)));
				$t->set(6, 4, $lz, (clone $stairs)->setFacing($t->facing(Facing::WEST)));
				$t->fill(1, 5, $lz, 5, 5, $lz, $planks);
			}
			//furniture
			$t->set(4, 2, 6, VanillaBlocks::CAULDRON());
			$t->set(2, 2, 6, VanillaBlocks::CRAFTING_TABLE());
			$t->set(2, 3, 3, VanillaBlocks::FLOWER_POT());
		}, $random->nextInt()));
		return true;
	}
}
