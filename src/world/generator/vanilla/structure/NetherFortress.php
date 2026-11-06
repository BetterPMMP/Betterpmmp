<?php

/*
 * BetterPMMP - nether fortress.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Nether fortress: nether brick bridges on pillars over the lava sea,
 * crossings, enclosed corridors with fence windows and loot chests, blaze
 * spawner platforms and nether wart rooms. Shares the nether complex grid
 * (spacing 27, separation 4) with bastions; 2 of 5 complexes are fortresses.
 */
final class NetherFortress extends Structure{

	private const MAX_PIECES = 60;

	public function getName() : string{ return "fortress"; }
	protected function getSpacing() : int{ return 27; }
	protected function getSeparation() : int{ return 4; }
	protected function getSalt() : int{ return 30084232; }
	public function getMaxRadiusChunks() : int{ return 8; }
	public function isSurface() : bool{ return false; }

	public static function isFortress(int $seed, int $chunkX, int $chunkZ) : bool{
		$r = new Random(($seed ^ ($chunkX * 0x4F1BBCDD) ^ ($chunkZ * 0x2545F491) ^ 0x4E657468) & 0x7fffffffffff);
		return $r->nextBoundedInt(5) < 2;
	}

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		if(!self::isFortress($ctx->seed, $chunkX, $chunkZ)){
			return null;
		}
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, false);
		$x = ($chunkX << 4) + 2;
		$z = ($chunkZ << 4) + 2;
		$y = 48 + $random->nextBoundedInt(16);
		$queue = [];
		$this->piece($start, "crossing", $x, $y, $z, Facing::SOUTH, $random, $queue, 0);
		$guard = 0;
		while(count($queue) > 0 && count($start->pieces) < self::MAX_PIECES && $guard++ < 300){
			$i = $random->nextBoundedInt(count($queue));
			[$px, $py, $pz, $face, $depth] = $queue[$i];
			array_splice($queue, $i, 1);
			if($depth > 10 || abs($px - $x) > 112 || abs($pz - $z) > 112){
				continue;
			}
			$roll = $random->nextBoundedInt(100);
			$type = match(true){
				$depth < 4 && $roll < 45 => "bridge",
				$roll < 25 => "bridge",
				$roll < 40 => "crossing",
				$roll < 65 => "corridor",
				$roll < 75 => "blaze",
				$roll < 82 => "wart",
				default => "corridor_turn",
			};
			$this->piece($start, $type, $px, $py, $pz, $face, $random, $queue, $depth + 1);
		}
		return $start;
	}

	/**
	 * @return array{int, int, int, list<array{int, int, int}>} width, height, length, exits [lateral, forward, turn]
	 */
	private static function shape(string $type, Random $r) : array{
		return match($type){
			"bridge" => [5, 10, 19, [[0, 19, 0]]],
			"crossing" => [7, 9, 7, [[0, 7, 0], [4, 3, 1], [-4, 3, -1]]],
			"corridor" => [5, 7, 7, [[0, 7, 0]]],
			"corridor_turn" => [5, 7, 5, [$r->nextBoolean() ? [3, 2, 1] : [-3, 2, -1]]],
			"blaze" => [7, 8, 7, []],
			"wart" => [13, 10, 13, [[0, 13, 0]]],
			default => [5, 5, 5, []],
		};
	}

	/**
	 * @param list<array{int, int, int, int, int}> $queue
	 */
	private function piece(StructureStart $start, string $type, int $x, int $y, int $z, int $face, Random $random, array &$queue, int $depth) : void{
		[$width, $height, $length, $exits] = self::shape($type, $random);
		$right = Facing::rotateY($face, true);
		[$fx, , $fz] = Facing::OFFSET[$face];
		[$rx, , $rz] = Facing::OFFSET[$right];
		$half = intdiv($width, 2);
		$xs = [$x + $rx * -$half, $x + $rx * ($width - 1 - $half) + $fx * ($length - 1)];
		$zs = [$z + $rz * -$half, $z + $rz * ($width - 1 - $half) + $fz * ($length - 1)];
		$xs[] = $x + $rx * ($width - 1 - $half);
		$xs[] = $x + $rx * -$half + $fx * ($length - 1);
		$zs[] = $z + $rz * ($width - 1 - $half);
		$zs[] = $z + $rz * -$half + $fz * ($length - 1);
		$piece = new StructurePiece(min($xs), $y - 40, min($zs), max($xs), $y + $height, max($zs), function(StructureCanvas $c, Random $r) use ($type, $x, $y, $z, $fx, $fz, $rx, $rz, $width, $height, $length, $half, $face, $exits) : void{
			$set = function(int $lx, int $ly, int $lz, Block $b, $tile = null) use ($c, $x, $y, $z, $fx, $fz, $rx, $rz) : void{
				$c->set($x + $rx * $lx + $fx * $lz, $y + $ly, $z + $rz * $lx + $fz * $lz, $b, $tile);
			};
			$pillar = function(int $lx, int $lz) use ($c, $x, $y, $z, $fx, $fz, $rx, $rz) : void{
				$c->foundation($x + $rx * $lx + $fx * $lz, $y, $z + $rz * $lx + $fz * $lz, VanillaBlocks::NETHER_BRICKS(), 40);
			};
			$this->draw($type, $set, $pillar, $r, $width, $height, $length, $half, $face, $exits);
		}, $random->nextInt());
		//all fortress pieces share the same deck level: an XZ overlap test is enough
		foreach($start->pieces as $other){
			if($other->intersectsColumn($piece->minX + 1, $piece->minZ + 1, $piece->maxX - 1, $piece->maxZ - 1)){
				return;
			}
		}
		$start->add($piece);
		foreach($exits as [$ex, $ez, $turn]){
			$next = $turn === 0 ? $face : Facing::rotateY($face, $turn > 0);
			$lx = $turn === 0 ? $ex : ($turn > 0 ? $width - $half : -$half - 1);
			$lz = $turn === 0 ? $length : $ez;
			$queue[] = [$x + $rx * $lx + $fx * $lz, $y, $z + $rz * $lx + $fz * $lz, $next, $depth];
		}
	}

	private function draw(string $type, \Closure $set, \Closure $pillar, Random $r, int $width, int $height, int $length, int $half, int $face, array $exits) : void{
		$bricks = VanillaBlocks::NETHER_BRICKS();
		$fence = VanillaBlocks::NETHER_BRICK_FENCE();
		$air = VanillaBlocks::AIR();
		$minL = -$half;
		$maxL = $width - 1 - $half;
		switch($type){
			case "bridge":
				for($lz = 0; $lz < $length; ++$lz){
					for($lx = $minL; $lx <= $maxL; ++$lx){
						$set($lx, 0, $lz, $bricks);
						for($ly = 1; $ly <= 3; ++$ly){
							$set($lx, $ly, $lz, $air);
						}
					}
					//railings and arches
					$set($minL, 1, $lz, $fence);
					$set($maxL, 1, $lz, $fence);
					if($lz % 6 === 2){
						$pillar($minL + 1, $lz);
						$pillar($maxL - 1, $lz);
						$set($minL + 1, -1, $lz, $bricks);
						$set($maxL - 1, -1, $lz, $bricks);
					}
				}
				break;
			case "crossing":
				for($lz = 0; $lz < $length; ++$lz){
					for($lx = $minL; $lx <= $maxL; ++$lx){
						$set($lx, 0, $lz, $bricks);
						$pillar($lx, $lz);
						for($ly = 1; $ly <= 4; ++$ly){
							$set($lx, $ly, $lz, $air);
						}
						if(($lx === $minL || $lx === $maxL) && ($lz === 0 || $lz === $length - 1)){
							for($ly = 1; $ly <= 4; ++$ly){
								$set($lx, $ly, $lz, $bricks);
							}
						}
					}
				}
				break;
			case "corridor":
			case "corridor_turn":
				for($lz = 0; $lz < $length; ++$lz){
					for($lx = $minL; $lx <= $maxL; ++$lx){
						$edge = $lx === $minL || $lx === $maxL;
						$set($lx, 0, $lz, $bricks);
						$set($lx, 5, $lz, $bricks);
						for($ly = 1; $ly <= 4; ++$ly){
							$window = $edge && $ly === 2 && $lz % 2 === 1;
							$set($lx, $ly, $lz, $edge ? ($window ? $fence : $bricks) : $air);
						}
					}
					$pillar(0, $lz);
				}
				foreach($exits as [$ex, $ez, $turn]){
					if($turn !== 0){
						$wx = $turn > 0 ? $maxL : $minL;
						for($d = -1; $d <= 1; ++$d){
							for($ly = 1; $ly <= 3; ++$ly){
								$set($wx, $ly, $ez + $d, $air);
							}
						}
					}
				}
				if($type === "corridor" && $r->nextBoundedInt(4) === 0){
					$set($maxL - 1, 1, 3, VanillaBlocks::CHEST(), LootTables::chestNbt("nether_bridge", $r));
				}
				break;
			case "blaze":
				for($lz = 0; $lz < $length; ++$lz){
					for($lx = $minL; $lx <= $maxL; ++$lx){
						$set($lx, 0, $lz, $bricks);
						$pillar($lx, $lz);
						for($ly = 1; $ly <= 6; ++$ly){
							$set($lx, $ly, $lz, $air);
						}
						$edge = $lx === $minL || $lx === $maxL || $lz === $length - 1;
						if($edge){
							$set($lx, 1, $lz, $fence);
						}
					}
				}
				//raised spawner platform with stairs
				$set(0, 1, 3, $bricks);
				$set(-1, 1, 3, VanillaBlocks::NETHER_BRICK_STAIRS()->setFacing(Facing::rotateY($face, false)));
				$set(1, 1, 3, VanillaBlocks::NETHER_BRICK_STAIRS()->setFacing(Facing::rotateY($face, true)));
				$set(0, 2, 3, VanillaBlocks::MONSTER_SPAWNER(), LootTables::spawnerNbt("minecraft:blaze"));
				break;
			case "wart":
				for($lz = 0; $lz < $length; ++$lz){
					for($lx = $minL; $lx <= $maxL; ++$lx){
						$edge = $lx === $minL || $lx === $maxL || $lz === 0 || $lz === $length - 1;
						$set($lx, 0, $lz, $bricks);
						$pillar($lx, $lz);
						$set($lx, 7, $lz, $bricks);
						for($ly = 1; $ly <= 6; ++$ly){
							$window = $edge && $ly === 3 && ($lx + $lz) % 2 === 0;
							$set($lx, $ly, $lz, $edge ? ($window ? $fence : $bricks) : $air);
						}
					}
				}
				//doorways front/back
				foreach([0, $length - 1] as $lz){
					for($lx = -1; $lx <= 1; ++$lx){
						for($ly = 1; $ly <= 3; ++$ly){
							$set($lx, $ly, $lz, $air);
						}
					}
				}
				//soul sand beds with nether wart along the side walls
				for($lz = 3; $lz <= $length - 4; ++$lz){
					foreach([$minL + 1, $maxL - 1] as $lx){
						$set($lx, 0, $lz, VanillaBlocks::SOUL_SAND());
						$set($lx, 1, $lz, VanillaBlocks::NETHER_WART()->setAge($r->nextBoundedInt(4)));
					}
				}
				//central stair block
				$set(0, 1, intdiv($length, 2), VanillaBlocks::NETHER_BRICK_STAIRS()->setFacing($face));
				$set(0, 1, intdiv($length, 2) + 1, $bricks);
				$set(0, 2, intdiv($length, 2) + 1, VanillaBlocks::CHEST(), LootTables::chestNbt("nether_bridge", $r));
				break;
		}
	}
}
