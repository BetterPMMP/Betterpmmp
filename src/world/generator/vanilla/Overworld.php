<?php

/*
 * BetterPMMP - vanilla-style overworld generator.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\utils\DirtType;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\noise\Simplex;
use function abs;
use function ceil;
use function count;
use function floor;
use function intdiv;
use function max;
use function min;
use function sqrt;
use function cos;
use function sin;
use const M_PI;

/**
 * Overworld generator modelled on vanilla 1.18+:
 *  - multi-noise biomes (see {@link OverworldBiomeBuilder})
 *  - terrain from continentalness / erosion / weirdness with 3D noise
 *  - world height -64..319, deepslate below y=0, bedrock floor
 *  - cheese, spaghetti and noodle caves, lava below y=-55
 *  - cave biomes (lush caves, dripstone caves, deep dark)
 *  - biome surface rules, 1.18 ore distribution, vegetation and villages
 *
 * Terrain is interpolated from a 4x4x4 density grid, like vanilla's cells.
 */
class Overworld extends Generator{

	public const MIN_Y = -64;
	public const MAX_Y = 320;
	private const CELL = 4;
	private const LAVA_LEVEL = -55;

	private OverworldClimate $climate;
	private Simplex $terrainNoise;
	private Simplex $cheese;
	private Simplex $spaghettiA;
	private Simplex $spaghettiB;
	private Simplex $spaghettiWidth;
	private Simplex $noodleA;
	private Simplex $noodleB;
	private Simplex $noodleToggle;
	private Simplex $entrance;
	private Simplex $surfaceNoise;
	private OverworldPopulator $populator;

	/** @var int[] terracotta band state ids, indexed by y & 63 */
	private array $terracottaBands = [];

