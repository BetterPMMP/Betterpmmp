<?php

/*
 * BetterPMMP - desert pyramid (desert temple).
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
 * 21x21 sandstone pyramid with two corner towers, orange/blue terracotta
 * patterns, and the hidden treasure room below: four chests and a TNT trap
 * triggered by a stone pressure plate.
 */
final class DesertPyramid extends SimpleStructure{

	public function __construct(){
		parent::__construct("desert_pyramid", 32, 8, 14357617, 1, [B::DESERT]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$x0 = $x - 10;
		$z0 = $z - 10;
		$y = self::groundLevel($ctx, $x0, $z0, $x0 + 20, $z0 + 20);
		$start->add(new StructurePiece($x0, $y - 14, $z0, $x0 + 20, $y + 14, $z0 + 20, function(StructureCanvas $c, Random $r) use ($x0, $y, $z0) : void{
			$t = new Transform($c, $x0, $y, $z0, 0);
			$ss = VanillaBlocks::SANDSTONE();
			$cut = VanillaBlocks::CUT_SANDSTONE();
			$chiseled = VanillaBlocks::CHISELED_SANDSTONE();
			$air = VanillaBlocks::AIR();
			$orange = VanillaBlocks::STAINED_CLAY()->setColor(DyeColor::ORANGE);
			$blue = VanillaBlocks::STAINED_CLAY()->setColor(DyeColor::BLUE);
			//base and foundation
			$t->fill(0, -4, 0, 20, 0, 20, $ss);
			for($lx = 0; $lx <= 20; ++$lx){
				for($lz = 0; $lz <= 20; ++$lz){
					$t->foundation($lx, -4, $lz, $ss, 12);
				}
			}
			$t->fill(0, 1, 0, 20, 20, 20, $air);
			//stepped pyramid: each layer one block smaller on every side
			for($level = 1; $level <= 10; ++$level){
				$t->fill($level - 1, $level, $level - 1, 21 - $level, $level, 21 - $level, $ss);
			}
			//inner hall
			$t->fill(4, 1, 4, 16, 6, 16, $air);
			$t->fill(5, 0, 5, 15, 0, 15, $ss);
			//towers at the front corners
			foreach([0, 16] as $tx){
				$t->fill($tx, 1, 0, $tx + 4, 12, 4, $ss);
				$t->fill($tx + 1, 1, 1, $tx + 3, 12, 3, $air);
				$t->fill($tx, 12, 0, $tx + 4, 12, 4, $cut);
				$t->fill($tx + 1, 1, 1, $tx + 1, 11, 1, VanillaBlocks::SANDSTONE_STAIRS()->setFacing(Facing::NORTH));
				for($ly = 5; $ly <= 9; $ly += 2){
					$t->set($tx + 2, $ly, 0, $orange);
				}
				$t->set($tx + 2, 10, 0, $chiseled);
			}
			//entrance
			$t->fill(8, 1, 0, 12, 5, 4, $ss);
			$t->fill(9, 1, 0, 11, 3, 4, $air);
			$t->set(10, 4, 0, $chiseled);
			//floor pattern: blue centre with orange cross
			$t->set(10, 0, 10, $blue);
			foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]){
				$t->set(10 + $dx, 0, 10 + $dz, $orange);
				$t->set(10 + $dx * 2, 0, 10 + $dz * 2, $orange);
			}
			//hidden shaft and treasure room 12 blocks below
			$t->fill(9, -13, 9, 11, -1, 11, $air);
			$t->set(10, 0, 10, $blue);
			$t->box(7, -14, 7, 13, -9, 13, $ss, $air);
			$t->fill(8, -13, 8, 12, -10, 12, $air);
			$t->fill(9, -1, 9, 11, -1, 11, $air);
			$t->fill(10, -13, 10, 10, -1, 10, $air);
			$t->set(10, -13, 10, VanillaBlocks::STONE_PRESSURE_PLATE());
			$t->fill(9, -15, 9, 11, -15, 11, VanillaBlocks::TNT());
			foreach([[10, 8], [10, 12], [8, 10], [12, 10]] as [$cx, $cz]){
				$t->set($cx, -13, $cz, VanillaBlocks::CHEST(), LootTables::chestNbt("desert_pyramid", $r));
			}
			foreach([[8, 8], [8, 12], [12, 8], [12, 12]] as [$cx, $cz]){
				$t->set($cx, -12, $cz, $chiseled);
			}
		}, $random->nextInt()));
		return true;
	}
}
