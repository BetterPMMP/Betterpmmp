<?php

/*
 * BetterPMMP - end cities and end ships.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * End city on the outer islands: an entrance house, two to three purpur
 * towers with end stone brick floors, end rods, chests on the top floors and
 * bridges between them; tall cities can have an end ship floating beside
 * them with two treasure chests and a dragon head on the bow.
 * Placement: spacing 20, separation 11, triangular; island surface >= 60
 * and more than 1024 blocks from the centre, like vanilla.
 * (Shulkers are entities and elytra are not in the core yet.)
 */
final class EndCity extends Structure{

	public function getName() : string{ return "end_city"; }
	protected function getSpacing() : int{ return 20; }
	protected function getSeparation() : int{ return 11; }
	protected function getSalt() : int{ return 10387313; }
	public function getMaxRadiusChunks() : int{ return 3; }
	protected function isTriangular() : bool{ return true; }

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$x = ($chunkX << 4) + 8;
		$z = ($chunkZ << 4) + 8;
		if($x * $x + $z * $z < 1024 * 1024){
			return null;
		}
		$y = min($ctx->heightAt($x - 6, $z - 6), $ctx->heightAt($x + 6, $z - 6), $ctx->heightAt($x - 6, $z + 6), $ctx->heightAt($x + 6, $z + 6));
		if($y < 59){
			return null;
		}
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, true);
		//entrance house
		$start->add(new StructurePiece($x - 4, $y, $z - 4, $x + 4, $y + 8, $z + 4, function(StructureCanvas $c, Random $r) use ($x, $y, $z) : void{
			$this->room($c, $x, $y, $z, 4, 5, true);
			$c->fill($x, $y + 1, $z - 4, $x, $y + 3, $z - 4, VanillaBlocks::AIR());
		}, $random->nextInt()));
		//towers
		$towers = 2 + $random->nextBoundedInt(2);
		$tallest = [0, 0, 0];
		$prev = [$x, $z, $y + 10];
		for($i = 0; $i < $towers; ++$i){
			$angle = $random->nextFloat() * M_PI * 2;
			$tx = $x + (int) (cos($angle) * (10 + $i * 6));
			$tz = $z + (int) (sin($angle) * (10 + $i * 6));
			$floors = 3 + $random->nextBoundedInt(4);
			$height = $floors * 4;
			$top = $y + $height;
			if($top > $tallest[2]){
				$tallest = [$tx, $tz, $top];
			}
			$start->add(new StructurePiece($tx - 4, $y - 4, $tz - 4, $tx + 4, $top + 10, $tz + 4, function(StructureCanvas $c, Random $r) use ($tx, $tz, $y, $floors) : void{
				$this->tower($c, $r, $tx, $y, $tz, $floors);
			}, $random->nextInt()));
			//bridge from the previous tower/house to this tower at mid height
			[$px, $pz, $py] = $prev;
			$by = min($py, $y + intdiv($height, 2));
			$start->add(new StructurePiece(min($px, $tx) - 1, $by - 1, min($pz, $tz) - 1, max($px, $tx) + 1, $by + 3, max($pz, $tz) + 1, function(StructureCanvas $c, Random $r) use ($px, $pz, $tx, $tz, $by) : void{
				$this->bridge($c, $px, $pz, $tx, $tz, $by);
			}, $random->nextInt()));
			$prev = [$tx, $tz, $y + intdiv($height, 2)];
		}
		//end ship next to the tallest tower
		if($tallest[2] > $y + 16 && $random->nextBoundedInt(2) === 0){
			[$sx, $sz, $sy] = $tallest;
			$sx += 12;
			$sy += 4;
			$start->add(new StructurePiece($sx - 8, $sy - 3, $sz - 3, $sx + 8, $sy + 10, $sz + 3, function(StructureCanvas $c, Random $r) use ($sx, $sy, $sz) : void{
				$this->ship($c, $r, $sx, $sy, $sz);
			}, $random->nextInt()));
		}
		return $start;
	}

	private function room(StructureCanvas $c, int $x, int $y, int $z, int $half, int $height, bool $foundation) : void{
		$purpur = VanillaBlocks::PURPUR();
		$pillar = VanillaBlocks::PURPUR_PILLAR();
		$bricks = VanillaBlocks::END_STONE_BRICKS();
		$air = VanillaBlocks::AIR();
		for($dx = -$half; $dx <= $half; ++$dx){
			for($dz = -$half; $dz <= $half; ++$dz){
				$corner = abs($dx) === $half && abs($dz) === $half;
				$edge = abs($dx) === $half || abs($dz) === $half;
				$c->set($x + $dx, $y, $z + $dz, $bricks);
				if($foundation){
					$c->foundation($x + $dx, $y, $z + $dz, $bricks, 6);
				}
				for($dy = 1; $dy < $height; ++$dy){
					$window = $edge && !$corner && $dy === 2 && (abs($dx) === 1 || abs($dz) === 1);
					$c->set($x + $dx, $y + $dy, $z + $dz, $corner ? $pillar : ($edge ? ($window ? VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::MAGENTA) : $purpur) : $air));
				}
				$c->set($x + $dx, $y + $height, $z + $dz, $edge ? VanillaBlocks::PURPUR_SLAB() : $purpur);
			}
		}
	}

	private function tower(StructureCanvas $c, Random $r, int $x, int $y, int $z, int $floors) : void{
		for($f = 0; $f < $floors; ++$f){
			$this->room($c, $x, $y + $f * 4, $z, 2, 4, $f === 0);
			//ladder-like stair hole between floors
			if($f > 0){
				$c->set($x, $y + $f * 4, $z, VanillaBlocks::AIR());
			}
			$c->fill($x, $y + $f * 4 + 1, $z + 1, $x, $y + $f * 4 + 4, $z + 1, VanillaBlocks::LADDER()->setFacing(Facing::NORTH));
		}
		$top = $y + $floors * 4;
		//wider top room with the loot
		$this->room($c, $x, $top, $z, 4, 5, false);
		$c->set($x, $top, $z, VanillaBlocks::AIR());
		$c->set($x + 3, $top + 1, $z + 3, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), LootTables::chestNbt("end_city_treasure", $r));
		if($r->nextBoolean()){
			$c->set($x - 3, $top + 1, $z + 3, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), LootTables::chestNbt("end_city_treasure", $r));
		}
		//end rods on the roof corners
		foreach([[-4, -4], [4, -4], [-4, 4], [4, 4]] as [$dx, $dz]){
			$c->set($x + $dx, $top + 6, $z + $dz, VanillaBlocks::END_ROD()->setFacing(Facing::UP));
		}
		$c->set($x, $top + 1, $z - 3, VanillaBlocks::BREWING_STAND());
	}

	private function bridge(StructureCanvas $c, int $x1, int $z1, int $x2, int $z2, int $y) : void{
		$steps = max(abs($x2 - $x1), abs($z2 - $z1));
		if($steps === 0){
			return;
		}
		for($i = 0; $i <= $steps; ++$i){
			$x = (int) round($x1 + ($x2 - $x1) * $i / $steps);
			$z = (int) round($z1 + ($z2 - $z1) * $i / $steps);
			for($dx = 0; $dx <= 1; ++$dx){
				for($dz = 0; $dz <= 1; ++$dz){
					$c->set($x + $dx, $y, $z + $dz, VanillaBlocks::PURPUR_SLAB());
					$c->set($x + $dx, $y + 1, $z + $dz, VanillaBlocks::AIR());
					$c->set($x + $dx, $y + 2, $z + $dz, VanillaBlocks::AIR());
				}
			}
		}
	}

	private function ship(StructureCanvas $c, Random $r, int $x, int $y, int $z) : void{
		$purpur = VanillaBlocks::PURPUR();
		$air = VanillaBlocks::AIR();
		for($dx = -7; $dx <= 7; ++$dx){
			$half = $dx > 4 ? 7 - $dx : ($dx < -5 ? 1 : 2);
			for($dz = -$half; $dz <= $half; ++$dz){
				$c->set($x + $dx, $y - 1, $z + $dz, $purpur);
				$edge = abs($dz) === $half;
				$c->set($x + $dx, $y, $z + $dz, $edge ? $purpur : VanillaBlocks::END_STONE_BRICKS());
				if($edge){
					$c->set($x + $dx, $y + 1, $z + $dz, VanillaBlocks::PURPUR_SLAB());
				}else{
					$c->set($x + $dx, $y + 1, $z + $dz, $air);
				}
				$c->set($x + $dx, $y - 2, $z + $dz, $dz === 0 ? $purpur : $air);
			}
		}
		//mast and sail
		$c->fill($x - 1, $y + 1, $z, $x - 1, $y + 9, $z, VanillaBlocks::PURPUR_PILLAR());
		$c->fill($x - 1, $y + 4, $z - 2, $x - 1, $y + 8, $z - 1, VanillaBlocks::WOOL()->setColor(DyeColor::MAGENTA));
		$c->fill($x - 1, $y + 4, $z + 1, $x - 1, $y + 8, $z + 2, VanillaBlocks::WOOL()->setColor(DyeColor::MAGENTA));
		//treasure and the dragon head on the bow
		$c->set($x + 2, $y + 1, $z - 1, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("end_city_treasure", $r));
		$c->set($x + 2, $y + 1, $z + 1, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), LootTables::chestNbt("end_city_treasure", $r));
		$c->set($x + 7, $y + 1, $z, VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::DRAGON)->setFacing(Facing::EAST));
		$c->set($x + 4, $y + 2, $z, VanillaBlocks::ITEM_FRAME()->setFacing(Facing::WEST));
	}
}
