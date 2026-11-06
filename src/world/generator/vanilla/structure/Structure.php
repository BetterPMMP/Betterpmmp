<?php

/*
 * BetterPMMP - base class of a generated structure type.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Random;
use function floor;

/**
 * A structure type placed with vanilla's random-spread placement: the world
 * is divided into regions of spacing x spacing chunks and each region gets at
 * most one start, at least `separation` chunks from the region border.
 */
abstract class Structure{

	/** Structure type name (also used for logging/debug). */
	abstract public function getName() : string;

	/** Region size in chunks. */
	abstract protected function getSpacing() : int;

	/** Minimum distance in chunks between starts of neighbouring regions. */
	abstract protected function getSeparation() : int;

	/** Per-structure salt so different types don't share positions. */
	abstract protected function getSalt() : int;

	/** Maximum reach of a start from its chunk, in chunks (for lookup). */
	abstract public function getMaxRadiusChunks() : int;

	/** Surface structures keep trees and plants out of their area. */
	public function isSurface() : bool{
		return true;
	}

	/** Vanilla uses a triangular distribution for some structures. */
	protected function isTriangular() : bool{
		return false;
	}

	/**
	 * Builds the start (layout of all pieces) for the given chunk, or returns
	 * null if the location is not valid (biome, height, ...).
	 */
	abstract protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart;

	/**
	 * Candidate start chunk of a region.
	 * @return int[]
	 */
	public function candidateChunk(int $seed, int $regionX, int $regionZ) : array{
		$spacing = $this->getSpacing();
		$range = $spacing - $this->getSeparation();
		$r = new Random(($regionX * 341873128712 + $regionZ * 132897987541 + $seed + $this->getSalt()) & 0x7fffffffffff);
		if($this->isTriangular()){
			$ox = intdiv($r->nextBoundedInt($range) + $r->nextBoundedInt($range), 2);
			$oz = intdiv($r->nextBoundedInt($range) + $r->nextBoundedInt($range), 2);
		}else{
			$ox = $r->nextBoundedInt($range);
			$oz = $r->nextBoundedInt($range);
		}
		return [$regionX * $spacing + $ox, $regionZ * $spacing + $oz];
	}

	/**
	 * Region coordinates whose starts could reach the given chunk.
	 * @return int[][]
	 */
	public function regionsNear(int $chunkX, int $chunkZ) : array{
		$spacing = $this->getSpacing();
		$radius = $this->getMaxRadiusChunks();
		$minRX = (int) floor(($chunkX - $radius) / $spacing);
		$maxRX = (int) floor(($chunkX + $radius) / $spacing);
		$minRZ = (int) floor(($chunkZ - $radius) / $spacing);
		$maxRZ = (int) floor(($chunkZ + $radius) / $spacing);
		$result = [];
		for($rx = $minRX; $rx <= $maxRX; ++$rx){
			for($rz = $minRZ; $rz <= $maxRZ; ++$rz){
				$result[] = [$rx, $rz];
			}
		}
		return $result;
	}

	public function buildStart(StructureContext $ctx, int $chunkX, int $chunkZ) : ?StructureStart{
		$random = new Random(($ctx->seed ^ ($chunkX * 0x5DEECE66D) ^ ($chunkZ * 0xB) ^ $this->getSalt()) & 0x7fffffffffff);
		$start = $this->createStart($ctx, $chunkX, $chunkZ, $random);
		return $start !== null && !$start->isEmpty() ? $start : null;
	}

	/**
	 * Starts that may overlap the given chunk. The default implementation uses
	 * the region grid; ring-placed structures (strongholds) override this.
	 * @return int[][] list of [chunkX, chunkZ]
	 */
	public function startChunksNear(int $seed, int $chunkX, int $chunkZ) : array{
		$result = [];
		$radius = $this->getMaxRadiusChunks();
		foreach($this->regionsNear($chunkX, $chunkZ) as [$rx, $rz]){
			[$cx, $cz] = $this->candidateChunk($seed, $rx, $rz);
			if(abs($cx - $chunkX) <= $radius && abs($cz - $chunkZ) <= $radius){
				$result[] = [$cx, $cz];
			}
		}
		return $result;
	}
}
