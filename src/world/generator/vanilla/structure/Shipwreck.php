<?php

/*
 * BetterPMMP - shipwrecks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * A wrecked wooden ship resting on the sea floor (or a beach): hull with
 * holes, deck, broken mast, cabin, and the three vanilla chests (supply,
 * map and treasure). Wood type is random like vanilla.
 */
final class Shipwreck extends SimpleStructure{

	public function __construct(){
		parent::__construct("shipwreck", 24, 4, 165745295, 2, [B::OCEAN, B::DEEP_OCEAN, B::COLD_OCEAN, B::DEEP_COLD_OCEAN, B::LUKEWARM_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::WARM_OCEAN, B::FROZEN_OCEAN, B::DEEP_FROZEN_OCEAN, B::BEACH, B::COLD_BEACH]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$rot = $random->nextBoundedInt(4);
		$ox = $x - 3;
		$oz = $z - 11;
		[$minX, $minZ, $maxX, $maxZ] = Transform::bounds($ox, $oz, $rot, 7, 23);
		$y = self::groundLevel($ctx, $minX, $minZ, $maxX, $maxZ) - 1;
		$woods = [
			[VanillaBlocks::OAK_PLANKS(), VanillaBlocks::OAK_LOG(), VanillaBlocks::OAK_FENCE()],
			[VanillaBlocks::SPRUCE_PLANKS(), VanillaBlocks::SPRUCE_LOG(), VanillaBlocks::SPRUCE_FENCE()],
			[VanillaBlocks::DARK_OAK_PLANKS(), VanillaBlocks::DARK_OAK_LOG(), VanillaBlocks::DARK_OAK_FENCE()],
			[VanillaBlocks::BIRCH_PLANKS(), VanillaBlocks::BIRCH_LOG(), VanillaBlocks::BIRCH_FENCE()],
			[VanillaBlocks::JUNGLE_PLANKS(), VanillaBlocks::JUNGLE_LOG(), VanillaBlocks::JUNGLE_FENCE()],
			[VanillaBlocks::ACACIA_PLANKS(), VanillaBlocks::ACACIA_LOG(), VanillaBlocks::ACACIA_FENCE()],
		];
		$wood = $woods[$random->nextBoundedInt(count($woods))];
		$upsideDown = $random->nextBoundedInt(6) === 0;
		$start->add(new StructurePiece($minX, $y - 2, $minZ, $maxX, $y + 12, $maxZ, function(StructureCanvas $c, Random $r) use ($ox, $y, $oz, $rot, $wood, $upsideDown) : void{
			$t = new Transform($c, $ox, $y, $oz, $rot);
			[$planks, $log, $fence] = $wood;
			//inside the hull: water below sea level, air on beaches
			$water = $y + 2 < 62 ? VanillaBlocks::WATER() : VanillaBlocks::AIR();
			$profile = static function(int $lz) : int{
				//half width of the hull along its length (pointed bow and stern)
				return $lz < 3 ? $lz : ($lz > 19 ? 22 - $lz : 3);
			};
			$hy = fn(int $ly) : int => $upsideDown ? 5 - $ly : $ly;
			for($lz = 0; $lz <= 22; ++$lz){
				$half = $profile($lz);
				for($ly = 0; $ly <= 4; ++$ly){
					$width = max(0, $half - ($ly === 0 ? 1 : 0));
					for($lx = 3 - $width; $lx <= 3 + $width; ++$lx){
						$hull = $lx === 3 - $width || $lx === 3 + $width || $ly === 0;
						if($r->nextFloat() < 0.12){
							continue; //rotten planks
						}
						$t->set($lx, $hy($ly), $lz, $hull ? $planks : $water);
					}
				}
				//deck
				if($half > 0 && $r->nextFloat() > 0.2){
					$t->fill(3 - $half + 1, $hy(4), $lz, 3 + $half - 1, $hy(4), $lz, $planks);
				}
			}
			if(!$upsideDown){
				//broken mast and cabin
				$mastHeight = 3 + $r->nextBoundedInt(6);
				$t->fill(3, 5, 9, 3, 4 + $mastHeight, 9, $log);
				$t->box(1, 4, 15, 5, 7, 19, $planks, $water);
				$t->set(3, 5, 15, $water);
				$t->set(3, 6, 15, $water);
				$t->set(2, 6, 17, $fence);
			}
			$t->set(3, $hy(1), 2, VanillaBlocks::CHEST(), LootTables::chestNbt("shipwreck_supply", $r));
			$t->set(4, $hy(1), 18, VanillaBlocks::CHEST(), LootTables::chestNbt("shipwreck_map", $r));
			$t->set(3, $hy(1), 11, VanillaBlocks::CHEST(), LootTables::chestNbt("shipwreck_treasure", $r));
		}, $random->nextInt()));
		return true;
	}
}
