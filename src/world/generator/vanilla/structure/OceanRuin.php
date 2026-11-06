<?php

/*
 * BetterPMMP - ocean ruins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use function in_array;

/**
 * Clusters of ruined buildings on the sea floor: stone brick (cold oceans)
 * or sandstone (warm oceans), partly buried, with magma blocks, a loot chest
 * in the big ruins and smaller satellite ruins around them.
 */
final class OceanRuin extends SimpleStructure{

	public function __construct(){
		parent::__construct("ocean_ruin", 20, 8, 14357621, 2, [B::OCEAN, B::DEEP_OCEAN, B::COLD_OCEAN, B::DEEP_COLD_OCEAN, B::LUKEWARM_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::WARM_OCEAN, B::FROZEN_OCEAN, B::DEEP_FROZEN_OCEAN]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$warm = in_array($ctx->biomeAt($x, 64, $z), [B::WARM_OCEAN, B::LUKEWARM_OCEAN, B::DEEP_LUKEWARM_OCEAN], true);
		$big = $random->nextBoundedInt(10) < 3;
		$this->ruin($ctx, $start, $x, $z, $big ? 9 : 5, $warm, $random, true);
		if($big){
			for($i = 0; $i < 4 + $random->nextBoundedInt(4); ++$i){
				$this->ruin($ctx, $start, $x + $random->nextRange(-18, 18), $z + $random->nextRange(-18, 18), 4, $warm, $random, false);
			}
		}
		return true;
	}

	private function ruin(StructureContext $ctx, StructureStart $start, int $x, int $z, int $size, bool $warm, Random $random, bool $chest) : void{
		$y = $ctx->heightAt($x, $z);
		$piece = new StructurePiece($x, $y - 2, $z, $x + $size - 1, $y + $size, $z + $size - 1, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $size, $warm, $chest) : void{
			$pick = function() use ($r, $warm) : Block{
				$roll = $r->nextBoundedInt(10);
				if($warm){
					return $roll < 6 ? VanillaBlocks::SANDSTONE() : ($roll < 8 ? VanillaBlocks::CUT_SANDSTONE() : VanillaBlocks::CHISELED_SANDSTONE());
				}
				return $roll < 5 ? VanillaBlocks::STONE_BRICKS() : ($roll < 8 ? VanillaBlocks::MOSSY_STONE_BRICKS() : VanillaBlocks::CRACKED_STONE_BRICKS());
			};
			$height = intdiv($size, 2) + 1;
			for($dx = 0; $dx < $size; ++$dx){
				for($dz = 0; $dz < $size; ++$dz){
					$top = $c->writer->highest($x + $dx, $z + $dz) ?? $y;
					//find the sea floor below the water column
					$floor = $top;
					while($floor > $y - 12 && !$c->getBlock($x + $dx, $floor, $z + $dz)->isSolid()){
						--$floor;
					}
					$c->set($x + $dx, $floor, $z + $dz, $pick());
					$wall = $dx === 0 || $dz === 0 || $dx === $size - 1 || $dz === $size - 1;
					if($wall){
						$wallHeight = $height - $r->nextBoundedInt($height);
						for($h = 1; $h <= $wallHeight; ++$h){
							$c->set($x + $dx, $floor + $h, $z + $dz, $pick());
						}
					}elseif($r->nextBoundedInt(12) === 0){
						$c->set($x + $dx, $floor, $z + $dz, VanillaBlocks::MAGMA());
					}
				}
			}
			if($chest){
				$cx = $x + intdiv($size, 2);
				$cz = $z + intdiv($size, 2);
				$floor = $c->writer->highest($cx, $cz) ?? $y;
				while($floor > $y - 12 && !$c->getBlock($cx, $floor, $cz)->isSolid()){
					--$floor;
				}
				$c->set($cx, $floor + 1, $cz, VanillaBlocks::CHEST(), LootTables::chestNbt("underwater_ruin", $r));
			}
		}, $random->nextInt());
		$start->add($piece);
	}
}
