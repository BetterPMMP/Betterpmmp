<?php

/*
 * BetterPMMP - trail ruins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\utils\Random;

/**
 * Buried ruins of a settlement: terracotta and mud brick walls, roads and a
 * tower, sunk into the ground and filled with gravel, with only the tops
 * showing. (Suspicious gravel/sand for archaeology is not in the core yet,
 * so these ruins have no archaeology loot.)
 */
final class TrailRuins extends SimpleStructure{

	public function __construct(){
		parent::__construct("trail_ruins", 34, 8, 83469867, 2, [B::TAIGA, B::COLD_TAIGA, B::MEGA_TAIGA, B::REDWOOD_TAIGA_MUTATED, B::BIRCH_FOREST_MUTATED, B::JUNGLE]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$y = $ctx->heightAt($x, $z);
		$rooms = 3 + $random->nextBoundedInt(4);
		$colors = [DyeColor::WHITE, DyeColor::LIGHT_GRAY, DyeColor::BROWN, DyeColor::RED, DyeColor::ORANGE, DyeColor::YELLOW, DyeColor::LIGHT_BLUE, DyeColor::BLUE];
		for($i = 0; $i < $rooms; ++$i){
			$rx = $x + $random->nextRange(-14, 14);
			$rz = $z + $random->nextRange(-14, 14);
			$w = 3 + $random->nextBoundedInt(3);
			$d = 3 + $random->nextBoundedInt(3);
			$color = $colors[$random->nextBoundedInt(count($colors))];
			$top = $y - $random->nextBoundedInt(2);
			$start->add(new StructurePiece($rx - $w, $top - 8, $rz - $d, $rx + $w, $top + 1, $rz + $d, function(StructureCanvas $c, Random $r) use ($rx, $rz, $w, $d, $top, $color) : void{
				$gravel = VanillaBlocks::GRAVEL();
				for($dx = -$w; $dx <= $w; ++$dx){
					for($dz = -$d; $dz <= $d; ++$dz){
						$edge = abs($dx) === $w || abs($dz) === $d;
						$c->set($rx + $dx, $top - 7, $rz + $dz, VanillaBlocks::MUD_BRICKS());
						for($dy = -6; $dy <= 0; ++$dy){
							if($edge){
								if($dy === 0 && $r->nextBoundedInt(2) === 0){
									continue;
								}
								$b = $r->nextBoundedInt(4) === 0 ? VanillaBlocks::MUD_BRICKS() : ($r->nextBoundedInt(3) === 0 ? VanillaBlocks::HARDENED_CLAY() : VanillaBlocks::STAINED_CLAY()->setColor($color));
								$c->set($rx + $dx, $top + $dy, $rz + $dz, $b);
							}else{
								$fill = $dy === -6 && $r->nextBoundedInt(3) === 0 ? VanillaBlocks::STAINED_CLAY()->setColor($color) : $gravel;
								if($fill === $gravel && $r->nextBoundedInt(12) === 0 && ($suspicious = \pocketmine\block\ExtraVanillaBlocks::get("minecraft:suspicious_gravel")) !== null){
									$fill = $suspicious;
								}
								$c->set($rx + $dx, $top + $dy, $rz + $dz, $fill);
							}
						}
					}
				}
			}, $random->nextInt()));
		}
		return true;
	}
}
