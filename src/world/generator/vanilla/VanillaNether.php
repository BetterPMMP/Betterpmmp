<?php

/*
 * BetterPMMP - vanilla-style nether generator.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\noise\Simplex;
use pocketmine\world\generator\object\TreeFactory;
use pocketmine\world\generator\object\TreeType;
use pocketmine\world\generator\vanilla\structure\Bastion;
use pocketmine\world\generator\vanilla\structure\NetherFortress;
use pocketmine\world\generator\vanilla\structure\RuinedPortal;
use pocketmine\world\generator\vanilla\structure\StructureContext;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use function intdiv;
use function max;
use function min;

/**
 * Nether generator modelled on vanilla 1.16+: height 0..127, bedrock floor
 * and roof, lava sea at y 31, large caverns, and the five nether biomes
 * (nether wastes, soul sand valley, crimson forest, warped forest, basalt
 * deltas) with their surfaces, vegetation, glowstone, ores and ancient debris.
 */
class VanillaNether extends Generator{

	public const HEIGHT = 128;
	public const LAVA_LEVEL = 31;
	private const CELL = 4;

	private Simplex $temperature;
	private Simplex $humidity;
	private Simplex $terrain;
	private Simplex $detail;
	private Simplex $patch;

	private int $air;
	private int $bedrock;
	private int $netherrack;
	private int $lava;
	private int $soulSand;
	private int $soulSoil;
	private int $gravel;
	private int $crimsonNylium;
	private int $warpedNylium;
	private int $basalt;
	private int $blackstone;
	private int $magma;
	private StructureManager $structures;

	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);
		$r = new Random($seed ^ 0x6E657468);
		$this->temperature = new Simplex($r, 3, 0.5, 1 / 260);
		$this->humidity = new Simplex($r, 3, 0.5, 1 / 260);
		$this->terrain = new Simplex($r, 4, 0.5, 1 / 90);
		$this->detail = new Simplex($r, 2, 0.5, 1 / 28);
		$this->patch = new Simplex($r, 2, 0.5, 1 / 18);

		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->bedrock = VanillaBlocks::BEDROCK()->getStateId();
		$this->netherrack = VanillaBlocks::NETHERRACK()->getStateId();
		$this->lava = VanillaBlocks::LAVA()->getStateId();
		$this->soulSand = VanillaBlocks::SOUL_SAND()->getStateId();
		$this->soulSoil = VanillaBlocks::SOUL_SOIL()->getStateId();
		$this->gravel = VanillaBlocks::GRAVEL()->getStateId();
		$this->crimsonNylium = VanillaBlocks::CRIMSON_NYLIUM()->getStateId();
		$this->warpedNylium = VanillaBlocks::WARPED_NYLIUM()->getStateId();
		$this->basalt = VanillaBlocks::BASALT()->getStateId();
		$this->blackstone = VanillaBlocks::BLACKSTONE()->getStateId();
		$this->magma = VanillaBlocks::MAGMA()->getStateId();
		$ctx = new StructureContext($seed, fn(int $x, int $y, int $z) : int => $this->biomeAt($x, $z), fn(int $x, int $z) : int => 64);
		$this->structures = new StructureManager($ctx, [
			new NetherFortress(),
			new Bastion(),
			new RuinedPortal(true),
		]);
	}

	public function getStructureManager() : StructureManager{
		return $this->structures;
	}

	/**
	 * Nearest of the vanilla nether biome climate points (temperature, humidity, offset).
	 */
	public function biomeAt(float $x, float $z) : int{
		$t = $this->temperature->noise2D($x, $z, true) * 1.6;
		$h = $this->humidity->noise2D($x + 3000, $z - 3000, true) * 1.6;
		static $points = [
			[B::HELL, 0.0, 0.0, 0.0],
			[B::SOULSAND_VALLEY, 0.0, -0.5, 0.0],
			[B::CRIMSON_FOREST, 0.4, 0.0, 0.0],
			[B::WARPED_FOREST, 0.0, 0.5, 0.375],
			[B::BASALT_DELTAS, -0.5, 0.0, 0.175],
		];
		$best = B::HELL;
		$bestDist = PHP_FLOAT_MAX;
		foreach($points as [$biome, $pt, $ph, $offset]){
			$d = ($t - $pt) ** 2 + ($h - $ph) ** 2 + $offset ** 2;
			if($d < $bestDist){
				$bestDist = $d;
				$best = $biome;
			}
		}
		return $best;
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;
		$random = new Random($this->seed ^ ($chunkX * 0x1B873593) ^ ($chunkZ * 0x5BD1E995));

		$biomes = new PalettedBlockArray(B::HELL);
		$columnBiome = [];
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biome = ($x & 3) === 0 && ($z & 3) === 0 ? $this->biomeAt($baseX + $x + 2, $baseZ + $z + 2) : $columnBiome[$x & ~3][$z & ~3];
				$columnBiome[$x][$z] = $biome;
				for($y = 0; $y < 16; ++$y){
					$biomes->set($x, $y, $z, $biome);
				}
			}
		}

		//density on a 4x4x4 grid: caverns in the middle, solid towards floor and roof
		$ny = intdiv(self::HEIGHT, self::CELL);
		$density = [];
		for($gx = 0; $gx <= 4; ++$gx){
			for($gz = 0; $gz <= 4; ++$gz){
				$x = $baseX + $gx * 4;
				$z = $baseZ + $gz * 4;
				$biome = $this->biomeAt($x, $z);
				$roughness = $biome === B::BASALT_DELTAS ? 1.35 : ($biome === B::SOULSAND_VALLEY ? 0.85 : 1.0);
				$col = [];
				for($gy = 0; $gy <= $ny; ++$gy){
					$y = $gy * 4;
					$shape = -0.06;
					if($y < 40){
						$shape += (40 - $y) / 40 * 0.9;
					}
					if($y > 90){
						$shape += ($y - 90) / 37 * 1.4;
					}
					$col[$gy] = $shape + $this->terrain->noise3D($x, $y * 1.9, $z, true) * 1.3 * $roughness;
				}
				$density[$gx][$gz] = $col;
			}
		}

		$subChunks = [];
		for($sy = Chunk::MIN_SUBCHUNK_INDEX; $sy <= Chunk::MAX_SUBCHUNK_INDEX; ++$sy){
			$subChunks[$sy] = new SubChunk($this->air, [], clone $biomes);
		}
		for($cx = 0; $cx < 4; ++$cx){
			for($cz = 0; $cz < 4; ++$cz){
				$d00 = $density[$cx][$cz];
				$d10 = $density[$cx + 1][$cz];
				$d01 = $density[$cx][$cz + 1];
				$d11 = $density[$cx + 1][$cz + 1];
				for($gy = 0; $gy < $ny; ++$gy){
					for($ly = 0; $ly < 4; ++$ly){
						$y = $gy * 4 + $ly;
						$fy = $ly / 4;
						$v0 = $d00[$gy] + ($d00[$gy + 1] - $d00[$gy]) * $fy;
						$v1 = $d10[$gy] + ($d10[$gy + 1] - $d10[$gy]) * $fy;
						$v2 = $d01[$gy] + ($d01[$gy + 1] - $d01[$gy]) * $fy;
						$v3 = $d11[$gy] + ($d11[$gy + 1] - $d11[$gy]) * $fy;
						$sub = $subChunks[$y >> 4];
						for($lx = 0; $lx < 4; ++$lx){
							$fx = $lx / 4;
							$w0 = $v0 + ($v1 - $v0) * $fx;
							$w1 = $v2 + ($v3 - $v2) * $fx;
							for($lz = 0; $lz < 4; ++$lz){
								$value = $w0 + ($w1 - $w0) * ($lz / 4);
								if($value > 0){
									$sub->setBlockStateId(($cx << 2) + $lx, $y & 15, ($cz << 2) + $lz, $this->netherrack);
								}elseif($y <= self::LAVA_LEVEL){
									$sub->setBlockStateId(($cx << 2) + $lx, $y & 15, ($cz << 2) + $lz, $this->lava);
								}
							}
						}
					}
				}
			}
		}
		foreach($subChunks as $sy => $sub){
			$chunk->setSubChunk($sy, $sub);
		}

		//bedrock floor and roof
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, 0, $z, $this->bedrock);
				$chunk->setBlockStateId($x, self::HEIGHT - 1, $z, $this->bedrock);
				for($i = 1; $i < 5; ++$i){
					if($random->nextBoundedInt(5) >= $i){
						$chunk->setBlockStateId($x, $i, $z, $this->bedrock);
					}
					if($random->nextBoundedInt(5) >= $i){
						$chunk->setBlockStateId($x, self::HEIGHT - 1 - $i, $z, $this->bedrock);
					}
				}
			}
		}

		$this->buildSurfaces($chunk, $baseX, $baseZ, $columnBiome, $random);
	}

	/**
	 * @param int[][] $columnBiome
	 */
	private function buildSurfaces(Chunk $chunk, int $baseX, int $baseZ, array $columnBiome, Random $random) : void{
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biome = $columnBiome[$x][$z];
				$wx = $baseX + $x;
				$wz = $baseZ + $z;
				$patch = $this->patch->noise2D($wx, $wz, true);
				$detail = $this->detail->noise2D($wx, $wz, true);
				$above = $this->air;
				for($y = self::HEIGHT - 6; $y > 4; --$y){
					$id = $chunk->getBlockStateId($x, $y, $z);
					if($id === $this->netherrack && ($above === $this->air || $above === $this->lava)){
						//floor block
						$state = match($biome){
							B::CRIMSON_FOREST => $this->crimsonNylium,
							B::WARPED_FOREST => $this->warpedNylium,
							B::SOULSAND_VALLEY => $patch > 0 ? $this->soulSoil : $this->soulSand,
							B::BASALT_DELTAS => $patch > 0.15 ? $this->blackstone : $this->basalt,
							default => ($y >= self::LAVA_LEVEL - 1 && $y <= self::LAVA_LEVEL + 4 && $patch > 0.25) ? ($detail > 0 ? $this->soulSand : $this->gravel) : $this->netherrack,
						};
						$chunk->setBlockStateId($x, $y, $z, $state);
						if($biome === B::SOULSAND_VALLEY || $biome === B::BASALT_DELTAS){
							//a few blocks deep
							for($d = 1; $d <= 2 && $chunk->getBlockStateId($x, $y - $d, $z) === $this->netherrack; ++$d){
								$chunk->setBlockStateId($x, $y - $d, $z, $biome === B::SOULSAND_VALLEY ? $this->soulSoil : $this->basalt);
							}
						}
						if($biome === B::BASALT_DELTAS && $above === $this->air && $detail > 0.45 && $y < 100){
							$chunk->setBlockStateId($x, $y, $z, $this->magma);
						}
					}elseif($biome === B::BASALT_DELTAS && $id === $this->netherrack && $patch < -0.3){
						$chunk->setBlockStateId($x, $y, $z, $this->blackstone);
					}
					$above = $id;
				}
			}
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$r = new Random($this->seed ^ ($chunkX * 0x3C6EF372) ^ ($chunkZ * 0x7F4A7C15) ^ 0x1234567);
		$w = new BlockWriter($world);
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;

		//ores
		$quartz = VanillaBlocks::NETHER_QUARTZ_ORE()->getStateId();
		$gold = VanillaBlocks::NETHER_GOLD_ORE()->getStateId();
		$debris = VanillaBlocks::ANCIENT_DEBRIS()->getStateId();
		$this->veins($w, $baseX, $baseZ, $r, $quartz, 16, 14, 10, 117);
		$this->veins($w, $baseX, $baseZ, $r, $gold, 10, 10, 10, 117);
		$this->veins($w, $baseX, $baseZ, $r, $this->magma, 4, 33, 27, 36);
		$this->veins($w, $baseX, $baseZ, $r, $debris, 1, 3, 8, 24, true);
		$this->veins($w, $baseX, $baseZ, $r, $debris, 1, 2, 8, 119, true);
		$this->veins($w, $baseX, $baseZ, $r, $this->gravel, 2, 33, 5, 41);
		$this->veins($w, $baseX, $baseZ, $r, $this->blackstone, 2, 33, 5, 31);

		$this->structures->populate($w, $chunkX, $chunkZ);

		$biome = $w->getBiome($baseX + 8, 64, $baseZ + 8);

		//glowstone clusters hanging from ceilings
		$glowstone = VanillaBlocks::GLOWSTONE()->getStateId();
		for($i = 0, $n = 10 + $r->nextBoundedInt(10); $i < $n; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$z = $baseZ + $r->nextBoundedInt(16);
			$y = 4 + $r->nextBoundedInt(120);
			if($w->get($x, $y, $z) !== $this->air || $w->get($x, $y + 1, $z) !== $this->netherrack){
				continue;
			}
			$w->set($x, $y, $z, $glowstone);
			for($j = 0; $j < 150; ++$j){
				$gx = $x + $r->nextRange(-3, 3);
				$gy = $y - $r->nextBoundedInt(8);
				$gz = $z + $r->nextRange(-3, 3);
				if($w->get($gx, $gy, $gz) !== $this->air){
					continue;
				}
				$neighbours = 0;
				foreach([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]){
					if($w->get($gx + $dx, $gy + $dy, $gz + $dz) === $glowstone){
						++$neighbours;
					}
				}
				if($neighbours === 1){
					$w->set($gx, $gy, $gz, $glowstone);
				}
			}
		}

		//floor features by biome
		$fire = VanillaBlocks::FIRE();
		$soulFire = VanillaBlocks::SOUL_FIRE();
		for($i = 0; $i < 48; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$z = $baseZ + $r->nextBoundedInt(16);
			$y = $this->randomFloor($w, $x, $z, $r);
			if($y === null){
				continue;
			}
			$ground = $w->get($x, $y, $z);
			$local = $w->getBiome($x, $y, $z);
			switch($local){
				case B::CRIMSON_FOREST:
					if($ground !== $this->crimsonNylium){
						break;
					}
					$roll = $r->nextBoundedInt(100);
					if($roll < 8){
						$this->fungusTree($w, $x, $y, $z, TreeType::CRIMSON, $r);
					}elseif($roll < 50){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::CRIMSON_ROOTS());
					}elseif($roll < 62){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::CRIMSON_FUNGUS());
					}
					break;
				case B::WARPED_FOREST:
					if($ground !== $this->warpedNylium){
						break;
					}
					$roll = $r->nextBoundedInt(100);
					if($roll < 8){
						$this->fungusTree($w, $x, $y, $z, TreeType::WARPED, $r);
					}elseif($roll < 40){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::WARPED_ROOTS());
					}elseif($roll < 60){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::NETHER_SPROUTS());
					}elseif($roll < 70){
						$w->setBlock($x, $y + 1, $z, VanillaBlocks::WARPED_FUNGUS());
					}elseif($roll < 78){
						$len = 1 + $r->nextBoundedInt(6);
						for($d = 1; $d <= $len && $w->get($x, $y + $d, $z) === $this->air; ++$d){
							$w->setBlock($x, $y + $d, $z, VanillaBlocks::TWISTING_VINES());
						}
					}
					break;
				case B::SOULSAND_VALLEY:
					if($ground === $this->soulSoil && $r->nextBoundedInt(12) === 0){
						$w->setBlock($x, $y + 1, $z, $soulFire);
					}elseif($r->nextBoundedInt(40) === 0){
						$this->basaltPillar($w, $x, $y, $z, $r);
					}
					break;
				case B::BASALT_DELTAS:
					if($r->nextBoundedInt(6) === 0){
						$this->basaltPillar($w, $x, $y, $z, $r);
					}elseif($r->nextBoundedInt(5) === 0 && $y > self::LAVA_LEVEL){
						//delta: shallow lava pool rimmed with basalt
						$w->set($x, $y, $z, $this->lava);
					}
					break;
				default:
					if($ground === $this->netherrack && $r->nextBoundedInt(14) === 0){
						$w->setBlock($x, $y + 1, $z, $fire);
					}elseif($ground === $this->netherrack && $r->nextBoundedInt(20) === 0){
						$w->setBlock($x, $y + 1, $z, $r->nextBoolean() ? VanillaBlocks::RED_MUSHROOM() : VanillaBlocks::BROWN_MUSHROOM());
					}
			}
		}

		//weeping vines from crimson ceilings
		if($biome === B::CRIMSON_FOREST){
			for($i = 0; $i < 10; ++$i){
				$x = $baseX + $r->nextBoundedInt(16);
				$z = $baseZ + $r->nextBoundedInt(16);
				$y = 30 + $r->nextBoundedInt(90);
				if($w->get($x, $y, $z) === $this->air && $w->get($x, $y + 1, $z) === $this->netherrack){
					$len = 1 + $r->nextBoundedInt(8);
					for($d = 0; $d < $len && $w->get($x, $y - $d, $z) === $this->air; ++$d){
						$w->setBlock($x, $y - $d, $z, VanillaBlocks::WEEPING_VINES());
					}
				}
			}
		}
	}

	private function randomFloor(BlockWriter $w, int $x, int $z, Random $r) : ?int{
		$y = 10 + $r->nextBoundedInt(110);
		for(; $y > 5; --$y){
			$id = $w->get($x, $y, $z);
			if($id !== $this->air && $id !== $this->lava && $w->get($x, $y + 1, $z) === $this->air){
				return $y;
			}
		}
		return null;
	}

	private function fungusTree(BlockWriter $w, int $x, int $y, int $z, TreeType $type, Random $r) : void{
		$tree = TreeFactory::get($r, $type);
		$transaction = $tree?->getBlockTransaction($w->getWorld(), $x, $y + 1, $z, $r);
		if($transaction === null){
			return;
		}
		foreach($transaction->getBlocks() as [$bx, $by, $bz]){
			if(!$w->isAvailable($bx, $bz) || $by >= self::HEIGHT - 4){
				return;
			}
		}
		$transaction->apply();
	}

	private function basaltPillar(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$height = 2 + $r->nextBoundedInt(6);
		for($d = 1; $d <= $height && $w->get($x, $y + $d, $z) === $this->air; ++$d){
			$w->set($x, $y + $d, $z, $this->basalt);
		}
	}

	private function veins(BlockWriter $w, int $baseX, int $baseZ, Random $r, int $ore, int $count, int $size, int $minY, int $maxY, bool $hidden = false) : void{
		for($i = 0; $i < $count; ++$i){
			$x = $baseX + $r->nextBoundedInt(16);
			$y = $minY + $r->nextBoundedInt(max(1, $maxY - $minY + 1));
			$z = $baseZ + $r->nextBoundedInt(16);
			$radius = max(1, (int) (($size / 3.5) ** (1 / 2.2) + 0.5));
			$placed = 0;
			for($a = 0; $a < $size * 2 && $placed < $size; ++$a){
				$dx = $r->nextRange(-$radius, $radius);
				$dy = $r->nextRange(-$radius, $radius);
				$dz = $r->nextRange(-$radius, $radius);
				$px = $x + $dx;
				$py = min(self::HEIGHT - 2, max(1, $y + $dy));
				$pz = $z + $dz;
				$id = $w->get($px, $py, $pz);
				if($id !== $this->netherrack && !($ore === $this->magma && ($id === $this->basalt || $id === $this->blackstone))){
					continue;
				}
				if($hidden){
					//ancient debris is never exposed to air
					$exposed = false;
					foreach([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$ox, $oy, $oz]){
						if($w->get($px + $ox, $py + $oy, $pz + $oz) === $this->air){
							$exposed = true;
							break;
						}
					}
					if($exposed){
						continue;
					}
				}
				$w->set($px, $py, $pz, $ore);
				++$placed;
			}
		}
	}
}
