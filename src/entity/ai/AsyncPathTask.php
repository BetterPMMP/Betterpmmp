<?php

/*
 * BetterPMMP - path finding in a worker thread.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function is_array;
use function is_string;
use function max;
use function min;
use function serialize;
use function str_starts_with;
use function substr;
use function unserialize;

/**
 * Runs {@link PathFinder} in an async worker instead of the main thread.
 *
 * The main thread only copies the block layer of the sub-chunks around the mob and its goal (a few
 * microseconds per sub-chunk); the A* search itself, which is the expensive part of mob AI, runs in
 * parallel with the world tick. The result comes back one or two ticks later; the mob keeps following
 * its old path meanwhile, so nothing visibly changes except that the main thread has more time.
 */
final class AsyncPathTask extends AsyncTask{

	/** Blocks around start and goal that are copied sideways (the search cannot leave this area). */
	private const BLOCK_MARGIN = 10;
	/** Blocks above and below start and goal that are copied. */
	private const Y_MARGIN = 6;
	/** Larger areas are searched on the main thread like before. */
	private const MAX_CHUNKS = 25;
	/** Searches waiting in the workers at once; more fall back to the main thread budget. */
	public const MAX_PENDING = 64;

	private static int $pending = 0;
	private static bool $failed = false;

	private string $snapshot;

	public static function pending() : int{
		return self::$pending;
	}

	/**
	 * Copies the needed part of the world, or returns null when the area is too large for a snapshot.
	 */
	public static function create(Navigator $navigator, World $world, int $sx, int $sy, int $sz, int $gx, int $gy, int $gz, int $height, bool $canSwim, bool $breakDoors, int $maxNodes, float $reach) : ?self{
		$minCx = (min($sx, $gx) - self::BLOCK_MARGIN) >> 4;
		$maxCx = (max($sx, $gx) + self::BLOCK_MARGIN) >> 4;
		$minCz = (min($sz, $gz) - self::BLOCK_MARGIN) >> 4;
		$maxCz = (max($sz, $gz) + self::BLOCK_MARGIN) >> 4;
		if(($maxCx - $minCx + 1) * ($maxCz - $minCz + 1) > self::MAX_CHUNKS){
			return null;
		}
		$minSy = max(Chunk::MIN_SUBCHUNK_INDEX, ($world->getMinY() >> 4), (min($sy, $gy) - self::Y_MARGIN) >> 4);
		$maxSy = min(Chunk::MAX_SUBCHUNK_INDEX, (($world->getMaxY() - 1) >> 4), (max($sy, $gy) + self::Y_MARGIN) >> 4);

		$chunks = [];
		for($cx = $minCx; $cx <= $maxCx; ++$cx){
			for($cz = $minCz; $cz <= $maxCz; ++$cz){
				$chunk = $world->getChunk($cx, $cz);
				if($chunk === null){
					continue; // stays unloaded for the search, like on the main thread
				}
				$subs = [];
				for($y = $minSy; $y <= $maxSy; ++$y){
					$sub = $chunk->getSubChunk($y);
					$layers = $sub->getBlockLayers();
					if(!isset($layers[0])){
						continue; // empty sub-chunk: air
					}
					$blocks = $layers[0];
					$subs[$y] = [$blocks->getBitsPerBlock(), $blocks->getWordArray(), $blocks->getPalette()];
				}
				$chunks[] = [$cx, $cz, $subs];
			}
		}

		$task = new self();
		$task->snapshot = serialize([
			$minSy << 4, ($maxSy + 1) << 4, $chunks,
			$sx, $sy, $sz, $gx, $gy, $gz, $height, $canSwim, $breakDoors, $maxNodes, $reach,
		]);
		$task->storeLocal("navigator", $navigator);
		++self::$pending;
		return $task;
	}

	private function __construct(){}

	public function onRun() : void{
		// an exception would take the whole worker down: report it and let the main thread take over
		try{
			$this->search();
		}catch(\Throwable $e){
			$this->setResult("error: " . $e->getMessage());
		}
	}

	private function search() : void{
		$data = unserialize($this->snapshot);
		if(!is_array($data)){
			$this->setResult(null);
			return;
		}
		[$minY, $maxY, $chunks, $sx, $sy, $sz, $gx, $gy, $gz, $height, $canSwim, $breakDoors, $maxNodes, $reach] = $data;

		$air = VanillaBlocks::AIR()->getStateId();
		// cells below the copied range count as dangerous, above as open: the same as the world's own limits
		$manager = new SimpleChunkManager($minY, $maxY);
		foreach($chunks as [$cx, $cz, $subs]){
			$subChunks = [];
			foreach($subs as $y => [$bits, $words, $palette]){
				$subChunks[$y] = new SubChunk($air, [PalettedBlockArray::fromData($bits, $words, $palette)], new PalettedBlockArray(BiomeIds::PLAINS));
			}
			$manager->setChunk($cx, $cz, new Chunk($subChunks, true));
		}

		$finder = new PathFinder($manager, $height, $canSwim, false, $breakDoors);
		$path = $finder->find($sx, $sy, $sz, $gx, $gy, $gz, $maxNodes, $reach);
		$this->setResult($path === null ? null : serialize($path));
	}

	public function onCompletion() : void{
		--self::$pending;
		/** @var Navigator $navigator */
		$navigator = $this->fetchLocal("navigator");
		$result = $this->getResult();
		if(is_string($result) && str_starts_with($result, "error: ")){
			if(!self::$failed){
				self::$failed = true;
				\GlobalLogger::get()->warning("BetterPMMP: async path finding failed (" . substr($result, 7) . "), mobs plan their paths on the main thread again");
			}
			$navigator->onAsyncPath(null, true);
			return;
		}
		$path = is_string($result) ? unserialize($result) : null;
		$navigator->onAsyncPath(is_array($path) ? $path : null);
	}

	/** Whether a search in a worker failed once; then all searches run on the main thread. */
	public static function hasFailed() : bool{
		return self::$failed;
	}
}
