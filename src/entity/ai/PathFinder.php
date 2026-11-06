<?php

/*
 * BetterPMMP - vanilla-style ground path finding.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Door;
use pocketmine\block\Fence;
use pocketmine\block\FenceGate;
use pocketmine\block\GenericBedrockBlock;
use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\Thin;
use pocketmine\block\Trapdoor;
use pocketmine\block\Wall;
use pocketmine\block\Water;
use pocketmine\world\World;
use SplPriorityQueue;
use function abs;
use function array_reverse;
use function count;
use function hrtime;
use function intdiv;
use function max;
use function sqrt;

/**
 * A* over the block grid for walking mobs, like vanilla's WalkNodeEvaluator:
 * steps up one block (jump), drops down up to 3 blocks, walks diagonally only
 * when both side cells are free, swims through water (at a cost) and never
 * enters lava, fire, cactus, magma, berry bushes, powder snow or wither roses.
 * Closed doors, fences, walls and closed fence gates block the way.
 *
 * Works on raw state IDs from loaded chunks and never loads or generates
 * chunks. If the goal is unreachable within the node budget, the path to the
 * node closest to the goal is returned (vanilla does the same).
 */
final class PathFinder{

	public const OPEN = 0;
	public const SOLID = 1;
	public const TALL = 2;
	public const WATER = 3;
	public const DANGER = 4;
	public const UNLOADED = 5;

	private const MAX_DROP = 3;

	/** @var int[] state id => cell class */
	private static array $classes = [];

	/** Paths computed during the current server tick (budget). */
	private static int $budgetTick = -1;
	private static int $budgetUsed = 0;
	private static float $budgetTime = 0.0;
	public const PATHS_PER_TICK = 10;
	/** Seconds of path finding allowed per server tick (all mobs together). */
	public const TIME_PER_TICK = 0.006;

	/** @var int[] cached classes for the running search, keyed by block hash */
	private array $cache = [];

	public function __construct(
		private World $world,
		private int $height = 2,
		private bool $canSwim = true,
		private bool $avoidWater = false
	){}

	/**
	 * Whether another path may be computed now. The budget is shared by all
	 * mobs and refills every 50 ms (one server tick).
	 */
	public static function takeBudget() : bool{
		$window = intdiv(hrtime(true), 50_000_000);
		if(self::$budgetTick !== $window){
			self::$budgetTick = $window;
			self::$budgetUsed = 0;
			self::$budgetTime = 0.0;
		}
		if(self::$budgetUsed >= self::PATHS_PER_TICK || self::$budgetTime >= \pocketmine\BetterPMMPConfig::pathfindingSecondsPerTick()){
			return false;
		}
		++self::$budgetUsed;
		return true;
	}

	public static function classifyState(int $stateId) : int{
		if(isset(self::$classes[$stateId])){
			return self::$classes[$stateId];
		}
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::$classes[$stateId] = self::classifyBlock($block);
	}

	private static function classifyBlock(Block $block) : int{
		$id = $block->getTypeId();
		if($id === BlockTypeIds::AIR){
			return self::OPEN;
		}
		if($block instanceof Lava){
			return self::DANGER;
		}
		if($block instanceof Water){
			return self::WATER;
		}
		if($block instanceof Liquid){
			return self::DANGER;
		}
		switch($id){
			case BlockTypeIds::FIRE:
			case BlockTypeIds::SOUL_FIRE:
			case BlockTypeIds::CACTUS:
			case BlockTypeIds::MAGMA:
			case BlockTypeIds::SWEET_BERRY_BUSH:
			case BlockTypeIds::WITHER_ROSE:
			case BlockTypeIds::CAMPFIRE:
			case BlockTypeIds::SOUL_CAMPFIRE:
			case BlockTypeIds::COBWEB:
				return self::DANGER;
		}
		if($block instanceof GenericBedrockBlock){
			$name = $block->getBedrockName();
			if($name === "minecraft:powder_snow" || $name === "minecraft:pointed_dripstone"){
				return self::DANGER;
			}
		}
		if($block instanceof Door){
			return $block->isOpen() ? self::OPEN : self::TALL;
		}
		if($block instanceof FenceGate){
			return $block->isOpen() ? self::OPEN : self::TALL;
		}
		if($block instanceof Trapdoor){
			return $block->isOpen() ? self::OPEN : self::SOLID;
		}
		if($block instanceof Fence || $block instanceof Wall || $block instanceof Thin){
			return self::TALL;
		}
		try{
			$boxes = $block->getCollisionBoxes();
		}catch(\Throwable){
			return $block->isSolid() ? self::SOLID : self::OPEN;
		}
		if(count($boxes) === 0){
			return self::OPEN;
		}
		$top = 0.0;
		foreach($boxes as $box){
			$top = max($top, $box->maxY);
		}
		if($top > 1.0){
			return self::TALL;
		}
		// carpets, snow layers, pressure plates: walk over them
		return $top <= 0.2 ? self::OPEN : self::SOLID;
	}

