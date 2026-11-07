<?php

/*
 * BetterPMMP - vanilla-style overworld decoration.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\utils\CoralType;
use pocketmine\block\utils\DirtType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\generator\object\TreeFactory;
use pocketmine\world\generator\object\TreeType;
use pocketmine\world\generator\vanilla\structure\AncientCity;
use pocketmine\world\generator\vanilla\structure\TrialChamber;
use pocketmine\world\generator\vanilla\structure\DesertPyramid;
use pocketmine\world\generator\vanilla\structure\Igloo;
use pocketmine\world\generator\vanilla\structure\JungleTemple;
use pocketmine\world\generator\vanilla\structure\Mineshaft;
use pocketmine\world\generator\vanilla\structure\OceanMonument;
use pocketmine\world\generator\vanilla\structure\OceanRuin;
use pocketmine\world\generator\vanilla\structure\PillagerOutpost;
use pocketmine\world\generator\vanilla\structure\RuinedPortal;
use pocketmine\world\generator\vanilla\structure\Shipwreck;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\SwampHut;
use pocketmine\world\generator\vanilla\structure\TrailRuins;
use pocketmine\world\generator\vanilla\structure\VillageStructure;
use pocketmine\world\generator\vanilla\structure\WoodlandMansion;
use pocketmine\world\generator\vanilla\structure\StructureContext;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\Stronghold;
use function count;
use function in_array;
use function max;
use function min;

/**
 * Decorates generated overworld chunks: ores (1.18 height distribution),
 * cave biome features, per-biome vegetation, ice spikes, boulders, desert
 * wells and villages.
 */
final class OverworldPopulator{

	private int $stone;
	private int $deepslate;
	private int $air;
	private int $water;
	private int $grass;
	private int $dirt;
	private int $coarseDirt;
	private int $podzol;
	private int $mycelium;
	private int $sand;
	private int $redSand;
	private int $snowLayer;
	private int $mud;
	private int $lava;

	/** @var int[] solid ground states plants may grow on */
	private array $soil;
	private StructureManager $structures;
	private UndergroundFeatures $features;

