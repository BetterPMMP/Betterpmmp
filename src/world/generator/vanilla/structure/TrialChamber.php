<?php

/*
 * BetterPMMP - trial chambers (1.21).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\ExtraVanillaBlocks;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use function max;
use function min;

/**
 * Trial chambers deep underground (y -40 .. -20): a tuff brick and copper
 * hall in the middle, corridors in the four directions with branches, and
 * chambers at their ends. Every chamber has a trial spawner on a chiseled
 * tuff pedestal, many have a vault (opened with the trial keys the spawners
 * drop), and supply chests and decorated corners are spread around.
 * Placement like vanilla: spacing 34, separation 12.
 */
final class TrialChamber extends SimpleStructure{

	public function __construct(){
		parent::__construct("trial_chambers", 34, 12, 94251327, 5, [], false);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		if($ctx->heightAt($x, $z) < 50){
			return false; // not under oceans
		}
		$y = -40 + $random->nextBoundedInt(16);
		// central hall 17x17
		$start->add(new StructurePiece($x - 9, $y - 1, $z - 9, $x + 9, $y + 10, $z + 9, function(StructureCanvas $c, Random $r) use ($x, $y, $z) : void{
			$this->hall($c, $r, $x, $y, $z);
		}, $random->nextInt()));
		foreach([Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST] as $face){
			[$dx, , $dz] = Facing::OFFSET[$face];
			$length = 10 + $random->nextBoundedInt(10);
			$sx = $x + $dx * 9;
			$sz = $z + $dz * 9;
			$ex = $sx + $dx * $length;
			$ez = $sz + $dz * $length;
			$this->corridor($start, $random, $sx, $y, $sz, $ex, $ez);
			$this->chamber($start, $random, $ex + $dx * 6, $y, $ez + $dz * 6);
			// a side branch with another chamber
			if($random->nextBoundedInt(3) > 0){
				$mx = ($sx + $ex) >> 1;
				$mz = ($sz + $ez) >> 1;
				$side = $random->nextBoolean() ? 1 : -1;
				[$px, $pz] = [$dz * $side, $dx * $side];
				$len = 8 + $random->nextBoundedInt(6);
				$this->corridor($start, $random, $mx, $y, $mz, $mx + $px * $len, $mz + $pz * $len);
				$this->chamber($start, $random, $mx + $px * ($len + 6), $y, $mz + $pz * ($len + 6));
			}
		}
		return true;
	}

	private static function bricks(Random $r) : Block{
		$roll = $r->nextBoundedInt(12);
		return $roll === 0 ? VanillaBlocks::CHISELED_TUFF_BRICKS() : ($roll < 3 ? VanillaBlocks::POLISHED_TUFF() : VanillaBlocks::TUFF_BRICKS());
	}

	private static function copper(Random $r) : Block{
		return $r->nextBoundedInt(3) === 0 ? VanillaBlocks::CUT_COPPER()->setWaxed(true) : VanillaBlocks::COPPER()->setWaxed(true);
	}

	/** Hollow box: tuff brick shell, air inside, copper floor pattern. */
	private function room(StructureCanvas $c, Random $r, int $x1, int $y, int $z1, int $x2, int $z2, int $height) : void{
		$c->fillWith($x1, $y - 1, $z1, $x2, $y + $height, $z2, fn() => self::bricks($r));
		$c->fill($x1 + 1, $y, $z1 + 1, $x2 - 1, $y + $height - 1, $z2 - 1, VanillaBlocks::AIR());
		for($x = $x1 + 1; $x < $x2; ++$x){
			for($z = $z1 + 1; $z < $z2; ++$z){
				$c->set($x, $y - 1, $z, (($x + $z) & 3) === 0 ? self::copper($r) : VanillaBlocks::POLISHED_TUFF());
			}
		}
	}

