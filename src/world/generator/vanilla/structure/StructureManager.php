<?php

/*
 * BetterPMMP - places structures during chunk population.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\BlockWriter;
use function count;

/**
 * Holds the structure types of a dimension, caches their starts and draws
 * the parts of every structure that overlap a populated chunk.
 */
final class StructureManager{

	/** @var array<string, ?StructureStart> */
	private array $cache = [];
	private const CACHE_LIMIT = 512;

	/**
	 * @param Structure[] $structures
	 */
	public function __construct(
		private StructureContext $ctx,
		private array $structures
	){}

	public function getContext() : StructureContext{
		return $this->ctx;
	}

	/** @return Structure[] */
	public function getStructures() : array{
		return $this->structures;
	}

	private function start(Structure $structure, int $chunkX, int $chunkZ) : ?StructureStart{
		$key = $structure->getName() . ":" . $chunkX . ":" . $chunkZ;
		if(array_key_exists($key, $this->cache)){
			return $this->cache[$key];
		}
		if(count($this->cache) >= self::CACHE_LIMIT){
			$this->cache = [];
		}
		return $this->cache[$key] = $structure->buildStart($this->ctx, $chunkX, $chunkZ);
	}

	/**
	 * All structure starts overlapping a chunk.
	 * @return StructureStart[]
	 */
	public function startsIn(int $chunkX, int $chunkZ) : array{
		$minX = $chunkX << 4;
		$minZ = $chunkZ << 4;
		$result = [];
		foreach($this->structures as $structure){
			foreach($structure->startChunksNear($this->ctx->seed, $chunkX, $chunkZ) as [$cx, $cz]){
				$start = $this->start($structure, $cx, $cz);
				if($start !== null && $start->intersectsColumn($minX, $minZ, $minX + 15, $minZ + 15)){
					$result[] = $start;
				}
			}
		}
		return $result;
	}

	/**
	 * Draws every structure piece overlapping the chunk.
	 * @return StructureStart[] the starts that were drawn
	 */
	public function populate(BlockWriter $writer, int $chunkX, int $chunkZ) : array{
		$starts = $this->startsIn($chunkX, $chunkZ);
		if(count($starts) === 0){
			return [];
		}
		$canvas = new StructureCanvas($writer, $chunkX, $chunkZ);
		$minX = $chunkX << 4;
		$minZ = $chunkZ << 4;
		foreach($starts as $start){
			foreach($start->pieces as $piece){
				if($piece->intersectsColumn($minX, $minZ, $minX + 15, $minZ + 15)){
					$piece->draw($canvas);
				}
			}
		}
		return $starts;
	}

	/**
	 * Nearest start of a structure type to a block position (for /locate-like use).
	 * @return int[]|null [x, z]
	 */
	public function locate(string $name, int $x, int $z, int $radiusChunks = 100) : ?array{
		foreach($this->structures as $structure){
			if($structure->getName() !== $name){
				continue;
			}
			$best = null;
			$bestDist = PHP_INT_MAX;
			$cx = $x >> 4;
			$cz = $z >> 4;
			//scan the region grid around the point
			$seen = [];
			for($dx = -$radiusChunks; $dx <= $radiusChunks; $dx += 4){
				for($dz = -$radiusChunks; $dz <= $radiusChunks; $dz += 4){
					foreach($structure->startChunksNear($this->ctx->seed, $cx + $dx, $cz + $dz) as [$sx, $sz]){
						$key = "$sx:$sz";
						if(isset($seen[$key])){
							continue;
						}
						$seen[$key] = true;
						$start = $this->start($structure, $sx, $sz);
						if($start === null){
							continue;
						}
						$d = ($sx * 16 - $x) ** 2 + ($sz * 16 - $z) ** 2;
						if($d < $bestDist){
							$bestDist = $d;
							$best = [($start->minX + $start->maxX) >> 1, ($start->minZ + $start->maxZ) >> 1];
						}
					}
				}
			}
			return $best;
		}
		return null;
	}
}