	private function cell(int $x, int $y, int $z) : int{
		$hash = World::blockHash($x, $y, $z);
		if(isset($this->cache[$hash])){
			return $this->cache[$hash];
		}
		if($y < $this->world->getMinY() || $y >= $this->world->getMaxY()){
			return $this->cache[$hash] = ($y < $this->world->getMinY() ? self::DANGER : self::OPEN);
		}
		$chunk = $this->world->getChunk($x >> 4, $z >> 4);
		if($chunk === null){
			return $this->cache[$hash] = self::UNLOADED;
		}
		return $this->cache[$hash] = self::classifyState($chunk->getBlockStateId($x & 15, $y, $z & 15));
	}

	/**
	 * Whether a mob can stand with its feet in this cell. Returns the extra
	 * cost of the cell, or -1 when it cannot stand there.
	 */
	public function standCost(int $x, int $y, int $z) : float{
		$feet = $this->cell($x, $y, $z);
		if($feet === self::UNLOADED || $feet === self::DANGER || $feet === self::SOLID || $feet === self::TALL){
			return -1;
		}
		for($i = 1; $i < $this->height; ++$i){
			$c = $this->cell($x, $y + $i, $z);
			if($c !== self::OPEN && $c !== self::WATER){
				return -1;
			}
		}
		if($feet === self::WATER){
			if(!$this->canSwim){
				return -1;
			}
			return $this->avoidWater ? 8.0 : 2.0;
		}
		$floor = $this->cell($x, $y - 1, $z);
		if($floor === self::SOLID){
			return 0.0;
		}
		if($floor === self::WATER && $this->canSwim){
			return $this->avoidWater ? 8.0 : 2.0;
		}
		return -1;
	}

	/** Lowest cell a mob would land in when walking off at (x, y, z), or null if too deep / dangerous. */
	private function landing(int $x, int $y, int $z) : ?int{
		for($drop = 0; $drop <= self::MAX_DROP; ++$drop){
			$ty = $y - $drop;
			$c = $this->cell($x, $ty, $z);
			if($c === self::DANGER || $c === self::UNLOADED || $c === self::TALL){
				return null;
			}
			if($c === self::SOLID){
				return null;
			}
			if($this->standCost($x, $ty, $z) >= 0){
				return $ty;
			}
		}
		return null;
	}

	/**
	 * @return int[][]|null list of [x, y, z] feet cells from start (exclusive) to the end
	 * @phpstan-return list<array{int, int, int}>|null
	 */
	public function find(int $sx, int $sy, int $sz, int $gx, int $gy, int $gz, int $maxNodes = 400, float $reach = 1.0) : ?array{
		$started = hrtime(true);
		try{
			return $this->search($sx, $sy, $sz, $gx, $gy, $gz, $maxNodes, $reach);
		}finally{
			self::$budgetTime += (hrtime(true) - $started) / 1e9;
		}
	}