	public function __construct(
		private int $seed,
		private OverworldClimate $climate
	){
		$this->stone = VanillaBlocks::STONE()->getStateId();
		$this->deepslate = VanillaBlocks::DEEPSLATE()->getStateId();
		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->water = VanillaBlocks::WATER()->getStateId();
		$this->lava = VanillaBlocks::LAVA()->getStateId();
		$this->grass = VanillaBlocks::GRASS()->getStateId();
		$this->dirt = VanillaBlocks::DIRT()->getStateId();
		$this->coarseDirt = VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE)->getStateId();
		$this->podzol = VanillaBlocks::PODZOL()->getStateId();
		$this->mycelium = VanillaBlocks::MYCELIUM()->getStateId();
		$this->sand = VanillaBlocks::SAND()->getStateId();
		$this->redSand = VanillaBlocks::RED_SAND()->getStateId();
		$this->snowLayer = VanillaBlocks::SNOW_LAYER()->getStateId();
		$this->mud = VanillaBlocks::MUD()->getStateId();
		$this->soil = [$this->grass, $this->dirt, $this->coarseDirt, $this->podzol, $this->mycelium, $this->mud];
		$ctx = new StructureContext(
			$seed,
			function(int $x, int $y, int $z) use ($climate) : int{
				return OverworldBiomeBuilder::pick(...$climate->sample($x, $z));
			},
			function(int $x, int $z) use ($climate) : int{
				$s = $climate->sample($x, $z);
				return (int) $climate->terrain($x, $z, $s[2], $s[3], $s[4])[0];
			}
		);
		$this->features = new UndergroundFeatures();
		$villages = new VillageStructure();
		$this->structures = new StructureManager($ctx, [
			new Mineshaft(),
			new Stronghold(),
			$villages,
			new DesertPyramid(),
			new JungleTemple(),
			new Igloo(),
			new SwampHut(),
			new PillagerOutpost(fn(int $chunkX, int $chunkZ) : bool => $villages->hasVillageNear($ctx, $chunkX, $chunkZ, 10)),
			new RuinedPortal(),
			new Shipwreck(),
			new OceanRuin(),
			new OceanMonument(),
			new WoodlandMansion(),
			new AncientCity(function(int $x, int $z) use ($climate) : bool{
				return $climate->sample($x, $z)[3] < -0.45;
			}),
			new TrailRuins(),
			new TrialChamber(),
		]);
	}

	public function getStructureManager() : StructureManager{
		return $this->structures;
	}

	public static function isSpawnFriendly(int $biome) : bool{
		return in_array($biome, [B::PLAINS, B::SUNFLOWER_PLAINS, B::FOREST, B::FLOWER_FOREST, B::BIRCH_FOREST, B::TAIGA, B::SAVANNA, B::MEADOW, B::CHERRY_GROVE, B::JUNGLE, B::JUNGLE_EDGE, B::BIRCH_FOREST_MUTATED], true);
	}

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$random = new Random($this->seed ^ ($chunkX * 0x4F9939F508) ^ ($chunkZ * 0x1EF1565BD5) ^ 0x2545F491);
		$w = new BlockWriter($world);
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;

		$this->features->populate($w, $baseX, $baseZ, $random);
		$this->placeOres($w, $baseX, $baseZ, $random);
		$starts = $this->structures->populate($w, $chunkX, $chunkZ);
		$this->decorateCaves($w, $baseX, $baseZ, $random);
		$surfaceY = $w->highest($baseX + 8, $baseZ + 8) ?? 64;
		$this->features->populateSurface($w, $baseX, $baseZ, $w->getBiome($baseX + 8, $surfaceY, $baseZ + 8), $random);

		$this->decorateSurface($w, $baseX, $baseZ, $random, $starts);
	}

	//---------------------------------------------------------------- ores

	/**
	 * 1.18 ore distribution. "t" = triangle (peaks in the middle of the range),
	 * "u" = uniform. Deepslate variants replace deepslate automatically.
	 */
	private function placeOres(BlockWriter $w, int $baseX, int $baseZ, Random $r) : void{
		static $table = null;
		if($table === null){
			$table = [
				//[ore, deepslate ore, veins per chunk, vein size, min y, max y, distribution]
				[VanillaBlocks::COAL_ORE(), VanillaBlocks::DEEPSLATE_COAL_ORE(), 30, 17, 136, 320, "u"],
				[VanillaBlocks::COAL_ORE(), VanillaBlocks::DEEPSLATE_COAL_ORE(), 20, 17, 0, 192, "t"],
				[VanillaBlocks::IRON_ORE(), VanillaBlocks::DEEPSLATE_IRON_ORE(), 90, 9, 80, 384, "t"],
				[VanillaBlocks::IRON_ORE(), VanillaBlocks::DEEPSLATE_IRON_ORE(), 10, 9, -24, 56, "t"],
				[VanillaBlocks::IRON_ORE(), VanillaBlocks::DEEPSLATE_IRON_ORE(), 10, 4, -64, 72, "u"],
				[VanillaBlocks::COPPER_ORE(), VanillaBlocks::DEEPSLATE_COPPER_ORE(), 16, 10, -16, 112, "t"],
				[VanillaBlocks::GOLD_ORE(), VanillaBlocks::DEEPSLATE_GOLD_ORE(), 4, 9, -64, 32, "t"],
				[VanillaBlocks::GOLD_ORE(), VanillaBlocks::DEEPSLATE_GOLD_ORE(), 1, 9, -64, -48, "u"],
				[VanillaBlocks::REDSTONE_ORE(), VanillaBlocks::DEEPSLATE_REDSTONE_ORE(), 4, 8, -64, 15, "u"],
				[VanillaBlocks::REDSTONE_ORE(), VanillaBlocks::DEEPSLATE_REDSTONE_ORE(), 8, 8, -96, -32, "t"],
				[VanillaBlocks::LAPIS_LAZULI_ORE(), VanillaBlocks::DEEPSLATE_LAPIS_LAZULI_ORE(), 2, 7, -32, 32, "t"],
				[VanillaBlocks::LAPIS_LAZULI_ORE(), VanillaBlocks::DEEPSLATE_LAPIS_LAZULI_ORE(), 4, 7, -64, 64, "u"],
				[VanillaBlocks::DIAMOND_ORE(), VanillaBlocks::DEEPSLATE_DIAMOND_ORE(), 7, 4, -144, 16, "t"],
				[VanillaBlocks::DIAMOND_ORE(), VanillaBlocks::DEEPSLATE_DIAMOND_ORE(), 4, 8, -144, 16, "t"],
				//stone variants and other blobs
				[VanillaBlocks::DIRT(), null, 7, 33, 0, 160, "u"],
				[VanillaBlocks::GRAVEL(), null, 14, 33, -64, 320, "u"],
				[VanillaBlocks::GRANITE(), null, 2, 64, 0, 60, "u"],
				[VanillaBlocks::GRANITE(), null, 6, 64, 64, 128, "u"],
				[VanillaBlocks::DIORITE(), null, 2, 64, 0, 60, "u"],
				[VanillaBlocks::DIORITE(), null, 6, 64, 64, 128, "u"],
				[VanillaBlocks::ANDESITE(), null, 2, 64, 0, 60, "u"],
				[VanillaBlocks::ANDESITE(), null, 6, 64, 64, 128, "u"],
				[VanillaBlocks::TUFF(), VanillaBlocks::TUFF(), 2, 64, -64, 0, "u"],
			];
			foreach($table as $k => $row){
				$table[$k][0] = $row[0]->getStateId();
				$table[$k][1] = $row[1]?->getStateId();
			}
		}
		foreach($table as [$ore, $deepOre, $count, $size, $minY, $maxY, $dist]){
			for($i = 0; $i < $count; ++$i){
				$y = $dist === "t"
					? $minY + intdiv($r->nextBoundedInt($maxY - $minY + 1) + $r->nextBoundedInt($maxY - $minY + 1), 2)
					: $minY + $r->nextBoundedInt($maxY - $minY + 1);
				if($y < Overworld::MIN_Y + 1 || $y > 300){
					continue;
				}
				$this->vein($w, $baseX + $r->nextBoundedInt(16), $y, $baseZ + $r->nextBoundedInt(16), $size, $ore, $deepOre, $r);
			}
		}

		//emeralds in mountain biomes
		$biome = $w->getBiome($baseX + 8, 100, $baseZ + 8);
		if(in_array($biome, [B::EXTREME_HILLS, B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS_PLUS_TREES, B::JAGGED_PEAKS, B::FROZEN_PEAKS, B::STONY_PEAKS, B::SNOWY_SLOPES, B::GROVE, B::MEADOW, B::CHERRY_GROVE], true)){
			$emerald = VanillaBlocks::EMERALD_ORE()->getStateId();
			$deepEmerald = VanillaBlocks::DEEPSLATE_EMERALD_ORE()->getStateId();
			for($i = 0; $i < 100; ++$i){
				$y = -16 + intdiv($r->nextBoundedInt(497) + $r->nextBoundedInt(497), 2);
				$this->vein($w, $baseX + $r->nextBoundedInt(16), $y, $baseZ + $r->nextBoundedInt(16), 3, $emerald, $deepEmerald, $r);
			}
		}
		//extra gold in badlands
		if(in_array($biome, [B::MESA, B::MESA_BRYCE, B::MESA_PLATEAU_STONE], true)){
			$gold = VanillaBlocks::GOLD_ORE()->getStateId();
			for($i = 0; $i < 50; ++$i){
				$this->vein($w, $baseX + $r->nextBoundedInt(16), 32 + $r->nextBoundedInt(225), $baseZ + $r->nextBoundedInt(16), 9, $gold, null, $r);
			}
		}
	}

	private function vein(BlockWriter $w, int $x, int $y, int $z, int $size, int $ore, ?int $deepOre, Random $r) : void{
		$radius = max(1.0, ($size / 3.5) ** (1 / 2.2));
		$rad = (int) ceil($radius);
		$placed = 0;
		for($attempt = 0; $attempt < $size * 2 && $placed < $size; ++$attempt){
			$dx = $r->nextRange(-$rad, $rad);
			$dy = $r->nextRange(-$rad, $rad);
			$dz = $r->nextRange(-$rad, $rad);
			if($dx * $dx + $dy * $dy + $dz * $dz > $radius * $radius + 0.5){
				continue;
			}
			$id = $w->get($x + $dx, $y + $dy, $z + $dz);
			if($id === $this->stone){
				$w->set($x + $dx, $y + $dy, $z + $dz, $ore);
				++$placed;
			}elseif($id === $this->deepslate && $deepOre !== null){
				$w->set($x + $dx, $y + $dy, $z + $dz, $deepOre);
				++$placed;
			}
		}
	}

	//---------------------------------------------------------------- caves

	/**
	 * Stalagmite (growing up from $y) or stalactite (hanging down from $y):
	 * base, middle and frustum segments ending in a tip, like vanilla.
	 */
	private function dripstone(BlockWriter $w, \pocketmine\block\GenericBedrockBlock $pointed, int $x, int $y, int $z, int $length, bool $hanging) : void{
		$dir = $hanging ? -1 : 1;
		$available = 0;
		while($available < $length && $w->get($x, $y + $dir * $available, $z) === $this->air){
			++$available;
		}
		for($i = 0; $i < $available; ++$i){
			$fromTip = $available - 1 - $i;
			$thickness = match(true){
				$fromTip === 0 => "tip",
				$fromTip === 1 => "frustum",
				$i === 0 && $available > 3 => "base",
				default => "middle",
			};
			$w->setBlock($x, $y + $dir * $i, $z, $pointed->withProperties(["dripstone_thickness" => $thickness, "hanging" => $hanging]));
		}
	}

	/** Position-based pseudo random number (independent of population order). */
	private function posRand(int $x, int $y, int $z, int $salt) : int{
		//all products stay below 2^63, so PHP never falls back to floats
		$h = (($x & 0xffffffff) * 0x1F1F1F1F) ^ ((($y + 2048) & 0xffff) * 0x2E3D4C5B) ^ (($z & 0xffffffff) * 0x3B5A7C9D)
			^ (($this->seed & 0xffffffff) * 31) ^ (($salt & 0xffff) * 0x6A09E667);
		$h &= 0xffffffff;
		$h ^= $h >> 13;
		$h = ($h * 0x5BD1E995) & 0xffffffff;
		$h ^= $h >> 15;
		return $h & 0x7fffffff;
	}

	private function decorateCaves(BlockWriter $w, int $baseX, int $baseZ, Random $r) : void{
		$hasCaveBiome = false;
		for($y = -60; $y < 120 && !$hasCaveBiome; $y += 16){
			foreach([[2, 2], [2, 13], [13, 2], [13, 13], [8, 8]] as [$x, $z]){
				$b = $w->getBiome($baseX + $x, $y, $baseZ + $z);
				if($b === B::LUSH_CAVES || $b === B::DEEP_DARK || $b === B::DRIPSTONE_CAVES){
					$hasCaveBiome = true;
					break;
				}
			}
		}
		$glowLichen = VanillaBlocks::GLOW_LICHEN();
		$vines = VanillaBlocks::CAVE_VINES();
		$azalea = VanillaBlocks::AZALEA();
		$floweringAzalea = VanillaBlocks::FLOWERING_AZALEA();
		$rooted = VanillaBlocks::DIRT()->setDirtType(DirtType::ROOTED)->getStateId();
		$clay = VanillaBlocks::CLAY()->getStateId();
		$sculk = VanillaBlocks::SCULK()->getStateId();
		$smallDripleaf = VanillaBlocks::SMALL_DRIPLEAF();
		$extra = fn(string $name) => \pocketmine\block\ExtraVanillaBlocks::get($name);
		$moss = $extra("minecraft:moss_block")?->getStateId() ?? $rooted;
		$mossCarpet = $extra("minecraft:moss_carpet");
		$dripstoneBlock = $extra("minecraft:dripstone_block")?->getStateId();
		$pointed = $extra("minecraft:pointed_dripstone");
		$sensor = $extra("minecraft:sculk_sensor");
		$shrieker = $extra("minecraft:sculk_shrieker");
		$catalyst = $extra("minecraft:sculk_catalyst");

		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				if(!$hasCaveBiome && $this->posRand($baseX + $x, 0, $baseZ + $z, 9) % 6 !== 0){
					continue; //plain caves only get sparse glow lichen
				}
				$wx = $baseX + $x;
				$wz = $baseZ + $z;
				$top = min(($w->highest($wx, $wz) ?? 64) - 12, 200);
				$prev = $w->get($wx, -58, $wz);
				for($y = -57; $y < $top; ++$y){
					$id = $w->get($wx, $y, $wz);
					$solidPrev = $prev === $this->stone || $prev === $this->deepslate;
					$solidNow = $id === $this->stone || $id === $this->deepslate;
					if($id === $this->air && $solidPrev){
						//floor at y-1
						$biome = $hasCaveBiome ? $w->getBiome($wx, $y, $wz) : -1;
						if($biome === B::LUSH_CAVES){
							$roll = $this->posRand($wx, $y, $wz, 1) % 100;
							if($roll < 70){
								$w->set($wx, $y - 1, $wz, $roll < 8 ? $clay : $moss);
								if($roll >= 18 && $roll < 40 && $mossCarpet !== null && $w->get($wx, $y, $wz) === $this->air){
									$w->setBlock($wx, $y, $wz, $mossCarpet);
								}
								if($roll >= 8 && $roll < 14){
									$w->setBlock($wx, $y, $wz, $this->posRand($wx, $y, $wz, 2) % 2 === 0 ? $azalea : $floweringAzalea);
								}elseif($roll >= 14 && $roll < 18 && $w->get($wx, $y + 1, $wz) === $this->air){
									$w->setBlock($wx, $y, $wz, (clone $smallDripleaf)->setTop(false));
									$w->setBlock($wx, $y + 1, $wz, (clone $smallDripleaf)->setTop(true));
								}
							}
						}elseif($biome === B::DEEP_DARK){
							if($this->posRand($wx, $y, $wz, 3) % 3 !== 0){
								$w->set($wx, $y - 1, $wz, $sculk);
								$roll = $this->posRand($wx, $y, $wz, 10) % 200;
								if($roll < 3 && $sensor !== null){
									$w->setBlock($wx, $y, $wz, $sensor);
								}elseif($roll < 5 && $shrieker !== null){
									$w->setBlock($wx, $y, $wz, $shrieker->withProperties(["can_summon" => true]));
								}elseif($roll < 6 && $catalyst !== null){
									$w->setBlock($wx, $y - 1, $wz, $catalyst);
								}
							}
						}elseif($biome === B::DRIPSTONE_CAVES && $dripstoneBlock !== null && $pointed !== null){
							$roll = $this->posRand($wx, $y, $wz, 11) % 100;
							if($roll < 35){
								$w->set($wx, $y - 1, $wz, $dripstoneBlock);
							}
							if($roll < 7){
								$this->dripstone($w, $pointed, $wx, $y, $wz, 1 + $this->posRand($wx, $y, $wz, 12) % 4, false);
							}
						}
					}elseif($solidNow && $prev === $this->air){
						//ceiling at y, air at y-1
						$biome = $hasCaveBiome ? $w->getBiome($wx, $y - 1, $wz) : -1;
						if($biome === B::LUSH_CAVES && $this->posRand($wx, $y, $wz, 4) % 5 === 0){
							$len = 1 + $this->posRand($wx, $y, $wz, 5) % 6;
							for($d = 1; $d <= $len && $w->get($wx, $y - $d, $wz) === $this->air; ++$d){
								$w->setBlock($wx, $y - $d, $wz, (clone $vines)->setHead($d === $len)->setBerries($this->posRand($wx, $y - $d, $wz, 6) % 4 === 0));
							}
						}elseif($biome === B::DRIPSTONE_CAVES && $pointed !== null && $this->posRand($wx, $y, $wz, 13) % 10 === 0){
							if($dripstoneBlock !== null){
								$w->set($wx, $y, $wz, $dripstoneBlock);
							}
							$this->dripstone($w, $pointed, $wx, $y - 1, $wz, 1 + $this->posRand($wx, $y, $wz, 14) % 5, true);
						}elseif($biome === B::DEEP_DARK && $this->posRand($wx, $y, $wz, 7) % 4 === 0){
							$w->set($wx, $y, $wz, $sculk);
						}elseif($this->posRand($wx, $y, $wz, 8) % 40 === 0){
							$w->setBlock($wx, $y - 1, $wz, (clone $glowLichen)->setFace(Facing::UP, true));
						}
					}
					$prev = $id;
				}
			}
		}
	}

	//---------------------------------------------------------------- surface

	/**
	 * Returns the y of the ground block of a column (below snow layers/plants),
	 * or null for water / unavailable columns.
	 */
	private function ground(BlockWriter $w, int $x, int $z) : ?int{
		$y = $w->highest($x, $z);
		if($y === null){
			return null;
		}
		$id = $w->get($x, $y, $z);
		if($id === $this->snowLayer){
			--$y;
			$id = $w->get($x, $y, $z);
		}
		return $id === $this->water || $id === $this->lava ? null : $y;
	}

	private function onSoil(BlockWriter $w, int $x, int $y, int $z) : bool{
		return in_array($w->get($x, $y, $z), $this->soil, true) && $w->get($x, $y + 1, $z) === $this->air;
	}

	private function plant(BlockWriter $w, int $x, int $z, Block $block, bool $needsSoil = true) : void{
		$y = $this->ground($w, $x, $z);
		if($y === null){
			return;
		}
		$above = $w->get($x, $y + 1, $z);
		if($above !== $this->air && $above !== $this->snowLayer){
			return;
		}
		if($needsSoil && !in_array($w->get($x, $y, $z), $this->soil, true)){
			return;
		}
		$w->setBlock($x, $y + 1, $z, $block);
	}

	private function doublePlant(BlockWriter $w, int $x, int $z, \pocketmine\block\DoublePlant $block) : void{
		$y = $this->ground($w, $x, $z);
		if($y === null || !$this->onSoil($w, $x, $y, $z) || $w->get($x, $y + 2, $z) !== $this->air){
			return;
		}
		$w->setBlock($x, $y + 1, $z, (clone $block)->setTop(false));
		$w->setBlock($x, $y + 2, $z, (clone $block)->setTop(true));
	}

	private function tree(BlockWriter $w, int $x, int $z, string $kind, Random $r) : void{
		$y = $this->ground($w, $x, $z);
		if($y === null || !in_array($w->get($x, $y, $z), $this->soil, true)){
			return;
		}
		switch($kind){
			case "dark_oak":
				VanillaFeatures::darkOak($w, $x, $y, $z, $r);
				return;
			case "pale_oak":
				VanillaFeatures::paleOak($w, $x, $y, $z, $r);
				return;
			case "mega_spruce":
				VanillaFeatures::megaSpruce($w, $x, $y, $z, $r, false);
				return;
			case "mega_pine":
				VanillaFeatures::megaSpruce($w, $x, $y, $z, $r, true);
				return;
			case "cherry":
				VanillaFeatures::cherry($w, $x, $y, $z, $r);
				return;
			case "mangrove":
				VanillaFeatures::mangrove($w, $x, $y, $z, $r);
				return;
			case "swamp_oak":
				VanillaFeatures::swampOak($w, $x, $y, $z, $r);
				return;
			case "mega_jungle":
				VanillaFeatures::megaJungle($w, $x, $y, $z, $r);
				return;
			case "jungle_bush":
				VanillaFeatures::jungleBush($w, $x, $y, $z, $r);
				return;
			case "huge_red_mushroom":
				VanillaFeatures::hugeMushroom($w, $x, $y, $z, $r, true);
				return;
			case "huge_brown_mushroom":
				VanillaFeatures::hugeMushroom($w, $x, $y, $z, $r, false);
				return;
		}
		$type = match($kind){
			"birch", "tall_birch" => TreeType::BIRCH,
			"spruce" => TreeType::SPRUCE,
			"jungle" => TreeType::JUNGLE,
			"acacia" => TreeType::ACACIA,
			"azalea" => TreeType::AZALEA,
			default => TreeType::OAK,
		};
		$tree = $kind === "tall_birch" ? new \pocketmine\world\generator\object\BirchTree(true) : TreeFactory::get($r, $type);
		$transaction = $tree?->getBlockTransaction($w->getWorld(), $x, $y + 1, $z, $r);
		if($transaction === null){
			return;
		}
		foreach($transaction->getBlocks() as [$bx, , $bz]){
			if(!$w->isAvailable($bx, $bz)){
				return;
			}
		}
		$transaction->apply();
	}

	/**
	 * @return array{0: int, 1: list<array{string, int}>, 2: int, 3: list<Block>, 4: int} trees per chunk, weighted tree kinds,
	 *         grass patches, flower pool, flowers per chunk
	 */
	private function profile(int $biome) : array{
		$basicFlowers = [VanillaBlocks::DANDELION(), VanillaBlocks::POPPY()];
		$plainsFlowers = [VanillaBlocks::DANDELION(), VanillaBlocks::POPPY(), VanillaBlocks::AZURE_BLUET(), VanillaBlocks::OXEYE_DAISY(), VanillaBlocks::CORNFLOWER(),
			VanillaBlocks::RED_TULIP(), VanillaBlocks::ORANGE_TULIP(), VanillaBlocks::WHITE_TULIP(), VanillaBlocks::PINK_TULIP()];
		$allFlowers = [...$plainsFlowers, VanillaBlocks::ALLIUM(), VanillaBlocks::LILY_OF_THE_VALLEY()];
		return match($biome){
			B::PLAINS => [0, [["oak", 9], ["birch", 1]], 30, $plainsFlowers, 4],
			B::SUNFLOWER_PLAINS => [0, [["oak", 1]], 30, $plainsFlowers, 6],
			B::FOREST => [10, [["oak", 8], ["birch", 2]], 6, [...$basicFlowers, VanillaBlocks::LILY_OF_THE_VALLEY()], 3],
			B::FLOWER_FOREST => [6, [["oak", 8], ["birch", 2]], 4, $allFlowers, 40],
			B::BIRCH_FOREST => [10, [["birch", 1]], 6, $basicFlowers, 3],
			B::BIRCH_FOREST_MUTATED => [10, [["tall_birch", 3], ["birch", 1]], 6, $basicFlowers, 3],
			B::PALE_GARDEN => [16, [["pale_oak", 1]], 2, array_filter([\pocketmine\block\ExtraVanillaBlocks::get("minecraft:closed_eyeblossom")]), 2],
			B::ROOFED_FOREST => [16, [["dark_oak", 12], ["huge_red_mushroom", 1], ["huge_brown_mushroom", 1], ["oak", 2], ["birch", 2]], 4, $basicFlowers, 2],
			B::TAIGA => [10, [["spruce", 1]], 8, $basicFlowers, 1],
			B::COLD_TAIGA => [10, [["spruce", 1]], 4, [], 0],
			B::MEGA_TAIGA => [12, [["mega_pine", 3], ["spruce", 4], ["mega_spruce", 1]], 10, [], 0],
			B::REDWOOD_TAIGA_MUTATED => [12, [["mega_spruce", 3], ["spruce", 4]], 10, [], 0],
			B::ICE_PLAINS => [0, [["spruce", 1]], 0, [], 0],
			B::GROVE => [8, [["spruce", 1]], 0, [], 0],
			B::SAVANNA, B::SAVANNA_PLATEAU => [1, [["acacia", 4], ["oak", 1]], 40, $basicFlowers, 1],
			B::SAVANNA_MUTATED => [1, [["acacia", 4], ["oak", 1]], 20, [], 0],
			B::JUNGLE => [40, [["jungle", 4], ["mega_jungle", 1], ["jungle_bush", 5]], 25, $basicFlowers, 2],
			B::JUNGLE_EDGE => [2, [["jungle", 4], ["jungle_bush", 4]], 25, $basicFlowers, 2],
			B::BAMBOO_JUNGLE => [12, [["jungle", 3], ["mega_jungle", 1], ["jungle_bush", 4]], 15, $basicFlowers, 1],
			B::SWAMPLAND => [2, [["swamp_oak", 1]], 5, [VanillaBlocks::BLUE_ORCHID()], 1],
			B::MANGROVE_SWAMP => [16, [["mangrove", 1]], 0, [], 0],
			B::MEADOW => [0, [["oak", 1], ["birch", 1]], 50, [...$plainsFlowers, VanillaBlocks::ALLIUM()], 12],
			B::CHERRY_GROVE => [4, [["cherry", 1]], 20, [], 0],
			B::EXTREME_HILLS, B::EXTREME_HILLS_MUTATED => [0, [["oak", 1], ["spruce", 2]], 6, $basicFlowers, 1],
			B::EXTREME_HILLS_PLUS_TREES => [5, [["spruce", 2], ["oak", 1]], 6, $basicFlowers, 1],
			B::MESA_PLATEAU_STONE => [3, [["oak", 1]], 4, [], 0],
			B::MUSHROOM_ISLAND => [1, [["huge_red_mushroom", 1], ["huge_brown_mushroom", 1]], 0, [], 0],
			B::RIVER, B::BEACH => [0, [["oak", 1]], 3, [], 0],
			default => [0, [["oak", 1]], 2, [], 0],
		};
	}

	private function pickWeighted(array $choices, Random $r) : string{
		$total = 0;
		foreach($choices as [, $weight]){
			$total += $weight;
		}
		$roll = $r->nextBoundedInt(max(1, $total));
		foreach($choices as [$name, $weight]){
			if(($roll -= $weight) < 0){
				return $name;
			}
		}
		return $choices[0][0];
	}

	/**
	 * @param StructureStart[] $starts structures overlapping this chunk
	 */
	private function decorateSurface(BlockWriter $w, int $baseX, int $baseZ, Random $r, array $starts = []) : void{
		$columnBiome = function(int $x, int $z) use ($w) : int{
			$y = $w->highest($x, $z) ?? 64;
			return $w->getBiome($x, $y, $z);
		};
		$surfaceStarts = array_values(array_filter($starts, fn(StructureStart $s) => $s->surface));
		$inVillage = function(int $x, int $z) use ($surfaceStarts) : bool{
			foreach($surfaceStarts as $start){
				if($start->containsColumn($x, $z, 3)){
					return true;
				}
			}
			return false;
		};
		$centerBiome = $columnBiome($baseX + 8, $baseZ + 8);
		[$trees, , $grassPatches, , $flowerCount] = $this->profile($centerBiome);

		//lakes are not generated; ice spikes, boulders and wells are biome specific
		if($centerBiome === B::ICE_PLAINS_SPIKES){
			for($i = 0; $i < 3; ++$i){
				$x = $baseX + 2 + $r->nextBoundedInt(12);
				$z = $baseZ + 2 + $r->nextBoundedInt(12);
				$y = $this->ground($w, $x, $z);
				if($y !== null && !$inVillage($x, $z)){
					VanillaFeatures::iceSpike($w, $x, $y, $z, $r);
				}
			}
		}
		if(($centerBiome === B::MEGA_TAIGA || $centerBiome === B::REDWOOD_TAIGA_MUTATED) && $r->nextBoundedInt(3) === 0){
			$x = $baseX + 3 + $r->nextBoundedInt(10);
			$z = $baseZ + 3 + $r->nextBoundedInt(10);
			$y = $this->ground($w, $x, $z);
			if($y !== null && !$inVillage($x, $z)){
				VanillaFeatures::boulder($w, $x, $y + 1, $z, $r);
			}
		}
		if($centerBiome === B::DESERT && $r->nextBoundedInt(500) === 0 && count($surfaceStarts) === 0){
			$this->desertWell($w, $baseX + 8, $baseZ + 8);
		}

		//trees (vanilla adds an occasional extra tree to most biomes)
		$count = $trees + ($r->nextBoundedInt(10) === 0 ? 1 : 0);
		for($i = 0; $i < $count; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$z = $baseZ + $r->nextBoundedInt(16);
			if($inVillage($x, $z)){
				continue;
			}
			[, $kinds] = $this->profile($columnBiome($x, $z));
			$this->tree($w, $x, $z, $this->pickWeighted($kinds, $r), $r);
		}

		//grass, ferns and double plants
		$tallGrass = VanillaBlocks::TALL_GRASS();
		$fern = VanillaBlocks::FERN();
		for($i = 0; $i < $grassPatches; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$z = $baseZ + $r->nextBoundedInt(16);
			if($inVillage($x, $z)){
				continue;
			}
			$biome = $columnBiome($x, $z);
			$taiga = in_array($biome, [B::TAIGA, B::MEGA_TAIGA, B::REDWOOD_TAIGA_MUTATED, B::COLD_TAIGA, B::JUNGLE, B::JUNGLE_EDGE, B::BAMBOO_JUNGLE], true);
			if($r->nextBoundedInt(10) === 0){
				$this->doublePlant($w, $x, $z, $taiga ? VanillaBlocks::LARGE_FERN() : VanillaBlocks::DOUBLE_TALLGRASS());
			}else{
				$this->plant($w, $x, $z, $taiga && $r->nextBoolean() ? $fern : $tallGrass);
			}
		}

		//flowers
		for($i = 0; $i < $flowerCount; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$z = $baseZ + $r->nextBoundedInt(16);
			if($inVillage($x, $z)){
				continue;
			}
			[, , , $pool] = $this->profile($columnBiome($x, $z));
			if(count($pool) > 0){
				$this->plant($w, $x, $z, $pool[$r->nextBoundedInt(count($pool))]);
			}
		}

		$this->biomeSpecials($w, $baseX, $baseZ, $centerBiome, $r, $inVillage);
	}

	private function biomeSpecials(BlockWriter $w, int $baseX, int $baseZ, int $biome, Random $r, \Closure $inVillage) : void{
		$rx = fn() => $baseX + $r->nextBoundedInt(16);
		$rz = fn() => $baseZ + $r->nextBoundedInt(16);
		switch($biome){
			case B::SUNFLOWER_PLAINS:
				for($i = 0; $i < 10; ++$i){
					$this->doublePlant($w, $rx(), $rz(), VanillaBlocks::SUNFLOWER());
				}
				break;
			case B::FLOWER_FOREST:
			case B::FOREST:
				for($i = 0, $n = $biome === B::FLOWER_FOREST ? 6 : 1; $i < $n; ++$i){
					$pick = [VanillaBlocks::LILAC(), VanillaBlocks::ROSE_BUSH(), VanillaBlocks::PEONY()][$r->nextBoundedInt(3)];
					$this->doublePlant($w, $rx(), $rz(), $pick);
				}
				break;
			case B::DESERT:
			case B::MESA:
			case B::MESA_BRYCE:
				$cactus = VanillaBlocks::CACTUS();
				for($i = 0; $i < ($biome === B::DESERT ? 3 : 2); ++$i){
					$x = $rx();
					$z = $rz();
					$y = $this->ground($w, $x, $z);
					if($y === null || $inVillage($x, $z) || !in_array($w->get($x, $y, $z), [$this->sand, $this->redSand], true)){
						continue;
					}
					$clear = true;
					foreach(Facing::HORIZONTAL as $face){
						[$ox, , $oz] = Facing::OFFSET[$face];
						if($w->get($x + $ox, $y + 1, $z + $oz) !== $this->air){
							$clear = false;
						}
					}
					if($clear){
						for($h = 1, $height = 1 + $r->nextBoundedInt(3); $h <= $height; ++$h){
							$w->setBlock($x, $y + $h, $z, $cactus);
						}
					}
				}
				for($i = 0; $i < 3; ++$i){
					$x = $rx();
					$z = $rz();
					$y = $this->ground($w, $x, $z);
					if($y !== null && $w->get($x, $y + 1, $z) === $this->air && !in_array($w->get($x, $y, $z), [$this->water], true)){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::DEAD_BUSH());
					}
				}
				break;
			case B::TAIGA:
			case B::COLD_TAIGA:
			case B::MEGA_TAIGA:
			case B::REDWOOD_TAIGA_MUTATED:
				if($r->nextBoundedInt(8) === 0){
					for($i = 0; $i < 4; ++$i){
						$this->plant($w, $rx(), $rz(), VanillaBlocks::SWEET_BERRY_BUSH()->setAge(3));
					}
				}
				break;
			case B::SWAMPLAND:
			case B::MANGROVE_SWAMP:
				for($i = 0; $i < 4; ++$i){
					$x = $rx();
					$z = $rz();
					$y = $w->highest($x, $z);
					if($y !== null && $w->get($x, $y, $z) === $this->water && $w->get($x, $y + 1, $z) === $this->air){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::LILY_PAD());
					}
				}
				for($i = 0; $i < 2; ++$i){
					$this->plant($w, $rx(), $rz(), $r->nextBoolean() ? VanillaBlocks::RED_MUSHROOM() : VanillaBlocks::BROWN_MUSHROOM());
				}
				break;
			case B::MUSHROOM_ISLAND:
				for($i = 0; $i < 3; ++$i){
					$this->plant($w, $rx(), $rz(), $r->nextBoolean() ? VanillaBlocks::RED_MUSHROOM() : VanillaBlocks::BROWN_MUSHROOM());
				}
				break;
			case B::BAMBOO_JUNGLE:
			case B::JUNGLE:
				$bamboo = $biome === B::BAMBOO_JUNGLE ? 40 : 4;
				for($i = 0; $i < $bamboo; ++$i){
					$x = $rx();
					$z = $rz();
					$y = $this->ground($w, $x, $z);
					if($y === null || $inVillage($x, $z) || !$this->onSoil($w, $x, $y, $z)){
						continue;
					}
					$height = 5 + $r->nextBoundedInt(9);
					for($h = 1; $h <= $height && $w->get($x, $y + $h, $z) === $this->air; ++$h){
						$w->setBlock($x, $y + $h, $z, VanillaBlocks::BAMBOO()->setThick($h < $height - 2)->setLeafSize($h >= $height - 2 ? 2 : 0));
					}
				}
				if($r->nextBoundedInt(4) === 0){
					$this->plant($w, $rx(), $rz(), VanillaBlocks::MELON(), true);
				}
				break;
			case B::CHERRY_GROVE:
				for($i = 0; $i < 12; ++$i){
					$this->plant($w, $rx(), $rz(), VanillaBlocks::PINK_PETALS()->setCount(1 + $r->nextBoundedInt(4)));
				}
				break;
			case B::WARM_OCEAN:
				//coral reefs
				for($i = 0; $i < 12; ++$i){
					$x = $rx();
					$z = $rz();
					$y = $this->seaFloor($w, $x, $z);
					if($y === null){
						continue;
					}
					$type = CoralType::cases()[$r->nextBoundedInt(count(CoralType::cases()))];
					$height = 1 + $r->nextBoundedInt(3);
					for($h = 0; $h < $height; ++$h){
						$w->setBlock($x, $y + $h, $z, VanillaBlocks::CORAL_BLOCK()->setCoralType($type));
					}
					if($w->get($x, $y + $height, $z) === $this->water){
						$w->setBlock($x, $y + $height, $z, $r->nextBoolean() ? VanillaBlocks::CORAL()->setCoralType($type) : VanillaBlocks::SEA_PICKLE()->setCount(1 + $r->nextBoundedInt(4))->setUnderwater(true));
					}
				}
				break;
		}

		//pumpkins in grassy biomes, sugar cane along water
		if($r->nextBoundedInt(64) === 0){
			for($i = 0; $i < 4; ++$i){
				$this->plant($w, $rx(), $rz(), VanillaBlocks::PUMPKIN());
			}
		}
		for($i = 0; $i < 10; ++$i){
			$x = $rx();
			$z = $rz();
			$y = $this->ground($w, $x, $z);
			if($y === null || $y < 60 || $y > 66 || $w->get($x, $y + 1, $z) !== $this->air){
				continue;
			}
			$ground = $w->get($x, $y, $z);
			if($ground !== $this->grass && $ground !== $this->dirt && $ground !== $this->sand){
				continue;
			}
			$nearWater = false;
			foreach(Facing::HORIZONTAL as $face){
				[$ox, , $oz] = Facing::OFFSET[$face];
				if($w->get($x + $ox, $y, $z + $oz) === $this->water){
					$nearWater = true;
				}
			}
			if($nearWater){
				for($h = 1, $height = 1 + $r->nextBoundedInt(3); $h <= $height; ++$h){
					$w->setBlock($x, $y + $h, $z, VanillaBlocks::SUGARCANE());
				}
			}
		}
	}

	private function seaFloor(BlockWriter $w, int $x, int $z) : ?int{
		$y = $w->highest($x, $z);
		if($y === null || $w->get($x, $y, $z) !== $this->water){
			return null;
		}
		while($y > Overworld::MIN_Y && $w->get($x, $y, $z) === $this->water){
			--$y;
		}
		return $y + 1;
	}

	private function desertWell(BlockWriter $w, int $x, int $z) : void{
		$y = $this->ground($w, $x, $z);
		if($y === null || $w->get($x, $y, $z) !== $this->sand){
			return;
		}
		$sandstone = VanillaBlocks::SANDSTONE();
		$slab = VanillaBlocks::SANDSTONE_SLAB();
		$water = VanillaBlocks::WATER();
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				$w->setBlock($x + $dx, $y, $z + $dz, $sandstone);
				$edge = abs($dx) === 2 || abs($dz) === 2;
				$w->setBlock($x + $dx, $y + 1, $z + $dz, $edge ? $slab : (abs($dx) + abs($dz) <= 1 ? ($dx === 0 && $dz === 0 ? $water : $water) : $sandstone));
				for($h = 2; $h <= 4; ++$h){
					$w->setBlock($x + $dx, $y + $h, $z + $dz, VanillaBlocks::AIR());
				}
			}
		}
		$w->setBlock($x, $y + 1, $z, $water);
		foreach([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dx, $dz]){
			$w->setBlock($x + $dx, $y + 2, $z + $dz, $sandstone);
			$w->setBlock($x + $dx, $y + 3, $z + $dz, $sandstone);
		}
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$w->setBlock($x + $dx, $y + 4, $z + $dz, $dx === 0 && $dz === 0 ? $sandstone : $slab);
			}
		}
		$w->setBlock($x, $y, $z, $water);
	}
}
