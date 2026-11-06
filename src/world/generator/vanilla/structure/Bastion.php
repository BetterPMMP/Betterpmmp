<?php

/*
 * BetterPMMP - bastion remnants.
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
 * Bastion remnant: a ruined polished blackstone brick fortification with
 * ramparts, inner walls, chains, gilded blackstone and gold blocks. Variants
 * like vanilla: housing units, hoglin stables, bridge and the treasure room
 * (magma core, gold, a treasure chest). Not generated in basalt deltas.
 */
final class Bastion extends Structure{

	public function getName() : string{ return "bastion"; }
	protected function getSpacing() : int{ return 27; }
	protected function getSeparation() : int{ return 4; }
	protected function getSalt() : int{ return 30084232; }
	public function getMaxRadiusChunks() : int{ return 3; }
	public function isSurface() : bool{ return false; }

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		if(NetherFortress::isFortress($ctx->seed, $chunkX, $chunkZ)){
			return null;
		}
		$x = ($chunkX << 4) + 8;
		$z = ($chunkZ << 4) + 8;
		if($ctx->biomeAt($x, 64, $z) === B::BASALT_DELTAS){
			return null;
		}
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, false);
		$variant = ["housing", "stables", "bridge", "treasure"][$random->nextBoundedInt(4)];
		$y = 33 + $random->nextBoundedInt(20);
		$half = $variant === "treasure" ? 18 : 16;
		$start->add(new StructurePiece($x - $half, $y - 30, $z - $half, $x + $half, $y + 26, $z + $half, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $half, $variant) : void{
			$this->draw($c, $r, $x, $y, $z, $half, $variant);
		}, $random->nextInt()));
		return $start;
	}

	private function brick(Random $r) : Block{
		$roll = $r->nextBoundedInt(20);
		return match(true){
			$roll < 12 => VanillaBlocks::POLISHED_BLACKSTONE_BRICKS(),
			$roll < 16 => VanillaBlocks::CRACKED_POLISHED_BLACKSTONE_BRICKS(),
			$roll < 19 => VanillaBlocks::BLACKSTONE(),
			default => VanillaBlocks::GILDED_BLACKSTONE(),
		};
	}

	private function draw(StructureCanvas $c, Random $r, int $x, int $y, int $z, int $half, string $variant) : void{
		$air = VanillaBlocks::AIR();
		//base platform and foundation
		for($dx = -$half; $dx <= $half; ++$dx){
			for($dz = -$half; $dz <= $half; ++$dz){
				if(!$c->inside($x + $dx, $z + $dz)){
					continue;
				}
				$c->set($x + $dx, $y, $z + $dz, VanillaBlocks::BLACKSTONE());
				$c->foundation($x + $dx, $y, $z + $dz, VanillaBlocks::BASALT(), 30);
				$c->fill($x + $dx, $y + 1, $z + $dz, $x + $dx, $y + 22, $z + $dz, $air);
				$outer = abs($dx) === $half || abs($dz) === $half;
				$inner = abs($dx) === intdiv($half, 2) || abs($dz) === intdiv($half, 2);
				//outer rampart, ruined towards the top
				if($outer){
					$top = 12 + $r->nextBoundedInt(8) - ($r->nextBoundedInt(6) === 0 ? 8 : 0);
					for($dy = 1; $dy <= $top; ++$dy){
						$c->set($x + $dx, $y + $dy, $z + $dz, $this->brick($r));
					}
				}elseif($inner && $variant !== "bridge"){
					$top = 6 + $r->nextBoundedInt(5);
					$gate = abs($dx) <= 1 || abs($dz) <= 1;
					for($dy = 1; $dy <= $top; ++$dy){
						if($gate && $dy <= 4){
							continue;
						}
						$c->set($x + $dx, $y + $dy, $z + $dz, $this->brick($r));
					}
				}
				//second floor walkway along the rampart
				if(!$outer && (abs($dx) === $half - 1 || abs($dz) === $half - 1)){
					$c->set($x + $dx, $y + 8, $z + $dz, VanillaBlocks::POLISHED_BLACKSTONE_BRICK_SLAB());
				}
			}
		}
		//chains hanging from the ramparts
		for($i = 0; $i < 8; ++$i){
			$cx = $x + $r->nextRange(-$half + 2, $half - 2);
			$cz = $z + $r->nextRange(-$half + 2, $half - 2);
			for($dy = 0; $dy < 4; ++$dy){
				$c->set($cx, $y + 14 - $dy, $cz, VanillaBlocks::CHAIN());
			}
		}
		switch($variant){
			case "treasure":
				//magma core with gold in the centre room
				for($dx = -3; $dx <= 3; ++$dx){
					for($dz = -3; $dz <= 3; ++$dz){
						$c->set($x + $dx, $y, $z + $dz, abs($dx) <= 1 && abs($dz) <= 1 ? VanillaBlocks::MAGMA() : VanillaBlocks::POLISHED_BLACKSTONE_BRICKS());
					}
				}
				$c->fill($x - 1, $y + 1, $z - 1, $x + 1, $y + 1, $z + 1, VanillaBlocks::GOLD());
				$c->set($x, $y + 2, $z, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("bastion_treasure", $r));
				for($i = 0; $i < 6; ++$i){
					$c->set($x + $r->nextRange(-6, 6), $y + 1, $z + $r->nextRange(-6, 6), VanillaBlocks::GOLD());
				}
				break;
			case "stables":
				for($i = 0; $i < 6; ++$i){
					$c->set($x + $r->nextRange(-$half + 2, $half - 2), $y + 1, $z + $r->nextRange(-$half + 2, $half - 2), VanillaBlocks::SOUL_SAND());
				}
				$c->set($x + 3, $y + 1, $z + 3, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("bastion_other", $r));
				break;
			case "bridge":
				//lava moat crossed by a bridge
				for($dx = -$half + 2; $dx <= $half - 2; ++$dx){
					for($dz = -2; $dz <= 2; ++$dz){
						$c->set($x + $dx, $y, $z + $dz + 6, VanillaBlocks::LAVA());
					}
					$c->set($x + $dx, $y + 1, $z, VanillaBlocks::POLISHED_BLACKSTONE_BRICKS());
				}
				$c->set($x, $y + 2, $z - 3, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("bastion_other", $r));
				break;
			default: //housing units
				foreach([[-6, -6], [6, -6], [-6, 6], [6, 6]] as [$dx, $dz]){
					$c->box($x + $dx - 2, $y + 1, $z + $dz - 2, $x + $dx + 2, $y + 5, $z + $dz + 2, VanillaBlocks::POLISHED_BLACKSTONE_BRICKS(), $air);
					$c->fill($x + $dx, $y + 1, $z + $dz - 2, $x + $dx, $y + 3, $z + $dz - 2, $air);
				}
				$c->set($x - 6, $y + 1, $z - 6, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("bastion_other", $r));
				$c->set($x + 6, $y + 1, $z + 6, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH), LootTables::chestNbt("bastion_other", $r));
				break;
		}
		//gold blocks hidden in the walls
		for($i = 0; $i < 4; ++$i){
			$c->set($x + $r->nextRange(-$half, $half), $y + $r->nextRange(1, 6), $z + ($r->nextBoolean() ? $half : -$half), VanillaBlocks::GOLD());
		}
	}
}
