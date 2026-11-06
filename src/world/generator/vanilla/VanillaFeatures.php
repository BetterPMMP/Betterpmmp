<?php

/*
 * BetterPMMP - vanilla-style world features (trees, mushrooms, spikes, ...).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\utils\MushroomBlockType;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use function abs;
use function max;

/**
 * Feature placers that PocketMine's tree objects don't cover. All of them
 * write through {@link BlockWriter}, only replace air/plants/leaves, and stay
 * within ~7 blocks of their origin so they fit the population area.
 */
final class VanillaFeatures{

	private static function canReplace(BlockWriter $w, int $x, int $y, int $z) : bool{
		$block = $w->getBlock($x, $y, $z);
		return $block->canBeReplaced() || $block->getTypeId() === BlockTypeIds::AIR || $block instanceof \pocketmine\block\Leaves || $block instanceof \pocketmine\block\Sapling;
	}

	private static function leaf(BlockWriter $w, int $x, int $y, int $z, Block $leaves) : void{
		if(self::canReplace($w, $x, $y, $z)){
			$w->setBlock($x, $y, $z, $leaves);
		}
	}

	private static function log(BlockWriter $w, int $x, int $y, int $z, Block $log) : void{
		if(self::canReplace($w, $x, $y, $z)){
			$w->setBlock($x, $y, $z, $log);
		}
	}

	private static function hasSpace(BlockWriter $w, int $x, int $y, int $z, int $height, int $radius) : bool{
		for($yy = 1; $yy <= $height; ++$yy){
			for($xx = -$radius; $xx <= $radius; ++$xx){
				for($zz = -$radius; $zz <= $radius; ++$zz){
					if(!self::canReplace($w, $x + $xx, $y + $yy, $z + $zz)){
						return false;
					}
				}
			}
		}
		return true;
	}

	/** Roofed (dark) oak: 2x2 trunk with a wide, flat canopy. */
	public static function darkOak(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		$height = 6 + $r->nextBoundedInt(3);
		if(!self::hasSpace($w, $x, $y, $z, 3, 1)){
			return false;
		}
		$log = VanillaBlocks::DARK_OAK_LOG();
		$leaves = VanillaBlocks::DARK_OAK_LEAVES();
		$dirt = VanillaBlocks::DIRT();
		for($dx = 0; $dx < 2; ++$dx){
			for($dz = 0; $dz < 2; ++$dz){
				$w->setBlock($x + $dx, $y, $z + $dz, $dirt);
				for($yy = 1; $yy <= $height; ++$yy){
					self::log($w, $x + $dx, $y + $yy, $z + $dz, $log);
				}
			}
		}
		$top = $y + $height;
		for($yy = -1; $yy <= 1; ++$yy){
			$radius = $yy === 1 ? 2 : 3;
			for($dx = -$radius; $dx <= $radius + 1; ++$dx){
				for($dz = -$radius; $dz <= $radius + 1; ++$dz){
					$edge = ($dx === -$radius || $dx === $radius + 1) && ($dz === -$radius || $dz === $radius + 1);
					if($edge && ($yy !== -1 || $r->nextBoolean())){
						continue;
					}
					self::leaf($w, $x + $dx, $top + $yy, $z + $dz, $leaves);
				}
			}
		}
		return true;
	}

