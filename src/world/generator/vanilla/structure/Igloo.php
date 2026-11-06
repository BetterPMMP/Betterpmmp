<?php

/*
 * BetterPMMP - igloo with optional basement.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;

/**
 * Snow dome with bed, furnace, crafting table and redstone torch. Half of
 * all igloos hide a trapdoor and ladder down to a stone brick basement with
 * a brewing stand, a cauldron, a chest and two prison cells.
 */
final class Igloo extends SimpleStructure{

	public function __construct(){
		parent::__construct("igloo", 32, 8, 14357618, 1, [B::ICE_PLAINS, B::COLD_TAIGA, B::SNOWY_SLOPES]);
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$y = self::groundLevel($ctx, $x - 4, $z - 4, $x + 4, $z + 4) + 1;
		$basement = $random->nextBoolean();
		$depth = $basement ? 4 + $random->nextBoundedInt(8) : 0;
		$start->add(new StructurePiece($x - 4, $y - $depth - 6, $z - 4, $x + 4, $y + 5, $z + 6, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $basement, $depth) : void{
			$snow = VanillaBlocks::SNOW();
			$air = VanillaBlocks::AIR();
			//dome
			for($dx = -4; $dx <= 4; ++$dx){
				for($dz = -4; $dz <= 4; ++$dz){
					$c->foundation($x + $dx, $y, $z + $dz, $snow, 8);
					for($dy = 0; $dy <= 4; ++$dy){
						$d = sqrt($dx * $dx + $dz * $dz + ($dy * 1.15) ** 2);
						if($d <= 4.3 && $d > 3.3){
							$c->set($x + $dx, $y + $dy, $z + $dz, $snow);
						}elseif($d <= 3.3){
							$c->set($x + $dx, $y + $dy, $z + $dz, $dy === 0 ? $snow : $air);
						}
					}
				}
			}
			//entrance tunnel facing south
			for($dz = 4; $dz <= 5; ++$dz){
				$c->set($x - 1, $y + 1, $z + $dz, $snow);
				$c->set($x + 1, $y + 1, $z + $dz, $snow);
				$c->set($x - 1, $y + 2, $z + $dz, $snow);
				$c->set($x + 1, $y + 2, $z + $dz, $snow);
				$c->set($x, $y + 3, $z + $dz, $snow);
				$c->set($x, $y + 1, $z + $dz, $air);
				$c->set($x, $y + 2, $z + $dz, $air);
				$c->set($x, $y, $z + $dz, $snow);
			}
			$c->set($x, $y + 1, $z + 3, $air);
			$c->set($x, $y + 2, $z + 3, $air);
			//interior
			$carpet = VanillaBlocks::CARPET()->setColor(DyeColor::WHITE);
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					$c->set($x + $dx, $y + 1, $z + $dz, $carpet);
				}
			}
			$c->set($x - 2, $y + 1, $z - 1, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setColor(DyeColor::RED));
			$c->set($x - 2, $y + 1, $z - 2, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setHead(true)->setColor(DyeColor::RED));
			$c->set($x + 2, $y + 1, $z - 2, VanillaBlocks::FURNACE()->setFacing(Facing::SOUTH));
			$c->set($x + 2, $y + 1, $z - 1, VanillaBlocks::CRAFTING_TABLE());
			$c->set($x + 2, $y + 1, $z + 1, VanillaBlocks::REDSTONE_TORCH());
			if(!$basement){
				return;
			}
			//trapdoor, ladder shaft and basement
			$c->set($x, $y, $z, VanillaBlocks::OAK_TRAPDOOR()->setFacing(Facing::NORTH));
			$c->set($x, $y + 1, $z, $air);
			for($dy = 1; $dy <= $depth; ++$dy){
				$c->set($x, $y - $dy, $z, VanillaBlocks::LADDER()->setFacing(Facing::SOUTH));
				$c->set($x, $y - $dy, $z - 1, VanillaBlocks::STONE_BRICKS());
			}
			$by = $y - $depth - 4;
			$bricks = VanillaBlocks::STONE_BRICKS();
			$c->box($x - 4, $by - 1, $z - 3, $x + 4, $by + 3, $z + 5, $bricks, $air);
			$c->set($x, $by + 3, $z, $air);
			$c->fill($x, $by + 3, $z, $x, $y - 1, $z, VanillaBlocks::LADDER()->setFacing(Facing::SOUTH));
			$c->set($x - 2, $by, $z + 3, VanillaBlocks::BREWING_STAND());
			$c->set($x + 2, $by, $z + 3, VanillaBlocks::CAULDRON());
			$c->set($x + 3, $by, $z - 2, VanillaBlocks::CHEST(), LootTables::chestNbt("igloo_chest", $r));
			//two prison cells behind iron bars
			foreach([-3, 3] as $cellX){
				$c->fill($x + $cellX - ($cellX < 0 ? 0 : 0), $by, $z + 4, $x + $cellX, $by + 2, $z + 4, VanillaBlocks::IRON_BARS());
			}
			$c->set($x, $by, $z + 1, VanillaBlocks::REDSTONE_TORCH());
		}, $random->nextInt()));
		return true;
	}
}