	private int $air;
	private int $stone;
	private int $deepslate;
	private int $bedrock;
	private int $water;
	private int $lava;
	private int $grass;
	private int $dirt;
	private int $coarseDirt;
	private int $podzol;
	private int $sand;
	private int $redSand;
	private int $sandstone;
	private int $redSandstone;
	private int $gravel;
	private int $clay;
	private int $mycelium;
	private int $snowBlock;
	private int $snowLayer;
	private int $ice;
	private int $packedIce;
	private int $terracotta;
	private int $calcite;
	private int $mud;

	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);
		$this->climate = new OverworldClimate($seed);
		$r = new Random($seed ^ 0x51ED270B);
		$this->terrainNoise = new Simplex($r, 3, 0.5, 1 / 72);
		$this->cheese = new Simplex($r, 2, 0.5, 1 / 64);
		$this->spaghettiA = new Simplex($r, 1, 0.5, 1 / 56);
		$this->spaghettiB = new Simplex($r, 1, 0.5, 1 / 56);
		$this->spaghettiWidth = new Simplex($r, 1, 0.5, 1 / 160);
		$this->noodleA = new Simplex($r, 1, 0.5, 1 / 36);
		$this->noodleB = new Simplex($r, 1, 0.5, 1 / 36);
		$this->noodleToggle = new Simplex($r, 1, 0.5, 1 / 90);
		$this->entrance = new Simplex($r, 2, 0.5, 1 / 110);
		$this->surfaceNoise = new Simplex($r, 2, 0.5, 1 / 24);

		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->stone = VanillaBlocks::STONE()->getStateId();
		$this->deepslate = VanillaBlocks::DEEPSLATE()->getStateId();
		$this->bedrock = VanillaBlocks::BEDROCK()->getStateId();
		$this->water = VanillaBlocks::WATER()->getStateId();
		$this->lava = VanillaBlocks::LAVA()->getStateId();
		$this->grass = VanillaBlocks::GRASS()->getStateId();
		$this->dirt = VanillaBlocks::DIRT()->getStateId();
		$this->coarseDirt = VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE)->getStateId();
		$this->podzol = VanillaBlocks::PODZOL()->getStateId();
		$this->sand = VanillaBlocks::SAND()->getStateId();
		$this->redSand = VanillaBlocks::RED_SAND()->getStateId();
		$this->sandstone = VanillaBlocks::SANDSTONE()->getStateId();
		$this->redSandstone = VanillaBlocks::RED_SANDSTONE()->getStateId();
		$this->gravel = VanillaBlocks::GRAVEL()->getStateId();
		$this->clay = VanillaBlocks::CLAY()->getStateId();
		$this->mycelium = VanillaBlocks::MYCELIUM()->getStateId();
		$this->snowBlock = VanillaBlocks::SNOW()->getStateId();
		$this->snowLayer = VanillaBlocks::SNOW_LAYER()->getStateId();
		$this->ice = VanillaBlocks::ICE()->getStateId();
		$this->packedIce = VanillaBlocks::PACKED_ICE()->getStateId();
		$this->terracotta = VanillaBlocks::HARDENED_CLAY()->getStateId();
		$this->calcite = VanillaBlocks::CALCITE()->getStateId();
		$this->mud = VanillaBlocks::MUD()->getStateId();

		$this->buildTerracottaBands(new Random($seed ^ 0x7E44AC07));
		$this->populator = new OverworldPopulator($seed, $this->climate);
	}

	public function getClimate() : OverworldClimate{
		return $this->climate;
	}

	/** Vanilla-like badlands strata: mostly plain terracotta with coloured bands. */
	private function buildTerracottaBands(Random $random) : void{
		$colors = [DyeColor::ORANGE, DyeColor::YELLOW, DyeColor::BROWN, DyeColor::RED, DyeColor::WHITE, DyeColor::LIGHT_GRAY];
		$bands = [];
		for($i = 0; $i < 64; ++$i){
			$bands[$i] = $this->terracotta;
		}
		for($i = 0; $i < 64; ++$i){
			$i += $random->nextBoundedInt(4) + 1;
			if($i >= 64){
				break;
			}
			$color = $colors[$random->nextBoundedInt(count($colors))];
			$width = 1 + $random->nextBoundedInt(3);
			$state = VanillaBlocks::STAINED_CLAY()->setColor($color)->getStateId();
			for($j = 0; $j < $width && $i + $j < 64; ++$j){
				$bands[$i + $j] = $state;
			}
			$i += $width;
		}
		$this->terracottaBands = $bands;
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");
		$baseX = $chunkX << Chunk::COORD_BIT_SIZE;
		$baseZ = $chunkZ << Chunk::COORD_BIT_SIZE;

		//1. climate & terrain shape on the 5x5 corner grid (every 4 blocks)
		$gridH = [];
		$gridS = [];
		$gridClimate = [];
		for($gx = 0; $gx <= 4; ++$gx){
			for($gz = 0; $gz <= 4; ++$gz){
				$x = $baseX + $gx * self::CELL;
				$z = $baseZ + $gz * self::CELL;
				$climate = $this->climate->sample($x, $z);
				[$h, $s] = $this->climate->terrain($x, $z, $climate[2], $climate[3], $climate[4]);
				$gridH[$gx][$gz] = $h;
				$gridS[$gx][$gz] = $s;
				$gridClimate[$gx][$gz] = $climate;
			}
		}

		//2. biomes per 4x4 column cell (sampled at the cell centre)
		$surfaceBiome = [];
		$cellClimate = [];
		for($cx = 0; $cx < 4; ++$cx){
			for($cz = 0; $cz < 4; ++$cz){
				$climate = $this->climate->sample($baseX + $cx * 4 + 2, $baseZ + $cz * 4 + 2);
				$cellClimate[$cx][$cz] = $climate;
				$surfaceBiome[$cx][$cz] = OverworldBiomeBuilder::pick(...$climate);
			}
		}

		//3. per-column interpolated surface height
		$colH = [];
		$maxH = -1000.0;
		$minH = 1000.0;
		$maxS = 0.0;
		for($x = 0; $x < 16; ++$x){
			$gx = $x >> 2;
			$fx = ($x & 3) / 4;
			for($z = 0; $z < 16; ++$z){
				$gz = $z >> 2;
				$fz = ($z & 3) / 4;
				$h = self::bilerp($gridH[$gx][$gz], $gridH[$gx + 1][$gz], $gridH[$gx][$gz + 1], $gridH[$gx + 1][$gz + 1], $fx, $fz);
				$colH[$x][$z] = $h;
			}
		}
		foreach($gridH as $gx => $row){
			foreach($row as $gz => $h){
				$maxH = max($maxH, $h + $gridS[$gx][$gz] * 1.6);
				$minH = min($minH, $h - $gridS[$gx][$gz] * 1.6);
				$maxS = max($maxS, $gridS[$gx][$gz]);
			}
		}

		//4. density grid (terrain + caves)
		$yTop = (int) min(self::MAX_Y - 4, ceil((max($maxH, OverworldClimate::SEA_LEVEL) + 4) / self::CELL) * self::CELL);
		$ny = intdiv($yTop - self::MIN_Y, self::CELL);
		$density = [];
		for($gx = 0; $gx <= 4; ++$gx){
			for($gz = 0; $gz <= 4; ++$gz){
				$x = $baseX + $gx * self::CELL;
				$z = $baseZ + $gz * self::CELL;
				$h = $gridH[$gx][$gz];
				$s = $gridS[$gx][$gz];
				$col = [];
				for($gy = 0; $gy <= $ny; ++$gy){
					$y = self::MIN_Y + $gy * self::CELL;
					$base = ($h - $y) / $s;
					if($base > 2.5){
						$t = 1.0;
					}elseif($base < -2.5){
						$t = -1.0;
					}else{
						$t = $base + $this->terrainNoise->noise3D($x, $y * 1.25, $z, true) * 1.15;
						$t = $t > 1 ? 1.0 : ($t < -1 ? -1.0 : $t);
					}
					if($t > 0 && $y < $h - 5 && $y > self::MIN_Y + 4){
						$t = min($t, $this->caveDensity($x, $y, $z, $h));
					}
					$col[$gy] = $t;
				}
				$density[$gx][$gz] = $col;
			}
		}

		//5. fill blocks
		$subChunks = [];
		$surfaceBiomeArray = new PalettedBlockArray(B::PLAINS);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biome = $surfaceBiome[$x >> 2][$z >> 2];
				for($y = 0; $y < 16; ++$y){
					$surfaceBiomeArray->set($x, $y, $z, $biome);
				}
			}
		}
		$minSub = Chunk::MIN_SUBCHUNK_INDEX;
		$maxSub = Chunk::MAX_SUBCHUNK_INDEX;
		$solidBelow = (int) floor($minH) - 8;
		$fills = [];
		for($sy = $minSub; $sy <= $maxSub; ++$sy){
			$fill = (($sy + 1) << 4) <= $solidBelow ? ($sy < 0 ? $this->deepslate : $this->stone) : $this->air;
			$fills[$sy] = $fill;
			$subChunks[$sy] = new SubChunk($this->air, $fill === $this->air ? [] : [new PalettedBlockArray($fill)], clone $surfaceBiomeArray);
		}

		$random = new Random($this->seed ^ ($chunkX * 341873128712) ^ ($chunkZ * 132897987541));
		for($cx = 0; $cx < 4; ++$cx){
			for($cz = 0; $cz < 4; ++$cz){
				$d00 = $density[$cx][$cz];
				$d10 = $density[$cx + 1][$cz];
				$d01 = $density[$cx][$cz + 1];
				$d11 = $density[$cx + 1][$cz + 1];
				for($gy = 0; $gy < $ny; ++$gy){
					$a = $d00[$gy];
					$b = $d10[$gy];
					$c = $d01[$gy];
					$d = $d11[$gy];
					$e = $d00[$gy + 1];
					$f = $d10[$gy + 1];
					$g = $d01[$gy + 1];
					$hh = $d11[$gy + 1];
					$y0 = self::MIN_Y + $gy * self::CELL;
					$sub = $subChunks[$y0 >> 4];
					$fill = $fills[$y0 >> 4];
					if($fill !== $this->air && ($y0 >= 8 || $y0 < 0) && $a > 0 && $b > 0 && $c > 0 && $d > 0 && $e > 0 && $f > 0 && $g > 0 && $hh > 0){
						continue; //entirely solid cell inside a pre-filled sub chunk
					}
					for($ly = 0; $ly < 4; ++$ly){
						$fy = $ly / 4;
						$y = $y0 + $ly;
						$ry = $y & 15;
						$v0 = $a + ($e - $a) * $fy;
						$v1 = $b + ($f - $b) * $fy;
						$v2 = $c + ($g - $c) * $fy;
						$v3 = $d + ($hh - $d) * $fy;
						for($lx = 0; $lx < 4; ++$lx){
							$fx = $lx / 4;
							$w0 = $v0 + ($v1 - $v0) * $fx;
							$w1 = $v2 + ($v3 - $v2) * $fx;
							$x = ($cx << 2) + $lx;
							for($lz = 0; $lz < 4; ++$lz){
								$z = ($cz << 2) + $lz;
								$value = $w0 + ($w1 - $w0) * ($lz / 4);
								if($value > 0){
									if($y < 0 || ($y < 8 && $random->nextBoundedInt(8) >= $y)){
										$state = $this->deepslate;
									}else{
										$state = $this->stone;
									}
								}elseif($y < OverworldClimate::SEA_LEVEL && $y > $colH[$x][$z] - 5){
									$state = $this->water;
								}elseif($y <= self::LAVA_LEVEL){
									$state = $this->lava;
								}else{
									$state = $this->air;
								}
								if($state !== $fill){
									$sub->setBlockStateId($x, $ry, $z, $state);
								}
							}
						}
					}
				}
			}
		}

		//water above the density grid top (deep oceans are covered by the grid already)
		//bedrock floor
		$bottom = $subChunks[self::MIN_Y >> 4];
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$bottom->setBlockStateId($x, 0, $z, $this->bedrock);
				for($y = 1; $y < 5; ++$y){
					if($random->nextBoundedInt(5) >= $y){
						$bottom->setBlockStateId($x, $y, $z, $this->bedrock);
					}
				}
			}
		}

		//6. cave biomes
		$this->applyCaveBiomes($subChunks, $baseX, $baseZ, $colH, $cellClimate);

		foreach($subChunks as $sy => $sub){
			$chunk->setSubChunk($sy, $sub);
		}

		//7. surface rules
		$this->buildSurface($chunk, $baseX, $baseZ, $yTop, $colH, $surfaceBiome, $random);

		//8. ravines (canyon carver), carved after the surface like vanilla
		$this->carveRavines($chunk, $chunkX, $chunkZ, $colH);
	}

	/**
	 * Canyon carver: long, tall and narrow ravines. Every chunk has a 1% chance
	 * to start one; a ravine can reach 8 chunks away, so the carver looks at all
	 * starts within that range and carves the part inside this chunk.
	 * @param float[][] $colH
	 */
	private function carveRavines(Chunk $chunk, int $chunkX, int $chunkZ, array $colH) : void{
		$minX = $chunkX << 4;
		$minZ = $chunkZ << 4;
		for($cx = $chunkX - 8; $cx <= $chunkX + 8; ++$cx){
			for($cz = $chunkZ - 8; $cz <= $chunkZ + 8; ++$cz){
				$r = new Random(($this->seed ^ ($cx * 0x3C6EF35F) ^ ($cz * 0x47502932) ^ 0x52617669) & 0x7fffffffffff);
				if($r->nextFloat() >= 0.01){
					continue;
				}
				$x = ($cx << 4) + $r->nextBoundedInt(16);
				$y = 10 + $r->nextBoundedInt(58);
				$z = ($cz << 4) + $r->nextBoundedInt(16);
				$yaw = $r->nextFloat() * M_PI * 2;
				$pitch = ($r->nextFloat() - 0.5) * 0.25;
				$width = ($r->nextFloat() * 2 + $r->nextFloat()) * 2;
				$steps = 112 - $r->nextBoundedInt(28);
				$yawChange = 0.0;
				$pitchChange = 0.0;
				for($i = 0; $i < $steps; ++$i){
					$h = 1.5 + sin($i * M_PI / $steps) * $width;
					$v = $h * 3.0;
					$x += cos($yaw) * cos($pitch);
					$y += sin($pitch);
					$z += sin($yaw) * cos($pitch);
					$pitch *= 0.7;
					$pitch += $pitchChange * 0.05;
					$yaw += $yawChange * 0.05;
					$pitchChange = $pitchChange * 0.8 + ($r->nextFloat() - $r->nextFloat()) * $r->nextFloat() * 2;
					$yawChange = $yawChange * 0.5 + ($r->nextFloat() - $r->nextFloat()) * $r->nextFloat() * 4;
					if($x + $h < $minX || $x - $h > $minX + 15 || $z + $h < $minZ || $z - $h > $minZ + 15){
						continue;
					}
					$this->carveEllipsoid($chunk, $minX, $minZ, $x, $y, $z, $h, $v, $colH);
				}
			}
		}
	}

	/** @param float[][] $colH */
	private function carveEllipsoid(Chunk $chunk, int $minX, int $minZ, float $cx, float $cy, float $cz, float $h, float $v, array $colH) : void{
		$x1 = max(0, (int) floor($cx - $h) - $minX);
		$x2 = min(15, (int) ceil($cx + $h) - $minX);
		$z1 = max(0, (int) floor($cz - $h) - $minZ);
		$z2 = min(15, (int) ceil($cz + $h) - $minZ);
		$y1 = max(self::MIN_Y + 5, (int) floor($cy - $v));
		$y2 = min(self::MAX_Y - 1, (int) ceil($cy + $v));
		for($lx = $x1; $lx <= $x2; ++$lx){
			$dx = ($lx + $minX + 0.5 - $cx) / $h;
			for($lz = $z1; $lz <= $z2; ++$lz){
				$dz = ($lz + $minZ + 0.5 - $cz) / $h;
				$horizontal = $dx * $dx + $dz * $dz;
				if($horizontal >= 1){
					continue;
				}
				//never cut open the sea floor
				$limit = $colH[$lx][$lz] < OverworldClimate::SEA_LEVEL ? $colH[$lx][$lz] - 6 : self::MAX_Y;
				for($y = $y1; $y <= $y2 && $y < $limit; ++$y){
					$dy = ($y + 0.5 - $cy) / $v;
					//vanilla ravine walls get steeper towards the bottom
					if($horizontal * (1 + max(0, -$dy) * 0.3) + $dy * $dy >= 1){
						continue;
					}
					$id = $chunk->getBlockStateId($lx, $y, $lz);
					if($id === $this->water || $id === $this->lava || $id === $this->bedrock || $id === $this->air){
						continue;
					}
					$chunk->setBlockStateId($lx, $y, $lz, $y <= self::LAVA_LEVEL ? $this->lava : $this->air);
				}
			}
		}
	}

	private static function bilerp(float $q00, float $q10, float $q01, float $q11, float $fx, float $fz) : float{
		$a = $q00 + ($q10 - $q00) * $fx;
		$b = $q01 + ($q11 - $q01) * $fx;
		return $a + ($b - $a) * $fz;
	}

	/**
	 * Cave density: negative inside caves. Combines cheese caves (large
	 * caverns with pillars), spaghetti caves (long tunnels) and noodle caves
	 * (thin winding tunnels), similar to vanilla 1.18.
	 */
	private function caveDensity(int $x, int $y, int $z, float $surface) : float{
		$depth = $surface - $y;

		//cheese caves: big caverns, kept away from the surface
		$cheese = $this->cheese->noise3D($x, $y * 1.7, $z, true);
		$carve = ($cheese - 0.36) * 3.0;
		if($depth < 24){
			$carve -= (24 - $depth) * 0.07;
		}
		if($y < self::MIN_Y + 12){
			$carve -= (self::MIN_Y + 12 - $y) * 0.12;
		}

		//spaghetti caves: tunnels where two noise fields cross zero; may open to the surface as entrances
		$a = $this->spaghettiA->noise3D($x, $y * 1.5, $z, true);
		$b = $this->spaghettiB->noise3D($x + 1000, $y * 1.5, $z - 1000, true);
		$width = 0.075 + 0.035 * $this->spaghettiWidth->noise3D($x, $y, $z, true);
		$spaghetti = ($width - sqrt($a * $a + $b * $b)) * 9.0;
		if($depth < 10){
			$open = $this->entrance->noise2D($x, $z, true);
			if($open < 0.25){
				$spaghetti -= (10 - $depth) * 0.12;
			}
		}
		$carve = max($carve, $spaghetti);

		//noodle caves
		if($y < 120 && $this->noodleToggle->noise3D($x, $y, $z, true) > 0){
			$na = $this->noodleA->noise3D($x, $y * 1.3, $z, true);
			$nb = $this->noodleB->noise3D($x - 500, $y * 1.3, $z + 500, true);
			$noodle = (0.045 - sqrt($na * $na + $nb * $nb)) * 12.0;
			if($depth < 12){
				$noodle -= (12 - $depth) * 0.1;
			}
			$carve = max($carve, $noodle);
		}

		return -$carve;
	}

	/**
	 * @param SubChunk[] $subChunks
	 * @param float[][]  $colH
	 * @param float[][][] $cellClimate
	 */
	private function applyCaveBiomes(array $subChunks, int $baseX, int $baseZ, array $colH, array $cellClimate) : void{
		for($cx = 0; $cx < 4; ++$cx){
			for($cz = 0; $cz < 4; ++$cz){
				[$t, $h, $c, $e] = $cellClimate[$cx][$cz];
				$surface = $colH[$cx * 4 + 2][$cz * 4 + 2];
				for($y = self::MIN_Y; $y < $surface - 24; $y += 4){
					$biome = null;
					if($e < -0.45 && $y < -16 && $surface > 70){
						$biome = B::DEEP_DARK;
					}elseif($h > 0.55){
						$biome = B::LUSH_CAVES;
					}elseif($c > 0.65){
						$biome = B::DRIPSTONE_CAVES;
					}
					if($biome === null){
						continue;
					}
					$sub = $subChunks[$y >> 4];
					$biomes = $sub->getBiomeArray();
					for($lx = 0; $lx < 4; ++$lx){
						for($lz = 0; $lz < 4; ++$lz){
							for($ly = 0; $ly < 4; ++$ly){
								$biomes->set($cx * 4 + $lx, ($y + $ly) & 15, $cz * 4 + $lz, $biome);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Applies biome surface rules to the topmost terrain of every column.
	 * @param float[][] $colH
	 * @param int[][]   $surfaceBiome
	 */
	private function buildSurface(Chunk $chunk, int $baseX, int $baseZ, int $yTop, array $colH, array $surfaceBiome, Random $random) : void{
		$sea = OverworldClimate::SEA_LEVEL - 1;
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biome = $surfaceBiome[$x >> 2][$z >> 2];
				$wx = $baseX + $x;
				$wz = $baseZ + $z;
				$noise = $this->surfaceNoise->noise2D($wx, $wz, true);
				$depth = 3 + (int) ($noise * 2 + 1.5);

				//steepness from neighbouring column heights
				$h = $colH[$x][$z];
				$nx = $colH[min(15, $x + 1)][$z] - $colH[max(0, $x - 1)][$z];
				$nz = $colH[$x][min(15, $z + 1)] - $colH[$x][max(0, $z - 1)];
				$steep = abs($nx) + abs($nz) > 4.5;

				//find the top solid block
				$top = null;
				for($y = $yTop; $y > self::MIN_Y; --$y){
					$id = $chunk->getBlockStateId($x, $y, $z);
					if($id === $this->stone || $id === $this->deepslate){
						$top = $y;
						break;
					}
					if($id !== $this->air && $id !== $this->water){
						break;
					}
				}
				if($top === null){
					continue;
				}
				$underwater = $top < $sea && $chunk->getBlockStateId($x, $top + 1, $z) === $this->water;
				$shore = false;
				if(!$underwater && $top <= OverworldClimate::SEA_LEVEL + 1){
					foreach([[2, 0], [-2, 0], [0, 2], [0, -2], [3, 3], [-3, -3]] as [$dx, $dz]){
						$sx = $x + $dx;
						$sz = $z + $dz;
						if($sx >= 0 && $sx < 16 && $sz >= 0 && $sz < 16 && $chunk->getBlockStateId($sx, $sea, $sz) === $this->water){
							$shore = true;
							break;
						}
					}
				}
				[$topBlock, $filler, $under, $snowy] = $this->surfaceBlocks($biome, $top, $underwater, $steep, $noise, $random, $shore);

				if($biome === B::MESA || $biome === B::MESA_BRYCE || $biome === B::MESA_PLATEAU_STONE){
					$this->buildBadlandsColumn($chunk, $x, $z, $top, $biome, $underwater, $noise);
				}else{
					for($i = 0; $i <= $depth + 3; ++$i){
						$y = $top - $i;
						if($y <= self::MIN_Y){
							break;
						}
						$id = $chunk->getBlockStateId($x, $y, $z);
						if($id !== $this->stone && $id !== $this->deepslate){
							break;
						}
						if($i === 0){
							$chunk->setBlockStateId($x, $y, $z, $topBlock);
						}elseif($i <= $depth){
							$chunk->setBlockStateId($x, $y, $z, $filler);
						}elseif($under !== null){
							$chunk->setBlockStateId($x, $y, $z, $under);
						}
					}
				}

				//snow, ice
				$aboveTop = $top + 1;
				if($snowy && !$underwater && $aboveTop < self::MAX_Y && $chunk->getBlockStateId($x, $aboveTop, $z) === $this->air){
					$chunk->setBlockStateId($x, $aboveTop, $z, $this->snowLayer);
				}
				if($this->isFrozenWaterBiome($biome) && $top < $sea && $chunk->getBlockStateId($x, $sea, $z) === $this->water){
					if($biome !== B::FROZEN_OCEAN && $biome !== B::DEEP_FROZEN_OCEAN || $noise > -0.3){
						$chunk->setBlockStateId($x, $sea, $z, $biome === B::DEEP_FROZEN_OCEAN && $noise > 0.4 ? $this->packedIce : $this->ice);
					}
				}
				//high mountains are snow capped
				if(!$underwater && $top > 165 + (int) ($noise * 12) && !$this->isHotBiome($biome) && $chunk->getBlockStateId($x, $aboveTop, $z) === $this->air){
					$chunk->setBlockStateId($x, $top, $z, $this->snowBlock);
				}
			}
		}
	}

	private function isHotBiome(int $biome) : bool{
		return match($biome){
			B::DESERT, B::MESA, B::MESA_BRYCE, B::MESA_PLATEAU_STONE, B::SAVANNA, B::SAVANNA_PLATEAU, B::SAVANNA_MUTATED,
			B::JUNGLE, B::JUNGLE_EDGE, B::BAMBOO_JUNGLE, B::STONY_PEAKS, B::MANGROVE_SWAMP, B::WARM_OCEAN => true,
			default => false
		};
	}

	private function isFrozenWaterBiome(int $biome) : bool{
		return match($biome){
			B::FROZEN_OCEAN, B::DEEP_FROZEN_OCEAN, B::FROZEN_RIVER, B::ICE_PLAINS, B::ICE_PLAINS_SPIKES, B::COLD_BEACH, B::COLD_TAIGA => true,
			default => false
		};
	}

	/**
	 * @return array{int, int, ?int, bool} top block, filler, block below filler (or null), snow layer on top
	 */
	private function surfaceBlocks(int $biome, int $top, bool $underwater, bool $steep, float $noise, Random $random, bool $shore) : array{
		if($underwater){
			return match($biome){
				B::WARM_OCEAN, B::LUKEWARM_OCEAN, B::DEEP_LUKEWARM_OCEAN, B::BEACH, B::DESERT => [$this->sand, $this->sand, $this->sandstone, false],
				B::SWAMPLAND => [$this->dirt, $this->dirt, null, false],
				B::MANGROVE_SWAMP => [$this->mud, $this->mud, null, false],
				B::RIVER, B::FROZEN_RIVER => $noise > 0.3 ? [$this->clay, $this->clay, null, false] : ($noise > -0.2 ? [$this->sand, $this->sand, null, false] : [$this->gravel, $this->gravel, null, false]),
				B::OCEAN, B::COLD_OCEAN, B::FROZEN_OCEAN => $noise > 0.2 ? [$this->sand, $this->sand, null, false] : [$this->gravel, $this->gravel, null, false],
				B::MUSHROOM_ISLAND => [$this->dirt, $this->dirt, null, false],
				default => [$this->gravel, $this->gravel, null, false],
			};
		}
		switch($biome){
			case B::DESERT:
				return [$this->sand, $this->sand, $this->sandstone, false];
			case B::BEACH:
				return [$this->sand, $this->sand, $this->sandstone, false];
			case B::COLD_BEACH:
				return [$this->sand, $this->sand, $this->sandstone, true];
			case B::STONE_BEACH:
			case B::STONY_PEAKS:
				return [$noise > 0.4 && $biome === B::STONY_PEAKS ? $this->calcite : $this->stone, $this->stone, null, false];
			case B::MUSHROOM_ISLAND:
				return [$this->mycelium, $this->dirt, null, false];
			case B::MEGA_TAIGA:
			case B::REDWOOD_TAIGA_MUTATED:
				return [$noise > 0.25 ? $this->podzol : ($noise < -0.45 ? $this->coarseDirt : $this->grass), $this->dirt, null, false];
			case B::ICE_PLAINS:
			case B::ICE_PLAINS_SPIKES:
			case B::COLD_TAIGA:
				return [$this->grass, $this->dirt, null, true];
			case B::GROVE:
				return [$steep ? $this->snowBlock : $this->grass, $this->dirt, null, true];
			case B::SNOWY_SLOPES:
				return [$this->snowBlock, $steep ? $this->stone : $this->snowBlock, null, false];
			case B::FROZEN_PEAKS:
				return [$noise > 0.2 ? $this->packedIce : $this->snowBlock, $steep ? $this->packedIce : $this->snowBlock, null, false];
			case B::JAGGED_PEAKS:
				return [$steep ? $this->stone : $this->snowBlock, $this->stone, null, false];
			case B::EXTREME_HILLS_MUTATED:
				return [$noise > -0.2 ? $this->gravel : $this->stone, $noise > 0 ? $this->gravel : $this->stone, null, false];
			case B::EXTREME_HILLS:
			case B::EXTREME_HILLS_PLUS_TREES:
				return [$steep ? $this->stone : $this->grass, $steep ? $this->stone : $this->dirt, null, false];
			case B::SAVANNA_MUTATED:
				return [$noise > 0.35 ? $this->coarseDirt : ($noise < -0.4 ? $this->stone : $this->grass), $this->dirt, null, false];
			case B::MANGROVE_SWAMP:
				return [$this->mud, $this->mud, null, false];
			default:
				if($steep && $top > 110){
					return [$this->stone, $this->stone, null, false];
				}
				if($shore && $noise > -0.3 && $biome !== B::SWAMPLAND){
					return [$this->sand, $this->sand, $this->sandstone, false]; //sandy shores along water
				}
				return [$this->grass, $this->dirt, null, false];
		}
	}

	private function buildBadlandsColumn(Chunk $chunk, int $x, int $z, int $top, int $biome, bool $underwater, float $noise) : void{
		$wooded = $biome === B::MESA_PLATEAU_STONE && $top > 96;
		for($i = 0; $i < 48; ++$i){
			$y = $top - $i;
			if($y <= self::MIN_Y){
				break;
			}
			$id = $chunk->getBlockStateId($x, $y, $z);
			if($id !== $this->stone && $id !== $this->deepslate){
				if($id === $this->air || $id === $this->water){
					continue;
				}
				break;
			}
			if($i === 0 && $wooded){
				$state = $noise > 0 ? $this->grass : $this->coarseDirt;
			}elseif($i <= 1 && $wooded){
				$state = $this->dirt;
			}elseif($i <= 2 && ($underwater || $top < 70)){
				$state = $i === 0 ? $this->redSand : $this->redSandstone;
			}else{
				$state = $this->terracottaBands[($y + (int) ($noise * 3)) & 63];
			}
			$chunk->setBlockStateId($x, $y, $z, $state);
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$this->populator->populate($world, $chunkX, $chunkZ);
	}

	/**
	 * Finds a spawn point on land close to the origin, like vanilla does.
	 */
	public static function findSpawn(int $seed) : Vector3{
		$climate = new OverworldClimate($seed);
		for($radius = 0; $radius <= 2048; $radius += 64){
			$steps = max(1, (int) ($radius / 32));
			for($i = 0; $i < $steps * 4; ++$i){
				$angle = $i / ($steps * 4) * 2 * M_PI;
				$x = (int) ($radius * cos($angle));
				$z = (int) ($radius * sin($angle));
				[$t, $h, $c, $e, $w] = $climate->sample($x, $z);
				$biome = OverworldBiomeBuilder::pick($t, $h, $c, $e, $w);
				[$height] = $climate->terrain($x, $z, $c, $e, $w);
				if($height > OverworldClimate::SEA_LEVEL + 1 && $height < 120 && OverworldPopulator::isSpawnFriendly($biome)){
					return new Vector3($x + 0.5, (int) ceil($height) + 8, $z + 0.5);
				}
			}
		}
		return new Vector3(0.5, 100, 0.5);
	}
}
