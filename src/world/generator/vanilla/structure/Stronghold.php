<?php

/*
 * BetterPMMP - strongholds with end portal rooms.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\utils\SlabType;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use function cos;
use function sin;
use const M_PI;

/**
 * Stronghold: a network of stone brick rooms - spiral staircase start,
 * corridors, turns, crossing rooms with fountains, prison cells, chest
 * corridors, libraries, descending stairs and exactly one end portal room
 * (12 frames, ~10% pre-filled eyes, lava pool, silverfish spawner).
 * Placement follows vanilla's concentric rings (3, 6, 10, ... per ring).
 */
final class Stronghold extends Structure{

	private const RINGS = [3, 6, 10, 15, 21, 28, 36, 9];
	private const MAX_PIECES = 45;

	/** @var array<int, list<int[]>> seed => list of [chunkX, chunkZ] */
	private static array $positions = [];

	public function getName() : string{ return "stronghold"; }
	protected function getSpacing() : int{ return 1; }
	protected function getSeparation() : int{ return 0; }
	protected function getSalt() : int{ return 0x53747267; }
	public function getMaxRadiusChunks() : int{ return 7; }
	public function isSurface() : bool{ return false; }

	/** @return list<int[]> */
	public static function positions(int $seed) : array{
		if(isset(self::$positions[$seed])){
			return self::$positions[$seed];
		}
		$r = new Random($seed ^ 0x5374726F);
		$list = [];
		$angle = $r->nextFloat() * M_PI * 2;
		foreach(self::RINGS as $ring => $count){
			$baseDistance = 88 + $ring * 192; //chunks: ring 0 at ~1408-2688 blocks
			for($i = 0; $i < $count; ++$i){
				$distance = $baseDistance + $r->nextBoundedInt(80);
				$list[] = [(int) (cos($angle) * $distance), (int) (sin($angle) * $distance)];
				$angle += M_PI * 2 / $count;
			}
			$angle += $r->nextFloat() * M_PI * 2 / $count;
		}
		return self::$positions[$seed] = $list;
	}

