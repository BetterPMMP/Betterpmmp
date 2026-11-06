<?php

/*
 * BetterPMMP - abandoned mineshafts.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\data\bedrock\block\BlockLegacyMetadata;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Abandoned mineshaft: a dirt-floored room with corridor networks branching
 * out (supports, rails, cobwebs, chests, cave spider spawners), crossings and
 * stairs, up to ~80 blocks from the start. Badlands use dark oak wood.
 * Vanilla gives every chunk a 0.4% chance to start one.
 */
final class Mineshaft extends Structure{

	private const CHANCE = 0.004;
	private const MAX_DEPTH = 8;
	private const MAX_EXTENT = 80;

	public function getName() : string{ return "mineshaft"; }
	protected function getSpacing() : int{ return 1; }
	protected function getSeparation() : int{ return 0; }
	protected function getSalt() : int{ return 0x4D696E65; }
	public function getMaxRadiusChunks() : int{ return 6; }
	public function isSurface() : bool{ return false; }

	public function startChunksNear(int $seed, int $chunkX, int $chunkZ) : array{
		$result = [];
		$radius = $this->getMaxRadiusChunks();
		for($cx = $chunkX - $radius; $cx <= $chunkX + $radius; ++$cx){
			for($cz = $chunkZ - $radius; $cz <= $chunkZ + $radius; ++$cz){
				$r = new Random(($seed ^ ($cx * 0x2E5BF271) ^ ($cz * 0x1B3A5C9D) ^ $this->getSalt()) & 0x7fffffffffff);
				if($r->nextFloat() < self::CHANCE){
					$result[] = [$cx, $cz];
				}
			}
		}
		return $result;
	}

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$x = ($chunkX << 4) + 2;
		$z = ($chunkZ << 4) + 2;
		$surface = $ctx->heightAt($x, $z);
		$biome = $ctx->biomeAt($x, 64, $z);
		$mesa = $biome === B::MESA || $biome === B::MESA_BRYCE || $biome === B::MESA_PLATEAU_STONE;
		if($biome === B::DEEP_OCEAN || $biome === B::DEEP_COLD_OCEAN || $biome === B::DEEP_FROZEN_OCEAN || $biome === B::DEEP_LUKEWARM_OCEAN){
			return null;
		}
		$y = $mesa ? min($surface - 12, 40 + $random->nextBoundedInt(30)) : -40 + $random->nextBoundedInt(min(70, max(10, $surface - 50)));
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, false);
		$wood = $mesa ? [VanillaBlocks::DARK_OAK_PLANKS(), VanillaBlocks::DARK_OAK_FENCE()] : [VanillaBlocks::OAK_PLANKS(), VanillaBlocks::OAK_FENCE()];

		//central room
		$w = 7 + $random->nextBoundedInt(6);
		$d = 7 + $random->nextBoundedInt(6);
		$h = 4 + $random->nextBoundedInt(3);
		$room = $start->add(new StructurePiece($x, $y, $z, $x + $w, $y + $h, $z + $d, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $w, $h, $d) : void{
			$c->fill($x, $y + 1, $z, $x + $w, $y + $h, $z + $d, VanillaBlocks::AIR());
			$c->fill($x, $y, $z, $x + $w, $y, $z + $d, VanillaBlocks::DIRT());
		}));
		//corridors leave from every side of the room
		foreach(Facing::HORIZONTAL as $face){
			for($i = 0, $n = 1 + $random->nextBoundedInt(2); $i < $n; ++$i){
				[$ox, , $oz] = Facing::OFFSET[$face];
				$along = $random->nextBoundedInt(max(1, ($ox !== 0 ? $d : $w) - 3));
				$sx = $ox > 0 ? $x + $w + 1 : ($ox < 0 ? $x - 1 : $x + $along);
				$sz = $oz > 0 ? $z + $d + 1 : ($oz < 0 ? $z - 1 : $z + $along);
				$this->corridor($start, $sx, $y + 1, $sz, $face, 1, $random, $x, $z, $wood);
			}
		}
		unset($room);
		return $start;
	}

	/**
	 * @param Block[] $wood [planks, fence]
	 */
	private function corridor(StructureStart $start, int $x, int $y, int $z, int $face, int $depth, Random $random, int $originX, int $originZ, array $wood) : void{
		if($depth > self::MAX_DEPTH || abs($x - $originX) > self::MAX_EXTENT || abs($z - $originZ) > self::MAX_EXTENT || $y < -58){
			return;
		}
		[$ox, , $oz] = Facing::OFFSET[$face];
		$sections = 2 + $random->nextBoundedInt(3);
		$length = $sections * 5;
		//corridor is 3 wide, 3 high; (x,y,z) is the centre of its first floor block
		$ex = $x + $ox * ($length - 1);
		$ez = $z + $oz * ($length - 1);
		$minX = min($x, $ex) - ($oz !== 0 ? 1 : 0);
		$maxX = max($x, $ex) + ($oz !== 0 ? 1 : 0);
		$minZ = min($z, $ez) - ($ox !== 0 ? 1 : 0);
		$maxZ = max($z, $ez) + ($ox !== 0 ? 1 : 0);
		$hasSpider = $random->nextBoundedInt(23) === 0;
		$piece = new StructurePiece($minX, $y - 1, $minZ, $maxX, $y + 2, $maxZ, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $ox, $oz, $length, $minX, $minZ, $maxX, $maxZ, $hasSpider, $wood) : void{
			[$planks, $fence] = $wood;
			$air = VanillaBlocks::AIR();
			$c->fill($minX, $y, $minZ, $maxX, $y + 2, $maxZ, $air);
			$rail = VanillaBlocks::RAIL()->setShape($ox !== 0 ? BlockLegacyMetadata::RAIL_STRAIGHT_EAST_WEST : BlockLegacyMetadata::RAIL_STRAIGHT_NORTH_SOUTH);
			$web = VanillaBlocks::COBWEB();
			for($i = 0; $i < $length; ++$i){
				$px = $x + $ox * $i;
				$pz = $z + $oz * $i;
				//bridge over caves
				for($w = -1; $w <= 1; ++$w){
					$bx = $px + ($oz !== 0 ? $w : 0);
					$bz = $pz + ($ox !== 0 ? $w : 0);
					if($c->isAir($bx, $y - 1, $bz)){
						$c->set($bx, $y - 1, $bz, $planks);
					}
				}
				if($r->nextBoundedInt(10) < 7){
					$c->set($px, $y, $pz, $rail);
				}
				if($i % 5 === 2){
					//support: two fence posts and a plank beam
					for($w = -1; $w <= 1; $w += 2){
						$bx = $px + ($oz !== 0 ? $w : 0);
						$bz = $pz + ($ox !== 0 ? $w : 0);
						$c->set($bx, $y, $bz, $fence);
						$c->set($bx, $y + 1, $bz, $fence);
					}
					for($w = -1; $w <= 1; ++$w){
						$c->set($px + ($oz !== 0 ? $w : 0), $y + 2, $pz + ($ox !== 0 ? $w : 0), $planks);
					}
					if($r->nextBoundedInt(4) === 0){
						$c->set($px + ($oz !== 0 ? 1 : 0), $y + 1, $pz + ($ox !== 0 ? 1 : 0), VanillaBlocks::TORCH()->setFacing($oz !== 0 ? Facing::WEST : Facing::NORTH));
					}
				}
				if($r->nextBoundedInt(12) === 0){
					$wx = $px + ($oz !== 0 ? $r->nextRange(-1, 1) : 0);
					$wz = $pz + ($ox !== 0 ? $r->nextRange(-1, 1) : 0);
					$c->set($wx, $y + 2, $wz, $web);
				}
				if($r->nextBoundedInt(100) === 0){
					$cx = $px + ($oz !== 0 ? ($r->nextBoolean() ? 1 : -1) : 0);
					$cz = $pz + ($ox !== 0 ? ($r->nextBoolean() ? 1 : -1) : 0);
					$c->set($cx, $y, $cz, VanillaBlocks::CHEST(), LootTables::chestNbt("abandoned_mineshaft", $r));
				}
			}
			if($hasSpider){
				$sx = $x + $ox * 7;
				$sz = $z + $oz * 7;
				$c->set($sx, $y, $sz, VanillaBlocks::MONSTER_SPAWNER(), LootTables::spawnerNbt("minecraft:cave_spider"));
				for($i = 0; $i < 30; ++$i){
					$wx = $sx + $r->nextRange(-2, 2);
					$wy = $y + $r->nextBoundedInt(3);
					$wz = $sz + $r->nextRange(-2, 2);
					if($c->isAir($wx, $wy, $wz)){
						$c->set($wx, $wy, $wz, $web);
					}
				}
			}
		}, $random->nextInt());
		if($start->collides($piece, -1)){
			return;
		}
		$start->add($piece);

		//what comes next
		$nx = $x + $ox * $length;
		$nz = $z + $oz * $length;
		$roll = $random->nextBoundedInt(10);
		if($roll < 2){
			$this->crossing($start, $nx, $y, $nz, $face, $depth + 1, $random, $originX, $originZ, $wood);
		}elseif($roll < 4){
			$this->stairs($start, $nx, $y, $nz, $face, $depth + 1, $random, $originX, $originZ, $wood, $random->nextBoolean());
		}else{
			$this->corridor($start, $nx, $y, $nz, $face, $depth + 1, $random, $originX, $originZ, $wood);
		}
		//side branches
		if($random->nextBoundedInt(3) === 0){
			$side = Facing::rotateY($face, $random->nextBoolean());
			[$sx, , $sz] = Facing::OFFSET[$side];
			$off = 1 + $random->nextBoundedInt(max(1, $length - 2));
			$this->corridor($start, $x + $ox * $off + $sx * 2, $y, $z + $oz * $off + $sz * 2, $side, $depth + 2, $random, $originX, $originZ, $wood);
		}
	}

	/** @param Block[] $wood */
	private function crossing(StructureStart $start, int $x, int $y, int $z, int $face, int $depth, Random $random, int $originX, int $originZ, array $wood) : void{
		[$ox, , $oz] = Facing::OFFSET[$face];
		$cx = $x + $ox * 2;
		$cz = $z + $oz * 2;
		$tall = $random->nextBoundedInt(4) === 0;
		$top = $y + ($tall ? 6 : 2);
		$piece = new StructurePiece($cx - 2, $y - 1, $cz - 2, $cx + 2, $top, $cz + 2, function(StructureCanvas $c, Random $r) use ($cx, $cz, $y, $top, $wood) : void{
			$c->fill($cx - 2, $y, $cz - 1, $cx + 2, $top, $cz + 1, VanillaBlocks::AIR());
			$c->fill($cx - 1, $y, $cz - 2, $cx + 1, $top, $cz + 2, VanillaBlocks::AIR());
			foreach([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dx, $dz]){
				$c->fill($cx + $dx, $y, $cz + $dz, $cx + $dx, $top, $cz + $dz, $wood[0]);
			}
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					if($c->isAir($cx + $dx, $y - 1, $cz + $dz)){
						$c->set($cx + $dx, $y - 1, $cz + $dz, $wood[0]);
					}
				}
			}
		}, $random->nextInt());
		if($start->collides($piece, -1)){
			return;
		}
		$start->add($piece);
		foreach(Facing::HORIZONTAL as $next){
			if($next === Facing::opposite($face) || $random->nextBoundedInt(4) === 0){
				continue;
			}
			[$nx, , $nz] = Facing::OFFSET[$next];
			$this->corridor($start, $cx + $nx * 3, $y, $cz + $nz * 3, $next, $depth, $random, $originX, $originZ, $wood);
		}
	}

	/** @param Block[] $wood */
	private function stairs(StructureStart $start, int $x, int $y, int $z, int $face, int $depth, Random $random, int $originX, int $originZ, array $wood, bool $down) : void{
		[$ox, , $oz] = Facing::OFFSET[$face];
		$steps = 5;
		$dy = $down ? -1 : 1;
		$ex = $x + $ox * ($steps + 1);
		$ez = $z + $oz * ($steps + 1);
		$ey = $y + $dy * $steps;
		$minX = min($x, $ex) - ($oz !== 0 ? 1 : 0);
		$maxX = max($x, $ex) + ($oz !== 0 ? 1 : 0);
		$minZ = min($z, $ez) - ($ox !== 0 ? 1 : 0);
		$maxZ = max($z, $ez) + ($ox !== 0 ? 1 : 0);
		$piece = new StructurePiece($minX, min($y, $ey) - 1, $minZ, $maxX, max($y, $ey) + 3, $maxZ, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $ox, $oz, $steps, $dy) : void{
			for($i = 0; $i <= $steps + 1; ++$i){
				$level = $y + $dy * min($i, $steps);
				for($w = -1; $w <= 1; ++$w){
					$px = $x + $ox * $i + ($oz !== 0 ? $w : 0);
					$pz = $z + $oz * $i + ($ox !== 0 ? $w : 0);
					for($h = 0; $h <= 3; ++$h){
						$c->set($px, $level + $h, $pz, VanillaBlocks::AIR());
					}
				}
			}
		}, $random->nextInt());
		if($start->collides($piece, -1)){
			return;
		}
		$start->add($piece);
		$this->corridor($start, $ex + $ox, $ey, $ez + $oz, $face, $depth, $random, $originX, $originZ, $wood);
	}
}