	/** Old growth (mega) spruce / pine: 2x2 trunk, tall, narrow conical canopy. */
	public static function megaSpruce(BlockWriter $w, int $x, int $y, int $z, Random $r, bool $pine) : bool{
		$height = 15 + $r->nextBoundedInt(12);
		if(!self::hasSpace($w, $x, $y, $z, 4, 1)){
			return false;
		}
		$log = VanillaBlocks::SPRUCE_LOG();
		$leaves = VanillaBlocks::SPRUCE_LEAVES();
		for($dx = 0; $dx < 2; ++$dx){
			for($dz = 0; $dz < 2; ++$dz){
				$w->setBlock($x + $dx, $y, $z + $dz, VanillaBlocks::PODZOL());
				for($yy = 1; $yy <= $height; ++$yy){
					self::log($w, $x + $dx, $y + $yy, $z + $dz, $log);
				}
			}
		}
		$canopy = $pine ? 5 + $r->nextBoundedInt(3) : (int) ($height * 0.6);
		for($i = 0; $i <= $canopy; ++$i){
			$yy = $y + $height + 1 - $i;
			$radius = $pine ? ($i < 2 ? 1 : 2) : (int) (($i / $canopy) * 4.5) + ($i % 2);
			$radius = max(0, $radius);
			for($dx = -$radius; $dx <= $radius + 1; ++$dx){
				for($dz = -$radius; $dz <= $radius + 1; ++$dz){
					$cx = $dx <= 0 ? -$dx : $dx - 1;
					$cz = $dz <= 0 ? -$dz : $dz - 1;
					if($cx * $cx + $cz * $cz > $radius * $radius + 1){
						continue;
					}
					self::leaf($w, $x + $dx, $yy, $z + $dz, $leaves);
				}
			}
		}
		return true;
	}

	/** Cherry tree: short trunk that forks into two arms with round pink canopies. */
	public static function cherry(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		if(!self::hasSpace($w, $x, $y, $z, 4, 0)){
			return false;
		}
		$log = VanillaBlocks::CHERRY_LOG();
		$leaves = VanillaBlocks::CHERRY_LEAVES();
		$w->setBlock($x, $y, $z, VanillaBlocks::DIRT());
		$trunk = 3 + $r->nextBoundedInt(2);
		for($yy = 1; $yy <= $trunk; ++$yy){
			self::log($w, $x, $y + $yy, $z, $log);
		}
		$arms = [Facing::HORIZONTAL[$r->nextBoundedInt(4)]];
		if($r->nextBoundedInt(3) !== 0){
			$arms[] = Facing::opposite($arms[0]);
		}
		foreach($arms as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			$len = 2 + $r->nextBoundedInt(2);
			$ax = $x;
			$az = $z;
			$ay = $y + $trunk;
			for($i = 1; $i <= $len; ++$i){
				$ax += $ox;
				$az += $oz;
				$ay += $i > 1 ? 1 : 0;
				self::log($w, $ax, $ay, $az, $log);
			}
			self::blob($w, $ax, $ay + 2, $az, 3, 2, $leaves, $r);
		}
		return true;
	}

