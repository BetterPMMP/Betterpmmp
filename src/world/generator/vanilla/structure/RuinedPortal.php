<?php

/*
 * BetterPMMP - ruined portals (overworld and nether).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use function in_array;

/**
 * Broken obsidian portal frame (some crying obsidian, missing blocks) on a
 * patch of netherrack with magma, gold blocks, lava and a loot chest.
 * Variants follow the biome: jungle portals are overgrown, desert ones
 * half-buried, ocean ones on the sea floor, mountain ones on high ground.
 */
final class RuinedPortal extends SimpleStructure{

	public function __construct(private bool $nether = false){
		parent::__construct($nether ? "ruined_portal_nether" : "ruined_portal", 40, 15, 34222645 + ($nether ? 1 : 0), 1, []);
	}

	/** Ground level of a column near $around (works below the nether roof too). */
	private function surfaceAt(StructureCanvas $c, int $x, int $z, int $around) : int{
		if(!$this->nether){
			return $c->writer->highest($x, $z) ?? $around;
		}
		for($y = min(120, $around + 8); $y > 5; --$y){
			if($c->isAir($x, $y + 1, $z) && !$c->isAir($x, $y, $z)){
				return $y;
			}
		}
		return $around;
	}

	protected function layout(StructureContext $ctx, int $x, int $z, Random $random, StructureStart $start) : bool{
		$biome = $ctx->biomeAt($x, 64, $z);
		$ocean = in_array($biome, [B::OCEAN, B::DEEP_OCEAN, B::COLD_OCEAN, B::DEEP_COLD_OCEAN, B::LUKEWARM_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::WARM_OCEAN, B::FROZEN_OCEAN, B::DEEP_FROZEN_OCEAN], true);
		$jungle = in_array($biome, [B::JUNGLE, B::BAMBOO_JUNGLE, B::JUNGLE_EDGE, B::SWAMPLAND, B::MANGROVE_SWAMP], true);
		$desert = $biome === B::DESERT;
		$y = $this->nether ? 32 + $random->nextBoundedInt(60) : $ctx->heightAt($x, $z);
		if($desert){
			$y -= 3; //half buried
		}
		$alongX = $random->nextBoolean();
		$giant = $random->nextBoundedInt(20) === 0;
		$w = $giant ? 6 : 4;
		$h = $giant ? 9 : 5;
		$start->add(new StructurePiece($x - 6, $y - 4, $z - 6, $x + 6, $y + $h + 2, $z + 6, function(StructureCanvas $c, Random $r) use ($x, $y, $z, $alongX, $w, $h, $jungle, $ocean) : void{
			if($this->nether){
				//stand the portal on the nearest floor in the nether caverns
				$floor = $this->surfaceAt($c, $x, $z, $y);
				$y = $floor;
			}
			$netherrack = VanillaBlocks::NETHERRACK();
			$obsidian = VanillaBlocks::OBSIDIAN();
			$crying = VanillaBlocks::CRYING_OBSIDIAN();
			$air = VanillaBlocks::AIR();
			//netherrack splash around the portal
			for($dx = -5; $dx <= 5; ++$dx){
				for($dz = -5; $dz <= 5; ++$dz){
					$d = $dx * $dx + $dz * $dz;
					if($d > 25 || $r->nextFloat() < $d / 30){
						continue;
					}
					$top = $this->surfaceAt($c, $x + $dx, $z + $dz, $y);
					if(abs($top - $y) <= 3){
						$roll = $r->nextBoundedInt(10);
						$c->set($x + $dx, $top, $z + $dz, $roll === 0 ? VanillaBlocks::MAGMA() : ($roll === 1 && !$ocean ? VanillaBlocks::LAVA() : $netherrack));
					}
				}
			}
			//frame
			for($i = -1; $i <= $w; ++$i){
				for($j = 0; $j <= $h + 1; ++$j){
					$edge = $i === -1 || $i === $w || $j === 0 || $j === $h + 1;
					$px = $alongX ? $x + $i - intdiv($w, 2) : $x;
					$pz = $alongX ? $z : $z + $i - intdiv($w, 2);
					if($edge){
						if($r->nextFloat() < 0.15){
							continue; //missing block
						}
						$c->set($px, $y + $j, $pz, $r->nextFloat() < 0.2 ? $crying : $obsidian);
						if($j === 0){
							$c->foundation($px, $y, $pz, $netherrack, 6);
						}
					}else{
						$c->set($px, $y + $j, $pz, $ocean ? VanillaBlocks::WATER() : $air);
					}
				}
			}
			//fallen obsidian, gold and vines
			for($i = 0; $i < 4; ++$i){
				$fx = $x + $r->nextRange(-4, 4);
				$fz = $z + $r->nextRange(-4, 4);
				$top = $this->surfaceAt($c, $fx, $fz, $y);
				$c->set($fx, $top + 1, $fz, $r->nextBoundedInt(4) === 0 ? VanillaBlocks::GOLD() : $obsidian);
			}
			if($jungle){
				for($i = 0; $i < 12; ++$i){
					$c->set($x + $r->nextRange(-3, 3), $y + $r->nextRange(1, $h), $z + $r->nextRange(-3, 3), VanillaBlocks::OAK_LEAVES());
				}
			}
			$cx = $x + ($alongX ? 0 : 3);
			$cz = $z + ($alongX ? 3 : 0);
			$top = $this->surfaceAt($c, $cx, $cz, $y);
			$c->set($cx, $top + 1, $cz, VanillaBlocks::CHEST(), LootTables::chestNbt("ruined_portal", $r));
		}, $random->nextInt()));
		return true;
	}
}