	private function hall(StructureCanvas $c, Random $r, int $x, int $y, int $z) : void{
		$this->room($c, $r, $x - 9, $y, $z - 9, $x + 9, $z + 9, 10);
		// copper grate ceiling with lit bulbs
		for($dx = -7; $dx <= 7; $dx += 2){
			for($dz = -7; $dz <= 7; $dz += 2){
				$c->set($x + $dx, $y + 10, $z + $dz, VanillaBlocks::COPPER_GRATE()->setWaxed(true));
			}
		}
		foreach([[-7, -7], [7, -7], [-7, 7], [7, 7], [0, 0]] as [$dx, $dz]){
			$c->set($x + $dx, $y + 9, $z + $dz, VanillaBlocks::COPPER_BULB()->setWaxed(true)->setLit(true));
		}
		// central pillar with a spawner and a vault at its foot
		$c->fill($x - 1, $y, $z - 1, $x + 1, $y, $z + 1, VanillaBlocks::CHISELED_TUFF());
		$this->spawner($c, $x, $y + 1, $z);
		$this->vault($c, $r, $x, $y, $z + 3);
		$c->set($x + 6, $y, $z - 6, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("trial_chambers_supply", $r));
	}

	private function corridor(StructureStart $start, Random $random, int $sx, int $y, int $sz, int $ex, int $ez) : void{
		$x1 = min($sx, $ex) - 2;
		$x2 = max($sx, $ex) + 2;
		$z1 = min($sz, $ez) - 2;
		$z2 = max($sz, $ez) + 2;
		$start->add(new StructurePiece($x1, $y - 1, $z1, $x2, $y + 5, $z2, function(StructureCanvas $c, Random $r) use ($x1, $x2, $z1, $z2, $y) : void{
			$this->room($c, $r, $x1, $y, $z1, $x2, $z2, 5);
			for($x = $x1 + 2; $x < $x2; $x += 6){
				for($z = $z1 + 2; $z < $z2; $z += 6){
					$c->set($x, $y + 4, $z, VanillaBlocks::COPPER_BULB()->setWaxed(true)->setLit(true));
				}
			}
			if($r->nextBoundedInt(3) === 0){
				$c->set($x1 + 1, $y, $z1 + 1, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("trial_chambers_corridor", $r));
			}
		}, $random->nextInt()));
	}

	private function chamber(StructureStart $start, Random $random, int $cx, int $y, int $cz) : void{
		$hasVault = $random->nextBoundedInt(3) > 0;
		$start->add(new StructurePiece($cx - 6, $y - 1, $cz - 6, $cx + 6, $y + 7, $cz + 6, function(StructureCanvas $c, Random $r) use ($cx, $cz, $y, $hasVault) : void{
			$this->room($c, $r, $cx - 6, $y, $cz - 6, $cx + 6, $cz + 6, 7);
			$c->set($cx, $y, $cz, VanillaBlocks::CHISELED_TUFF());
			$this->spawner($c, $cx, $y + 1, $cz);
			// second spawner in bigger fights
			if($r->nextBoundedInt(3) === 0){
				$c->set($cx + 3, $y, $cz + 3, VanillaBlocks::CHISELED_TUFF());
				$this->spawner($c, $cx + 3, $y + 1, $cz + 3);
			}
			if($hasVault){
				$this->vault($c, $r, $cx - 4, $y, $cz);
			}
			foreach([[-5, -5], [5, -5], [-5, 5], [5, 5]] as [$dx, $dz]){
				$c->set($cx + $dx, $y + 6, $cz + $dz, VanillaBlocks::COPPER_BULB()->setWaxed(true)->setLit(true));
			}
			if($r->nextBoundedInt(2) === 0){
				$c->set($cx + 5, $y, $cz - 5, VanillaBlocks::CHEST()->setFacing(Facing::WEST), LootTables::chestNbt("trial_chambers_supply", $r));
			}
		}, $random->nextInt()));
	}

	private function spawner(StructureCanvas $c, int $x, int $y, int $z) : void{
		$block = ExtraVanillaBlocks::get("minecraft:trial_spawner");
		if($block !== null){
			$c->set($x, $y, $z, $block->withProperties(["trial_spawner_state" => 1]));
		}
	}

	private function vault(StructureCanvas $c, Random $r, int $x, int $y, int $z) : void{
		$block = ExtraVanillaBlocks::get("minecraft:vault");
		if($block !== null){
			$c->set($x, $y, $z, $block->withProperties(["vault_state" => "active"]));
		}
	}
}
