<?php

/*
 * BetterPMMP - vanilla-style End generator.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\noise\Simplex;
use pocketmine\world\generator\vanilla\structure\EndCity;
use pocketmine\world\generator\vanilla\structure\StructureContext;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use function abs;
use function count;
use function floor;
use function cos;
use function max;
use function min;
use function sin;
use function sqrt;
use const M_PI;

/**
 * End generator modelled on vanilla:
 *  - island shape from vanilla's end island density (main island of about
 *    100 blocks radius, void ring, outer islands beyond 1024 blocks)
 *  - ten obsidian spikes at radius 42 (heights 76..103, two caged)
 *  - unactivated exit portal (bedrock) at the centre, obsidian arrival
 *    platform at (100, 48, 0)
 *  - chorus plants on the outer islands and end cities (with end ships)
 */
class VanillaEnd extends Generator{

	private Simplex $islandNoise;
	private Simplex $surfaceNoise;
	private StructureManager $structures;
	private int $endStone;
	private int $air;

	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);
		$r = new Random($seed ^ 0x456E6421);
		$this->islandNoise = new Simplex($r, 1, 0.5, 1.0);
		$this->surfaceNoise = new Simplex($r, 2, 0.5, 1 / 24);
		$this->endStone = VanillaBlocks::END_STONE()->getStateId();
		$this->air = VanillaBlocks::AIR()->getStateId();
		$ctx = new StructureContext($seed, fn(int $x, int $y, int $z) : int => B::THE_END, fn(int $x, int $z) : int => $this->surfaceHeight($x, $z) ?? 0);
		$this->structures = new StructureManager($ctx, [new EndCity()]);
	}

	public function getStructureManager() : StructureManager{
		return $this->structures;
	}

	/** @var array<string, list<array{float, float, int}>> */
	private array $islandCache = [];

	/**
	 * Outer island centres (in 8-block units) that can influence a chunk:
	 * vanilla places one on every 16-block cell beyond 1024 blocks where the
	 * island noise is below -0.9, with a size factor derived from its position.
	 * @return list<array{float, float, int}>
	 */
	private function islandsNear(int $chunkX, int $chunkZ) : array{
		$key = $chunkX . ":" . $chunkZ;
		if(isset($this->islandCache[$key])){
			return $this->islandCache[$key];
		}
		if(count($this->islandCache) > 256){
			$this->islandCache = [];
		}
		$list = [];
		if($chunkX * $chunkX + $chunkZ * $chunkZ > 2500){ //closer than ~800 blocks no outer island can reach
			for($o = $chunkX - 12; $o <= $chunkX + 12; ++$o){
				for($p = $chunkZ - 12; $p <= $chunkZ + 12; ++$p){
					if($o * $o + $p * $p > 4096 && $this->islandNoise->getNoise2D($o, $p) < -0.9){
						$list[] = [$o * 2.0, $p * 2.0, (abs($o) * 3439 + abs($p) * 147) % 13 + 9];
					}
				}
			}
		}
		return $this->islandCache[$key] = $list;
	}

	/**
	 * Vanilla end island density: positive inside an island (main island
	 * around the origin plus outer islands).
	 */
	public function islandValue(float $x, float $z) : float{
		$cx = $x / 8;
		$cz = $z / 8;
		$f = max(-100.0, min(80.0, 100.0 - sqrt($cx * $cx + $cz * $cz) * 8.0));
		foreach($this->islandsNear(((int) floor($x)) >> 4, ((int) floor($z)) >> 4) as [$ix, $iz, $g]){
			$h = $cx - $ix;
			$q = $cz - $iz;
			$f = max($f, min(80.0, 100.0 - sqrt($h * $h + $q * $q) * $g));
		}
		return $f;
	}

	/** Surface height of the island at a column, or null over the void. */
	public function surfaceHeight(int $x, int $z) : ?int{
		$f = $this->islandValue($x, $z);
		if($f <= 0){
			return null;
		}
		return (int) (55 + min($f, 60) * 0.13 + $this->surfaceNoise->noise2D($x, $z, true) * 2);
	}

	/**
	 * Vanilla spikes: [x, z, height above 65, caged, radius]. Top obsidian is
	 * at y 65 + height; the crystal sits one block above.
	 * @return list<array{int, int, int, bool, int}>
	 */
	public static function getPillars() : array{
		static $pillars = null;
		if($pillars !== null){
			return $pillars;
		}
		//vanilla shuffles the size index per world; use a fixed shuffle so the
		//dragon fight code can find the crystals without knowing the seed
		$order = [6, 2, 9, 0, 4, 7, 1, 8, 3, 5];
		$pillars = [];
		for($i = 0; $i < 10; ++$i){
			$angle = 2 * M_PI * (-$i) / 10 + M_PI;
			$x = (int) floor(42 * cos($angle));
			$z = (int) floor(42 * sin($angle));
			$size = $order[$i];
			$top = 76 + $size * 3;
			$pillars[] = [$x, $z, $top - 65, $size === 1 || $size === 2, 2 + intdiv($size, 3)];
		}
		return $pillars;
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = new Chunk([], false);
		$biomes = new PalettedBlockArray(B::THE_END);
		foreach($chunk->getSubChunks() as $y => $_){
			$chunk->setSubChunk($y, new SubChunk($this->air, [], clone $biomes));
		}
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;
		//island columns: top surface, cone-shaped underside
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$wx = $baseX + $x;
				$wz = $baseZ + $z;
				$f = $this->islandValue($wx, $wz);
				if($f <= 0){
					continue;
				}
				$top = (int) (55 + min($f, 60) * 0.13 + $this->surfaceNoise->noise2D($wx, $wz, true) * 2);
				$bottom = (int) (55 - $f * 0.42 + $this->surfaceNoise->noise2D($wz, $wx, true) * 3);
				for($y = max(1, $bottom); $y <= $top; ++$y){
					$chunk->setBlockStateId($x, $y, $z, $this->endStone);
				}
			}
		}
		//spikes
		$obsidian = VanillaBlocks::OBSIDIAN()->getStateId();
		$bars = VanillaBlocks::IRON_BARS()->getStateId();
		foreach(self::getPillars() as [$px, $pz, $height, $caged, $radius]){
			if($px + $radius + 2 < $baseX || $px - $radius - 2 > $baseX + 15 || $pz + $radius + 2 < $baseZ || $pz - $radius - 2 > $baseZ + 15){
				continue;
			}
			$top = 65 + $height;
			for($x = 0; $x < 16; ++$x){
				for($z = 0; $z < 16; ++$z){
					$dx = $baseX + $x - $px;
					$dz = $baseZ + $z - $pz;
					if($dx * $dx + $dz * $dz <= $radius * $radius + 1){
						for($y = 40; $y <= $top; ++$y){
							$chunk->setBlockStateId($x, $y, $z, $obsidian);
						}
					}
					if($caged && abs($dx) <= 2 && abs($dz) <= 2){
						for($y = $top + 1; $y <= $top + 3; ++$y){
							if(abs($dx) === 2 || abs($dz) === 2 || $y === $top + 3){
								$chunk->setBlockStateId($x, $y, $z, $bars);
							}
						}
					}
				}
			}
		}
		//exit portal frame (activated by the dragon fight code at y 64/65)
		if($chunkX >= -1 && $chunkX <= 0 && $chunkZ >= -1 && $chunkZ <= 0){
			$bedrock = VanillaBlocks::BEDROCK()->getStateId();
			for($dx = -3; $dx <= 3; ++$dx){
				for($dz = -3; $dz <= 3; ++$dz){
					$wx = $dx;
					$wz = $dz;
					if(($wx >> 4) !== $chunkX || ($wz >> 4) !== $chunkZ){
						continue;
					}
					$d = $dx * $dx + $dz * $dz;
					if($d > 10){
						continue;
					}
					for($y = 58; $y <= 63; ++$y){
						$chunk->setBlockStateId($wx & 15, $y, $wz & 15, $this->endStone);
					}
					$chunk->setBlockStateId($wx & 15, 64, $wz & 15, $bedrock);
					if($d >= 5){
						$chunk->setBlockStateId($wx & 15, 65, $wz & 15, $bedrock);
					}
					for($y = 66; $y <= 70; ++$y){
						$chunk->setBlockStateId($wx & 15, $y, $wz & 15, $this->air);
					}
					if($dx === 0 && $dz === 0){
						for($y = 65; $y <= 68; ++$y){
							$chunk->setBlockStateId(0, $y, 0, $bedrock);
						}
					}
				}
			}
		}
		$world->setChunk($chunkX, $chunkZ, $chunk);
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$w = new BlockWriter($world);
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;
		$r = new Random($this->seed ^ ($chunkX * 0x2F1B3C4D) ^ ($chunkZ * 0x5A6B7C8D));
		//torches on the exit portal pillar
		if($chunkX === 0 && $chunkZ === 0){
			foreach(Facing::HORIZONTAL as $face){
				[$ox, , $oz] = Facing::OFFSET[$face];
				$w->setBlock($ox, 67, $oz, VanillaBlocks::TORCH()->setFacing($face));
			}
		}
		//obsidian arrival platform (vanilla: 5x5 at x=100, y=48, z=0)
		if($baseX <= 102 && $baseX + 15 >= 98 && $baseZ <= 2 && $baseZ + 15 >= -2){
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					$w->setBlock(100 + $dx, 48, $dz, VanillaBlocks::OBSIDIAN());
					for($y = 49; $y <= 51; ++$y){
						$w->setBlock(100 + $dx, $y, $dz, VanillaBlocks::AIR());
					}
				}
			}
		}
		$this->structures->populate($w, $chunkX, $chunkZ);
		//chorus plants on the outer islands
		if($baseX * $baseX + $baseZ * $baseZ > 1000 * 1000){
			for($i = 0, $n = $r->nextBoundedInt(5); $i < $n; ++$i){
				$x = $baseX + $r->nextBoundedInt(16);
				$z = $baseZ + $r->nextBoundedInt(16);
				$y = $w->highest($x, $z);
				if($y !== null && $w->get($x, $y, $z) === $this->endStone){
					$this->chorus($w, $x, $y + 1, $z, $r, 0);
				}
			}
		}
	}

	private function chorus(BlockWriter $w, int $x, int $y, int $z, Random $r, int $depth) : void{
		$plant = VanillaBlocks::CHORUS_PLANT();
		$height = 1 + $r->nextBoundedInt($depth === 0 ? 4 : 3);
		for($i = 0; $i < $height; ++$i){
			if($w->get($x, $y + $i, $z) !== $this->air){
				return;
			}
			$w->setBlock($x, $y + $i, $z, $plant);
		}
		$top = $y + $height - 1;
		if($depth < 4){
			$branches = $r->nextBoundedInt(4 - $depth) + ($depth === 0 ? 1 : 0);
			for($b = 0; $b < $branches; ++$b){
				$face = Facing::HORIZONTAL[$r->nextBoundedInt(4)];
				[$ox, , $oz] = Facing::OFFSET[$face];
				if($w->get($x + $ox, $top, $z + $oz) === $this->air && abs($ox * ($depth + 1)) <= 7){
					$w->setBlock($x + $ox, $top, $z + $oz, $plant);
					$this->chorus($w, $x + $ox, $top + 1, $z + $oz, $r, $depth + 1);
				}
			}
			if($branches > 0){
				return;
			}
		}
		$w->setBlock($x, $top + 1, $z, VanillaBlocks::CHORUS_FLOWER());
	}
}
