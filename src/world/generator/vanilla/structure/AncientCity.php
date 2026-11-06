<?php

/*
 * BetterPMMP - ancient city.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Ancient city in the deep dark (y -51): a huge cavern with a deepslate
 * tile plaza, the reinforced deepslate "portal" frame in the centre, side
 * towers, ruined deepslate brick buildings with loot chests, soul lanterns,
 * candles, sculk patches and paths. Placement: spacing 24, separation 8.
 * Sculk sensors/shriekers are not registered in the core yet.
 */
final class AncientCity extends SimpleStructure{

	private const Y = -51;
	private const HALF = 44;

	public function __construct(private ?\Closure $isDeepDark = null){
		parent::__construct("ancient_city", 24, 8, 20083232, 4, [], false);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		//vanilla: only below terrain with low erosion (where the deep dark is)
		if($this->isDeepDark !== null && !($this->isDeepDark)($x, $z)){
			return false;
		}
		if($ctx->heightAt($x, $z) < 75){
			return false;
		}
		$y = self::Y;
		$h = self::HALF;
		//split the cavern into 16x16 tiles so only the overlapping tile runs per chunk
		for($tx = -$h; $tx < $h; $tx += 16){
			for($tz = -$h; $tz < $h; $tz += 16){
				$ax = $x + $tx;
				$az = $z + $tz;
				$start->add(new StructurePiece($ax, $y - 2, $az, $ax + 15, $y + 22, $az + 15, function(StructureCanvas $c, Random $r) use ($ax, $az, $y, $x, $z) : void{
					$this->cavern($c, $r, $ax, $az, $y, $x, $z);
				}, $random->nextInt()));
			}
		}
		//central portal frame
		$start->add(new StructurePiece($x - 16, $y, $z - 4, $x + 16, $y + 20, $z + 4, function(StructureCanvas $c, Random $r) use ($x, $y, $z) : void{
			$this->portal($c, $x, $y, $z);
		}, $random->nextInt()));
		//buildings around the plaza
		$spots = [[-30, -28], [-30, 0], [-30, 26], [28, -28], [28, 0], [28, 26], [-12, -32], [12, -32], [-12, 30], [12, 30]];
		foreach($spots as [$dx, $dz]){
			if($random->nextBoundedInt(5) === 0){
				continue;
			}
			$bx = $x + $dx + $random->nextRange(-2, 2);
			$bz = $z + $dz + $random->nextRange(-2, 2);
			$w = 5 + $random->nextBoundedInt(4);
			$d = 5 + $random->nextBoundedInt(4);
			$hgt = 4 + $random->nextBoundedInt(5);
			$start->add(new StructurePiece($bx - $w, $y, $bz - $d, $bx + $w, $y + $hgt + 2, $bz + $d, function(StructureCanvas $c, Random $r) use ($bx, $bz, $y, $w, $d, $hgt) : void{
				$this->building($c, $r, $bx, $y, $bz, $w, $d, $hgt);
			}, $random->nextInt()));
		}
		return true;
	}

	private function brick(Random $r) : Block{
		$roll = $r->nextBoundedInt(10);
		return $roll < 6 ? VanillaBlocks::DEEPSLATE_BRICKS() : ($roll < 8 ? VanillaBlocks::CRACKED_DEEPSLATE_BRICKS() : VanillaBlocks::DEEPSLATE_TILES());
	}

	private function cavern(StructureCanvas $c, Random $r, int $ax, int $az, int $y, int $cx, int $cz) : void{
		$air = VanillaBlocks::AIR();
		$tiles = VanillaBlocks::DEEPSLATE_TILES();
		$sculk = VanillaBlocks::SCULK();
		for($x = $ax; $x < $ax + 16; ++$x){
			for($z = $az; $z < $az + 16; ++$z){
				$dx = ($x - $cx) / self::HALF;
				$dz = ($z - $cz) / self::HALF;
				$d = $dx * $dx + $dz * $dz;
				if($d > 1){
					continue;
				}
				//dome shaped cavern: tallest in the middle
				$height = (int) (8 + 14 * (1 - $d));
				$c->fill($x, $y, $z, $x, $y + $height, $z, $air);
				$c->set($x, $y - 1, $z, $r->nextBoundedInt(5) === 0 ? $sculk : $tiles);
				$c->set($x, $y - 2, $z, VanillaBlocks::DEEPSLATE());
				//paths of polished deepslate on the axes
				if(abs($x - $cx) <= 1 || abs($z - $cz) <= 1){
					$c->set($x, $y - 1, $z, VanillaBlocks::POLISHED_DEEPSLATE());
				}
				if($r->nextBoundedInt(90) === 0){
					$c->set($x, $y, $z, VanillaBlocks::SOUL_LANTERN());
				}elseif($r->nextBoundedInt(140) === 0){
					$c->set($x, $y, $z, VanillaBlocks::CANDLE()->setCount(1 + $r->nextBoundedInt(4))->setLit(false));
				}
			}
		}
	}