	/** Mangrove: trunk standing on arched roots, irregular canopy with hanging leaves. */
	public static function mangrove(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		if(!self::hasSpace($w, $x, $y + 2, $z, 4, 0)){
			return false;
		}
		$log = VanillaBlocks::MANGROVE_LOG();
		$roots = VanillaBlocks::MANGROVE_ROOTS();
		$leaves = VanillaBlocks::MANGROVE_LEAVES();
		$rootHeight = 2 + $r->nextBoundedInt(2);
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			for($yy = 0; $yy <= $rootHeight; ++$yy){
				$reach = $rootHeight - $yy + 1;
				self::log($w, $x + $ox * $reach, $y + $yy, $z + $oz * $reach, $roots);
			}
		}
		$height = 5 + $r->nextBoundedInt(4);
		for($yy = 1; $yy <= $rootHeight + $height; ++$yy){
			self::log($w, $x, $y + $yy, $z, $yy <= $rootHeight ? $roots : $log);
		}
		$top = $y + $rootHeight + $height;
		self::blob($w, $x, $top, $z, 3, 2, $leaves, $r);
		for($i = 0; $i < 6; ++$i){
			$hx = $x + $r->nextRange(-2, 2);
			$hz = $z + $r->nextRange(-2, 2);
			for($d = 1; $d <= 1 + $r->nextBoundedInt(2); ++$d){
				self::leaf($w, $hx, $top - 2 - $d, $hz, $leaves);
			}
		}
		return true;
	}

	/** Swamp oak: plain oak with a wider canopy draped in vines. */
	public static function swampOak(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		$height = 5 + $r->nextBoundedInt(3);
		if(!self::hasSpace($w, $x, $y, $z, $height, 0)){
			return false;
		}
		$log = VanillaBlocks::OAK_LOG();
		$leaves = VanillaBlocks::OAK_LEAVES();
		$w->setBlock($x, $y, $z, VanillaBlocks::DIRT());
		for($yy = 1; $yy <= $height; ++$yy){
			self::log($w, $x, $y + $yy, $z, $log);
		}
		$top = $y + $height;
		for($yy = -3; $yy <= 0; ++$yy){
			$radius = $yy >= -1 ? 2 : 3;
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if(abs($dx) === $radius && abs($dz) === $radius && $r->nextBoolean()){
						continue;
					}
					self::leaf($w, $x + $dx, $top + $yy, $z + $dz, $leaves);
				}
			}
		}
		//vines hanging from the canopy edge
		for($dx = -4; $dx <= 4; ++$dx){
			for($dz = -4; $dz <= 4; ++$dz){
				if($r->nextBoundedInt(4) !== 0){
					continue;
				}
				foreach(Facing::HORIZONTAL as $face){
					[$ox, , $oz] = Facing::OFFSET[$face];
					$lx = $x + $dx + $ox;
					$lz = $z + $dz + $oz;
					if($w->getBlock($lx, $top - 3, $lz) instanceof \pocketmine\block\Leaves && self::canReplace($w, $x + $dx, $top - 3, $z + $dz) && $w->get($x + $dx, $top - 3, $z + $dz) === VanillaBlocks::AIR()->getStateId()){
						$vine = VanillaBlocks::VINES()->setFace($face, true);
						$len = 1 + $r->nextBoundedInt(4);
						for($d = 0; $d < $len && $w->get($x + $dx, $top - 3 - $d, $z + $dz) === VanillaBlocks::AIR()->getStateId(); ++$d){
							$w->setBlock($x + $dx, $top - 3 - $d, $z + $dz, $vine);
						}
						break;
					}
				}
			}
		}
		return true;
	}

	/** Huge red or brown mushroom. */
	public static function hugeMushroom(BlockWriter $w, int $x, int $y, int $z, Random $r, bool $red) : bool{
		$height = 5 + $r->nextBoundedInt(3);
		if(!self::hasSpace($w, $x, $y, $z, $height, 0)){
			return false;
		}
		$stem = VanillaBlocks::MUSHROOM_STEM();
		$cap = $red ? VanillaBlocks::RED_MUSHROOM_BLOCK() : VanillaBlocks::BROWN_MUSHROOM_BLOCK();
		$cap->setMushroomBlockType(MushroomBlockType::ALL_CAP);
		for($yy = 1; $yy <= $height; ++$yy){
			self::log($w, $x, $y + $yy, $z, $stem);
		}
		$top = $y + $height;
		if($red){
			for($yy = -3; $yy <= 0; ++$yy){
				$radius = $yy === 0 ? 1 : 2;
				for($dx = -$radius; $dx <= $radius; ++$dx){
					for($dz = -$radius; $dz <= $radius; ++$dz){
						$side = abs($dx) === $radius || abs($dz) === $radius;
						if($yy < 0 && !$side){
							continue; //hollow skirt
						}
						if($yy < 0 && abs($dx) === $radius && abs($dz) === $radius){
							continue;
						}
						self::leaf($w, $x + $dx, $top + $yy, $z + $dz, $cap);
					}
				}
			}
		}else{
			for($dx = -3; $dx <= 3; ++$dx){
				for($dz = -3; $dz <= 3; ++$dz){
					if(abs($dx) === 3 && abs($dz) === 3){
						continue;
					}
					self::leaf($w, $x + $dx, $top, $z + $dz, $cap);
				}
			}
		}
		return true;
	}

	/** Ice spike: a tall tapering column of packed ice. */
	public static function iceSpike(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$ice = VanillaBlocks::PACKED_ICE();
		$tall = $r->nextBoundedInt(16) === 0;
		$height = $tall ? 25 + $r->nextBoundedInt(25) : 6 + $r->nextBoundedInt(8);
		$baseRadius = $tall ? 2 : 1 + $r->nextBoundedInt(2);
		for($yy = -2; $yy <= $height; ++$yy){
			$radius = (int) ($baseRadius * (1 - max(0, $yy) / ($height + 1)) + 0.5);
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if($dx * $dx + $dz * $dz <= $radius * $radius + ($yy < 0 ? 1 : 0)){
						$w->setBlock($x + $dx, $y + $yy, $z + $dz, $ice);
					}
				}
			}
		}
	}

	/** Mossy cobblestone boulder (old growth taiga). */
	public static function boulder(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$stone = VanillaBlocks::MOSSY_COBBLESTONE();
		for($i = 0; $i < 3; ++$i){
			$radius = 1 + $r->nextBoundedInt(2);
			$cx = $x + $r->nextRange(-1, 1);
			$cz = $z + $r->nextRange(-1, 1);
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dy = -$radius; $dy <= $radius; ++$dy){
					for($dz = -$radius; $dz <= $radius; ++$dz){
						if($dx * $dx + $dy * $dy + $dz * $dz <= $radius * $radius){
							$w->setBlock($cx + $dx, $y + $dy, $cz + $dz, $stone);
						}
					}
				}
			}
		}
	}

	/** Jungle bush: one log with a small leaf mound. */
	public static function jungleBush(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$log = VanillaBlocks::JUNGLE_LOG();
		$leaves = VanillaBlocks::OAK_LEAVES();
		self::log($w, $x, $y + 1, $z, $log);
		for($yy = 1; $yy <= 2; ++$yy){
			$radius = 3 - $yy;
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if(abs($dx) + abs($dz) <= $radius + 1){
						self::leaf($w, $x + $dx, $y + $yy, $z + $dz, $leaves);
					}
				}
			}
		}
	}

	/** Mega jungle tree: 2x2 trunk, vines and a big canopy. */
	public static function megaJungle(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		$height = 18 + $r->nextBoundedInt(12);
		if(!self::hasSpace($w, $x, $y, $z, 4, 1)){
			return false;
		}
		$log = VanillaBlocks::JUNGLE_LOG();
		$leaves = VanillaBlocks::JUNGLE_LEAVES();
		for($dx = 0; $dx < 2; ++$dx){
			for($dz = 0; $dz < 2; ++$dz){
				$w->setBlock($x + $dx, $y, $z + $dz, VanillaBlocks::DIRT());
				for($yy = 1; $yy <= $height; ++$yy){
					self::log($w, $x + $dx, $y + $yy, $z + $dz, $log);
				}
			}
		}
		self::blob($w, $x, $y + $height + 1, $z, 4, 2, $leaves, $r);
		//side branches with small canopies
		for($by = $y + $height - 4; $by > $y + 8; $by -= 3 + $r->nextBoundedInt(3)){
			$face = Facing::HORIZONTAL[$r->nextBoundedInt(4)];
			[$ox, , $oz] = Facing::OFFSET[$face];
			self::log($w, $x + $ox * 2, $by, $z + $oz * 2, $log);
			self::blob($w, $x + $ox * 3, $by + 1, $z + $oz * 3, 2, 1, $leaves, $r);
		}
		//vines on the trunk
		foreach(Facing::HORIZONTAL as $face){
			[$ox, , $oz] = Facing::OFFSET[$face];
			$vine = VanillaBlocks::VINES()->setFace(Facing::opposite($face), true);
			$vx = $x + ($ox > 0 ? 2 : ($ox < 0 ? -1 : 0));
			$vz = $z + ($oz > 0 ? 2 : ($oz < 0 ? -1 : 0));
			for($yy = 1; $yy < $height; ++$yy){
				if($r->nextBoundedInt(3) > 0 && $w->get($vx, $y + $yy, $vz) === VanillaBlocks::AIR()->getStateId()){
					$w->setBlock($vx, $y + $yy, $vz, $vine);
				}
			}
		}
		return true;
	}

	private static function blob(BlockWriter $w, int $x, int $y, int $z, int $radius, int $vertical, Block $leaves, Random $r) : void{
		for($dy = -$vertical; $dy <= $vertical; ++$dy){
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					$dist = ($dx * $dx + $dz * $dz) / ($radius * $radius + 0.5) + ($dy * $dy) / ($vertical * $vertical + 1);
					if($dist <= 1.0 || ($dist <= 1.25 && $r->nextBoolean())){
						self::leaf($w, $x + $dx, $y + $dy, $z + $dz, $leaves);
					}
				}
			}
		}
	}
}
