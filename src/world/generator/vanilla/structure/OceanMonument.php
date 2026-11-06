<?php

/*
 * BetterPMMP - ocean monument.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\utils\Random;

/**
 * 58x58 prismarine monument on the floor of deep oceans: raised base on
 * pillars, two wings, a central tower with the penthouse, sea lantern
 * windows, flooded rooms on two levels, wet sponge rooms and the core with
 * eight gold blocks. Placement: spacing 32, separation 5, triangular.
 * (Guardians are entities; the core does not spawn them yet.)
 */
final class OceanMonument extends SimpleStructure{

	public function __construct(){
		parent::__construct("ocean_monument", 32, 5, 10387313, 3, [B::DEEP_OCEAN, B::DEEP_COLD_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::DEEP_FROZEN_OCEAN], true, true);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$x0 = $x - 29;
		$z0 = $z - 29;
		//all corners must be deep ocean too
		foreach([[$x0, $z0], [$x0 + 57, $z0], [$x0, $z0 + 57], [$x0 + 57, $z0 + 57]] as [$cx, $cz]){
			if($ctx->heightAt($cx, $cz) > 50){
				return false;
			}
		}
		$y = 39;
		$start->add(new StructurePiece($x0, $y - 20, $z0, $x0 + 57, $y + 24, $z0 + 57, function(StructureCanvas $c, Random $r) use ($x0, $y, $z0) : void{
			$this->draw($c, $r, $x0, $y, $z0);
		}, $random->nextInt()));
		return true;
	}

	private function draw(StructureCanvas $c, Random $r, int $x0, int $y, int $z0) : void{
		$t = new Transform($c, $x0, $y, $z0, 0);
		$bricks = VanillaBlocks::PRISMARINE_BRICKS();
		$prismarine = VanillaBlocks::PRISMARINE();
		$dark = VanillaBlocks::DARK_PRISMARINE();
		$lantern = VanillaBlocks::SEA_LANTERN();
		$water = VanillaBlocks::WATER();

		//base platform on pillars
		$t->fill(0, 0, 0, 57, 0, 57, $prismarine);
		for($lx = 0; $lx <= 57; $lx += 5){
			for($lz = 0; $lz <= 57; $lz += 5){
				$t->foundation($lx, 0, $lz, $prismarine, 22);
			}
		}
		//water volume above the platform
		$t->fill(0, 1, 0, 57, 23, 57, $water);
		//wings (left and right) and main hall
		foreach([[0, 21], [36, 57]] as [$a, $b]){
			$t->box($a, 1, 0, $b, 8, 57, $bricks, $water);
			$t->fill($a, 8, 0, $b, 8, 57, $dark);
		}
		$t->box(21, 1, 8, 36, 12, 57, $bricks, $water);
		$t->fill(21, 12, 8, 36, 12, 57, $dark);
		//central tower and penthouse
		$t->box(22, 12, 22, 35, 20, 35, $bricks, $water);
		$t->box(25, 20, 25, 32, 23, 32, $dark, $water);
		//entrance at the front (z = 0 side of the main hall)
		$t->fill(25, 1, 8, 32, 6, 8, $water);
		$t->fill(25, 1, 0, 32, 1, 7, $bricks);
		//second floor in the wings
		$t->fill(1, 4, 1, 20, 4, 56, $prismarine);
		$t->fill(37, 4, 1, 56, 4, 56, $prismarine);
		//room walls inside the wings with openings
		foreach([[0, 21], [36, 57]] as [$a, $b]){
			for($lz = 8; $lz < 57; $lz += 8){
				$t->fill($a + 1, 1, $lz, $b - 1, 7, $lz, $bricks);
				$mid = ($a + $b) >> 1;
				$t->fill($mid - 1, 1, $lz, $mid + 1, 3, $lz, $water);
				$t->fill($mid - 1, 5, $lz, $mid + 1, 7, $lz, $water);
			}
			$t->fill(($a + $b) >> 1, 4, 2, (($a + $b) >> 1) + 1, 4, 3, $water); //stair hole
		}
		//sea lantern windows on outer walls
		for($i = 3; $i < 57; $i += 6){
			foreach([2, 6] as $ly){
				$t->set(0, $ly, $i, $lantern);
				$t->set(57, $ly, $i, $lantern);
			}
			$t->set($i, 5, 0, $lantern);
			$t->set($i, 5, 57, $lantern);
		}
		foreach([[22, 16, 22], [35, 16, 22], [22, 16, 35], [35, 16, 35], [28, 22, 25], [28, 22, 32]] as [$lx, $ly, $lz]){
			$t->set($lx, $ly, $lz, $lantern);
		}
		//core: 8 gold blocks inside dark prismarine
		$t->box(25, 9, 40, 32, 15, 47, $dark, $water);
		$t->fill(28, 11, 43, 29, 12, 44, VanillaBlocks::GOLD());
		$t->fill(28, 10, 40, 29, 11, 40, $water);
		//sponge rooms
		$sponge = VanillaBlocks::SPONGE()->setWet(true);
		foreach([[5, 7, 18], [42, 7, 34]] as [$lx, $ly, $lz]){
			for($i = 0; $i < 10; ++$i){
				$t->set($lx + $r->nextBoundedInt(8), $ly, $lz + $r->nextBoundedInt(6), $sponge);
			}
		}
		//hall pillars
		for($lz = 14; $lz < 57; $lz += 8){
			foreach([24, 33] as $lx){
				$t->fill($lx, 1, $lz, $lx, 11, $lz, $prismarine);
			}
		}
	}
}