	/**
	 * @phpstan-return list<array{int, int, int}>|null
	 */
	private function search(int $sx, int $sy, int $sz, int $gx, int $gy, int $gz, int $maxNodes, float $reach) : ?array{
		$this->cache = [];
		// a mob standing on a slab or in a half-block can report y one too low/high
		if($this->standCost($sx, $sy, $sz) < 0){
			if($this->standCost($sx, $sy + 1, $sz) >= 0){
				++$sy;
			}elseif(($land = $this->landing($sx, $sy, $sz)) !== null){
				$sy = $land;
			}
		}
		$startKey = World::blockHash($sx, $sy, $sz);
		$open = new SplPriorityQueue();
		$open->setExtractFlags(SplPriorityQueue::EXTR_DATA);
		$g = [$startKey => 0.0];
		$parent = [];
		$pos = [$startKey => [$sx, $sy, $sz]];
		$closed = [];
		$open->insert($startKey, [-self::h($sx, $sy, $sz, $gx, $gy, $gz), 0]);
		$bestKey = $startKey;
		$bestH = self::h($sx, $sy, $sz, $gx, $gy, $gz);
		$expanded = 0;
		$reachSq = $reach * $reach;
		$serial = 0;

		while(!$open->isEmpty() && $expanded < $maxNodes){
			$key = $open->extract();
			if(isset($closed[$key])){
				continue;
			}
			$closed[$key] = true;
			++$expanded;
			[$x, $y, $z] = $pos[$key];
			$dx = $x - $gx;
			$dy = $y - $gy;
			$dz = $z - $gz;
			if($dx * $dx + $dz * $dz <= $reachSq && abs($dy) <= 1){
				$bestKey = $key;
				break;
			}
			$hh = self::h($x, $y, $z, $gx, $gy, $gz);
			if($hh < $bestH){
				$bestH = $hh;
				$bestKey = $key;
			}

			foreach([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]] as [$ox, $oz]){
				$nx = $x + $ox;
				$nz = $z + $oz;
				if($ox !== 0 && $oz !== 0){
					// no corner cutting
					if($this->standCost($x + $ox, $y, $z) < 0 || $this->standCost($x, $y, $z + $oz) < 0){
						continue;
					}
				}
				$ny = null;
				$cost = $this->standCost($nx, $y, $nz);
				if($cost >= 0){
					$ny = $y;
				}else{
					$feet = $this->cell($nx, $y, $nz);
					if($feet === self::SOLID){
						// one block up: needs head room above the current cell
						$up = $this->standCost($nx, $y + 1, $nz);
						if($up >= 0 && $this->cell($x, $y + $this->height, $z) === self::OPEN){
							$ny = $y + 1;
							$cost = $up + 0.5;
						}
					}elseif($feet === self::OPEN || $feet === self::WATER){
						$land = $this->landing($nx, $y - 1, $nz);
						if($land !== null){
							$ny = $land;
							$cost = $this->standCost($nx, $land, $nz) + ($y - $land) * 0.5;
						}
					}
				}
				if($ny === null){
					continue;
				}
				$nkey = World::blockHash($nx, $ny, $nz);
				if(isset($closed[$nkey])){
					continue;
				}
				$step = ($ox !== 0 && $oz !== 0) ? 1.4142 : 1.0;
				$ng = $g[$key] + $step + $cost;
				if(!isset($g[$nkey]) || $ng < $g[$nkey]){
					$g[$nkey] = $ng;
					$parent[$nkey] = $key;
					$pos[$nkey] = [$nx, $ny, $nz];
					// tie-break on insertion order so equal priorities stay FIFO
					$open->insert($nkey, [-($ng + self::h($nx, $ny, $nz, $gx, $gy, $gz)), -(++$serial)]);
				}
			}
		}

		if($bestKey === $startKey){
			return null;
		}
		$path = [];
		for($key = $bestKey; $key !== $startKey; $key = $parent[$key]){
			$path[] = $pos[$key];
		}
		return array_reverse($path);
	}

	private static function h(int $x, int $y, int $z, int $gx, int $gy, int $gz) : float{
		$dx = $x - $gx;
		$dy = $y - $gy;
		$dz = $z - $gz;
		return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
	}
}