	private function portal(StructureCanvas $c, int $x, int $y, int $z) : void{
		$reinforced = VanillaBlocks::REINFORCED_DEEPSLATE();
		$polished = VanillaBlocks::POLISHED_DEEPSLATE();
		$bricks = VanillaBlocks::DEEPSLATE_BRICKS();
		$air = VanillaBlocks::AIR();
		//stepped base
		$c->fill($x - 15, $y, $z - 3, $x + 15, $y, $z + 3, $bricks);
		$c->fill($x - 13, $y + 1, $z - 2, $x + 13, $y + 1, $z + 2, $polished);
		//frame: two thick pillars joined by a lintel, with "horns" on top
		for($side = -1; $side <= 1; $side += 2){
			$px = $x + $side * 9;
			$c->fill(min($px, $px + $side * 3), $y + 2, $z - 1, max($px, $px + $side * 3), $y + 15, $z + 1, $reinforced);
			$c->fill(min($px + $side * 3, $px + $side * 5), $y + 12, $z - 1, max($px + $side * 3, $px + $side * 5), $y + 18, $z + 1, $reinforced);
		}
		$c->fill($x - 9, $y + 14, $z - 1, $x + 9, $y + 16, $z + 1, $reinforced);
		$c->fill($x - 8, $y + 2, $z - 1, $x + 8, $y + 13, $z + 1, $air);
		//inner trim
		$c->fill($x - 8, $y + 13, $z, $x + 8, $y + 13, $z, $polished);
		$c->fill($x - 8, $y + 2, $z, $x - 8, $y + 12, $z, $polished);
		$c->fill($x + 8, $y + 2, $z, $x + 8, $y + 12, $z, $polished);
		//soul fire braziers
		foreach([-12, 12] as $dx){
			$c->set($x + $dx, $y + 1, $z + 3, VanillaBlocks::SOUL_SOIL());
			$c->set($x + $dx, $y + 2, $z + 3, VanillaBlocks::SOUL_FIRE());
		}
	}

	private function building(StructureCanvas $c, Random $r, int $x, int $y, int $z, int $w, int $d, int $h) : void{
		$air = VanillaBlocks::AIR();
		for($dx = -$w; $dx <= $w; ++$dx){
			for($dz = -$d; $dz <= $d; ++$dz){
				$edge = abs($dx) === $w || abs($dz) === $d;
				$c->set($x + $dx, $y, $z + $dz, VanillaBlocks::POLISHED_DEEPSLATE());
				for($dy = 1; $dy <= $h; ++$dy){
					if($edge){
						//ruined: walls crumble towards the top
						if($dy > $h - 2 && $r->nextBoundedInt(3) === 0){
							continue;
						}
						$window = ($dy === 2 || $dy === 3) && (abs($dx) % 3 === 1 || abs($dz) % 3 === 1) && !(abs($dx) === $w && abs($dz) === $d);
						$c->set($x + $dx, $y + $dy, $z + $dz, $window ? $air : $this->brick($r));
					}else{
						$c->set($x + $dx, $y + $dy, $z + $dz, $air);
					}
				}
				if(!$edge && $dx % 2 === 0 && $r->nextBoundedInt(4) === 0){
					$c->set($x + $dx, $y + $h + 1, $z + $dz, VanillaBlocks::DEEPSLATE_BRICK_SLAB());
				}
			}
		}
		//doorway, wool, chest, sculk
		$c->fill($x, $y + 1, $z - $d, $x, $y + 2, $z - $d, $air);
		$c->set($x - $w + 1, $y + 1, $z + $d - 1, VanillaBlocks::WOOL()->setColor(DyeColor::GRAY));
		$c->set($x - $w + 1, $y + 2, $z + $d - 1, VanillaBlocks::WOOL()->setColor(DyeColor::LIGHT_GRAY));
		$c->set($x + $w - 1, $y + 1, $z + $d - 1, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), LootTables::chestNbt("ancient_city", $r));
		$c->set($x, $y + $h - 1, $z, VanillaBlocks::SOUL_LANTERN()->setHanging(true));
		for($i = 0; $i < 6; ++$i){
			$c->set($x + $r->nextRange(-$w + 1, $w - 1), $y, $z + $r->nextRange(-$d + 1, $d - 1), VanillaBlocks::SCULK());
		}
		$c->set($x + $w - 2, $y + 1, $z - $d + 1, VanillaBlocks::CANDLE()->setCount(2)->setLit(false));
	}
}