	public function startChunksNear(int $seed, int $chunkX, int $chunkZ) : array{
		$result = [];
		$radius = $this->getMaxRadiusChunks();
		foreach(self::positions($seed) as [$cx, $cz]){
			if(abs($cx - $chunkX) <= $radius && abs($cz - $chunkZ) <= $radius){
				$result[] = [$cx, $cz];
			}
		}
		return $result;
	}

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, false);
		$x = ($chunkX << 4) + 2;
		$z = ($chunkZ << 4) + 2;
		$surface = $ctx->heightAt($x, $z);
		$y = max(-30, min($surface - 40, 10 + $random->nextBoundedInt(30)));
		$face = Facing::HORIZONTAL[$random->nextBoundedInt(4)];

		$queue = [];
		$this->room($start, "start", $x, $y, $z, $face, $random, $queue);
		$portal = false;
		$guard = 0;
		while(count($queue) > 0 && count($start->pieces) < self::MAX_PIECES && $guard++ < 400){
			$index = $random->nextBoundedInt(count($queue));
			[$ex, $ey, $ez, $eface, $depth] = $queue[$index];
			array_splice($queue, $index, 1);
			if($depth > 9 || abs($ex - $x) > 112 || abs($ez - $z) > 112){
				continue;
			}
			$type = $this->pickType($random, $portal, count($start->pieces));
			if($this->room($start, $type, $ex, $ey, $ez, $eface, $random, $queue, $depth + 1) && $type === "portal"){
				$portal = true;
			}
		}
		//make sure the portal room exists: attach it to any free exit left over
		if(!$portal){
			foreach($queue as [$ex, $ey, $ez, $eface]){
				if($this->room($start, "portal", $ex, $ey, $ez, $eface, $random, $unused)){
					$portal = true;
					break;
				}
			}
		}
		if(!$portal){
			//last resort: a free spot next to the start, one level deeper if needed
			foreach([0, -8, -16] as $dy){
				foreach(Facing::HORIZONTAL as $f){
					[$ox, , $oz] = Facing::OFFSET[$f];
					for($dist = 6; $dist <= 30 && !$portal; $dist += 4){
						$unused = [];
						if($this->room($start, "portal", $x + $ox * $dist, $y + $dy, $z + $oz * $dist, $f, $random, $unused, 1)){
							$portal = true;
							if($dy !== 0){
								//connecting stair shaft down from the start room
								$this->room($start, "stairs", $x + $ox * 3, $y, $z + $oz * 3, $f, $random, $unused, 1, true);
							}
						}
					}
					if($portal){
						break 2;
					}
				}
			}
		}
		return $start;
	}

	private function pickType(Random $r, bool $portalPlaced, int $count) : string{
		if(!$portalPlaced && $count > 6 && $r->nextBoundedInt(4) === 0){
			return "portal";
		}
		$roll = $r->nextBoundedInt(100);
		return match(true){
			$roll < 30 => "corridor",
			$roll < 42 => "turn",
			$roll < 54 => "crossing",
			$roll < 64 => "stairs",
			$roll < 74 => "prison",
			$roll < 84 => "chest",
			$roll < 92 => "five_way",
			default => "library",
		};
	}

	/**
	 * Room sizes: [width (sideways), height, length (forward), exits, bottom y offset]
	 * exits: list of [lateral offset, y offset, forward offset, facing turn: 0=forward, 1=right, -1=left]
	 * @return array{int, int, int, list<array{int, int, int, int}>, int}
	 */
	private static function shape(string $type, Random $r) : array{
		return match($type){
			"start" => [5, 5, 5, [[0, 0, 5, 0]], 0],
			"corridor" => [5, 5, 7, [[0, 0, 7, 0]], 0],
			"chest" => [5, 5, 7, [[0, 0, 7, 0]], 0],
			"turn" => [5, 5, 5, [$r->nextBoolean() ? [3, 0, 1, 1] : [-3, 0, 1, -1]], 0],
			"stairs" => [5, 10, 9, [[0, -5, 9, 0]], -5],
			"crossing" => [11, 7, 11, [[0, 0, 11, 0], [6, 0, 4, 1], [-6, 0, 4, -1]], 0],
			"five_way" => [10, 9, 11, [[0, 0, 11, 0], [5, 0, 2, 1], [-6, 0, 2, -1], [5, 4, 7, 1], [-6, 4, 7, -1]], 0],
			"prison" => [9, 5, 11, [[0, 0, 11, 0]], 0],
			"library" => [14, 11, 15, [], 0],
			"portal" => [11, 8, 16, [], 0],
			default => [5, 5, 5, [], 0],
		};
	}

	/**
	 * Adds a room whose entrance is centred at (x, y, z) on its back wall,
	 * extending in $face. Returns false if it would overlap other rooms.
	 * @param list<array{int, int, int, int, int}> $queue
	 */
	private function room(StructureStart $start, string $type, int $x, int $y, int $z, int $face, Random $random, ?array &$queue, int $depth = 0, bool $force = false) : bool{
		[$width, $height, $length, $exits, $bottom] = self::shape($type, $random);
		$right = Facing::rotateY($face, true);
		[$fx, , $fz] = Facing::OFFSET[$face];
		[$rx, , $rz] = Facing::OFFSET[$right];
		$half = intdiv($width, 2);
		$corners = [];
		foreach([-$half, $width - 1 - $half] as $lx){
			foreach([0, $length - 1] as $lz){
				$corners[] = [$x + $rx * $lx + $fx * $lz, $z + $rz * $lx + $fz * $lz];
			}
		}
		$minX = min(array_column($corners, 0));
		$maxX = max(array_column($corners, 0));
		$minZ = min(array_column($corners, 1));
		$maxZ = max(array_column($corners, 1));
		$seed = $random->nextInt();
		$drawer = function(StructureCanvas $c, Random $r) use ($type, $x, $y, $z, $fx, $fz, $rx, $rz, $width, $height, $length, $half, $face, $exits, $bottom) : void{
			$t = fn(int $lx, int $ly, int $lz) : array => [$x + $rx * $lx + $fx * $lz, $y + $ly, $z + $rz * $lx + $fz * $lz];
			$set = function(int $lx, int $ly, int $lz, Block $b, $tile = null) use ($c, $t) : void{
				[$wx, $wy, $wz] = $t($lx, $ly, $lz);
				$c->set($wx, $wy, $wz, $b, $tile);
			};
			$brick = function() use ($r) : Block{
				$roll = $r->nextBoundedInt(10);
				return $roll < 6 ? VanillaBlocks::STONE_BRICKS() : ($roll < 8 ? VanillaBlocks::MOSSY_STONE_BRICKS() : VanillaBlocks::CRACKED_STONE_BRICKS());
			};
			$air = VanillaBlocks::AIR();
			//shell
			for($lx = -$half; $lx < $width - $half; ++$lx){
				for($lz = 0; $lz < $length; ++$lz){
					for($ly = $bottom; $ly < $bottom + $height; ++$ly){
						$edge = $lx === -$half || $lx === $width - 1 - $half || $lz === 0 || $lz === $length - 1 || $ly === $bottom || $ly === $bottom + $height - 1;
						$set($lx, $ly, $lz, $edge ? $brick() : $air);
					}
				}
			}
			//entrance doorway
			if($type !== "start"){
				$door = $r->nextBoundedInt(4);
				for($lx = -1; $lx <= 1; ++$lx){
					for($ly = 1; $ly <= 3; ++$ly){
						$set($lx, $ly, 0, $air);
					}
				}
				if($door === 1){
					$set(0, 1, 0, VanillaBlocks::OAK_DOOR()->setFacing($face));
					$set(0, 2, 0, VanillaBlocks::OAK_DOOR()->setFacing($face)->setTop(true));
					$set(-1, 1, 0, $brick());
					$set(1, 1, 0, $brick());
					$set(-1, 2, 0, $brick());
					$set(1, 2, 0, $brick());
					$set(-1, 3, 0, $brick());
					$set(0, 3, 0, $brick());
					$set(1, 3, 0, $brick());
				}elseif($door === 2){
					for($lx = -1; $lx <= 1; ++$lx){
						for($ly = 1; $ly <= 3; ++$ly){
							$set($lx, $ly, 0, VanillaBlocks::IRON_BARS());
						}
					}
					$set(0, 1, 0, $air);
					$set(0, 2, 0, $air);
				}
			}
			//exits
			foreach($exits as [$ex, $ey, $ez, $turn]){
				$wallLx = $turn === 0 ? $ex : ($turn > 0 ? $width - 1 - $half : -$half);
				$wallLz = $turn === 0 ? $length - 1 : $ez;
				for($d = -1; $d <= 1; ++$d){
					for($ly = 1; $ly <= 3; ++$ly){
						if($turn === 0){
							$set($wallLx + $d, $ey + $ly, $wallLz, $air);
						}else{
							$set($wallLx, $ey + $ly, $wallLz + $d, $air);
						}
					}
				}
			}
			$this->decorate($type, $set, $t, $c, $r, $width, $height, $length, $half, $face);
		};
		$piece = new StructurePiece($minX, $y + $bottom, $minZ, $maxX, $y + $bottom + $height - 1, $maxZ, $drawer, $seed);
		if(!$force && $start->collides($piece, -1)){
			return false;
		}
		$start->add($piece);
		if($queue !== null){
			foreach($exits as [$ex, $ey, $ez, $turn]){
				$nextFace = $turn === 0 ? $face : Facing::rotateY($face, $turn > 0);
				if($turn === 0){
					$lx = $ex;
					$lz = $length;
				}else{
					$lx = $turn > 0 ? $width - $half : -$half - 1;
					$lz = $ez;
				}
				$queue[] = [$x + $rx * $lx + $fx * $lz, $y + $ey, $z + $rz * $lx + $fz * $lz, $nextFace, $depth];
			}
		}
		return true;
	}

	private function decorate(string $type, \Closure $set, \Closure $t, StructureCanvas $c, Random $r, int $width, int $height, int $length, int $half, int $face) : void{
		$air = VanillaBlocks::AIR();
		switch($type){
			case "stairs":
				//descending stair steps from the entrance level down to the exit level
				$stairs = VanillaBlocks::STONE_BRICK_STAIRS()->setFacing(Facing::opposite($face));
				for($i = 0; $i < 6; ++$i){
					$lz = 1 + $i;
					for($lx = -1; $lx <= 1; ++$lx){
						$set($lx, -$i, $lz, $i === 0 ? VanillaBlocks::STONE_BRICKS() : $stairs);
						for($ly = -4; $ly < -$i; ++$ly){
							$set($lx, $ly, $lz, VanillaBlocks::STONE_BRICKS());
						}
					}
				}
				break;
			case "start":
				$set(0, 3, 2, VanillaBlocks::TORCH()->setFacing(Facing::opposite($face)));
				break;
			case "chest":
				$set($half - 1, 1, intdiv($length, 2), VanillaBlocks::CHEST(), LootTables::chestNbt("stronghold_corridor", $r));
				$set(-$half + 1, 1, intdiv($length, 2), VanillaBlocks::STONE_BRICK_SLAB());
				break;
			case "corridor":
				if($r->nextBoundedInt(3) === 0){
					$set(-$half + 1, 2, 3, VanillaBlocks::TORCH()->setFacing(Facing::rotateY($face, true)));
				}
				break;
			case "crossing":
				$mid = intdiv($length, 2);
				if($r->nextBoolean()){
					//fountain
					for($lx = -1; $lx <= 1; ++$lx){
						for($lz = $mid - 1; $lz <= $mid + 1; ++$lz){
							$set($lx, 1, $lz, VanillaBlocks::STONE_BRICKS());
						}
					}
					$set(0, 2, $mid, VanillaBlocks::WATER());
					$set(0, 1, $mid, VanillaBlocks::STONE_BRICKS());
				}else{
					for($ly = 1; $ly < $height - 1; ++$ly){
						$set(0, $ly, $mid, VanillaBlocks::STONE_BRICKS());
					}
					$set(1, 3, $mid, VanillaBlocks::TORCH()->setFacing(Facing::rotateY($face, true)));
				}
				if($r->nextBoundedInt(3) === 0){
					$set(-$half + 1, 1, 2, VanillaBlocks::CHEST(), LootTables::chestNbt("stronghold_crossing", $r));
				}
				break;
			case "prison":
				//cells on both sides behind iron bars
				for($cell = 0; $cell < 3; ++$cell){
					$lz = 2 + $cell * 3;
					foreach([-1, 1] as $side){
						$lx = $side * 2;
						for($ly = 1; $ly <= 3; ++$ly){
							$set($lx, $ly, $lz, VanillaBlocks::IRON_BARS());
							$set($lx, $ly, $lz + 1, VanillaBlocks::IRON_BARS());
							$set($lx + $side, $ly, $lz + 2, VanillaBlocks::STONE_BRICKS());
							$set($lx + $side * 2, $ly, $lz + 2, VanillaBlocks::STONE_BRICKS());
						}
						$set($lx, 1, $lz + 2, VanillaBlocks::IRON_DOOR()->setFacing($face));
						$set($lx, 2, $lz + 2, VanillaBlocks::IRON_DOOR()->setFacing($face)->setTop(true));
					}
				}
				break;
			case "library":
				$shelf = VanillaBlocks::BOOKSHELF();
				$planks = VanillaBlocks::OAK_PLANKS();
				$web = VanillaBlocks::COBWEB();
				for($lz = 1; $lz < $length - 1; ++$lz){
					for($ly = 1; $ly <= 3; ++$ly){
						$set(-$half + 1, $ly, $lz, $lz % 4 === 0 ? VanillaBlocks::OAK_LOG() : $shelf);
						$set($width - 2 - $half, $ly, $lz, $lz % 4 === 0 ? VanillaBlocks::OAK_LOG() : $shelf);
						//upper gallery shelves
						$set(-$half + 1, $ly + 5, $lz, $shelf);
						$set($width - 2 - $half, $ly + 5, $lz, $shelf);
					}
					//gallery floor
					$set(-$half + 2, 5, $lz, $planks);
					$set($width - 3 - $half, 5, $lz, $planks);
				}
				//rows of shelves
				for($lx = -$half + 3; $lx < $width - 3 - $half; $lx += 3){
					for($lz = 3; $lz < $length - 3; ++$lz){
						for($ly = 1; $ly <= 3; ++$ly){
							$set($lx, $ly, $lz, $shelf);
						}
					}
				}
				for($i = 0; $i < 15; ++$i){
					$set($r->nextRange(-$half + 1, $width - 2 - $half), $r->nextRange(1, $height - 2), $r->nextRange(1, $length - 2), $web);
				}
				$set(-$half + 2, 1, $length - 2, VanillaBlocks::CHEST(), LootTables::chestNbt("stronghold_library", $r));
				$set($width - 3 - $half, 6, 2, VanillaBlocks::CHEST(), LootTables::chestNbt("stronghold_library", $r));
				$set(0, $height - 2, intdiv($length, 2), VanillaBlocks::OAK_FENCE());
				break;
			case "portal":
				$mid = 0;
				//lava pool at the entrance end
				for($lx = -3; $lx <= 3; ++$lx){
					for($lz = 2; $lz <= 5; ++$lz){
						$set($lx, 1, $lz, VanillaBlocks::LAVA());
					}
				}
				//stairs up to the frame platform
				for($lz = 6; $lz <= 8; ++$lz){
					for($lx = -1; $lx <= 1; ++$lx){
						$set($lx, 1, $lz, VanillaBlocks::STONE_BRICKS());
						$set($lx, 2, $lz, $lz === 6 ? VanillaBlocks::STONE_BRICK_STAIRS()->setFacing($face) : VanillaBlocks::STONE_BRICKS());
					}
				}
				//platform and ring of 12 end portal frames (5x5 ring without corners)
				$centerZ = 11;
				for($lx = -3; $lx <= 3; ++$lx){
					for($lz = $centerZ - 3; $lz <= $centerZ + 3; ++$lz){
						$set($lx, 1, $lz, VanillaBlocks::STONE_BRICKS());
						$set($lx, 2, $lz, VanillaBlocks::STONE_BRICKS());
					}
				}
				for($lx = -1; $lx <= 1; ++$lx){
					for($lz = $centerZ - 1; $lz <= $centerZ + 1; ++$lz){
						$set($lx, 2, $lz, VanillaBlocks::LAVA());
					}
				}
				$back = Facing::opposite($face);
				$right = Facing::rotateY($face, true);
				$left = Facing::opposite($right);
				for($i = -1; $i <= 1; ++$i){
					//frames face the centre of the ring
					$set($i, 3, $centerZ - 2, VanillaBlocks::END_PORTAL_FRAME()->setFacing($face)->setEye($r->nextFloat() < 0.1));
					$set($i, 3, $centerZ + 2, VanillaBlocks::END_PORTAL_FRAME()->setFacing($back)->setEye($r->nextFloat() < 0.1));
					$set(-2, 3, $centerZ + $i, VanillaBlocks::END_PORTAL_FRAME()->setFacing($right)->setEye($r->nextFloat() < 0.1));
					$set(2, 3, $centerZ + $i, VanillaBlocks::END_PORTAL_FRAME()->setFacing($left)->setEye($r->nextFloat() < 0.1));
				}
				for($lx = -1; $lx <= 1; ++$lx){
					for($lz = $centerZ - 1; $lz <= $centerZ + 1; ++$lz){
						$set($lx, 3, $lz, $air);
					}
				}
				//silverfish spawner on the stairs
				$set(0, 3, 7, VanillaBlocks::MONSTER_SPAWNER(), LootTables::spawnerNbt("minecraft:silverfish"));
				//iron bar windows
				for($lz = 3; $lz < $length - 2; $lz += 4){
					$set(-$half, 3, $lz, VanillaBlocks::IRON_BARS());
					$set($width - 1 - $half, 3, $lz, VanillaBlocks::IRON_BARS());
				}
				break;
		}
	}
}
