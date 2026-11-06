<?php

/*
 * BetterPMMP - fast block access for population.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

/**
 * State-id based block access over the chunks available during population
 * (the populated chunk and its eight neighbours). Writes outside those chunks
 * are silently dropped, so features near the border never throw.
 */
final class BlockWriter{

	/** @var (Chunk|null)[] */
	private array $chunks = [];

	public function __construct(
		private ChunkManager $world
	){}

	public function getWorld() : ChunkManager{
		return $this->world;
	}

	private function chunk(int $x, int $z) : ?Chunk{
		$hash = World::chunkHash($x >> 4, $z >> 4);
		if(!isset($this->chunks[$hash]) && !array_key_exists($hash, $this->chunks)){
			$this->chunks[$hash] = $this->world->getChunk($x >> 4, $z >> 4);
		}
		return $this->chunks[$hash];
	}

	public function isAvailable(int $x, int $z) : bool{
		return $this->chunk($x, $z) !== null;
	}

	public function get(int $x, int $y, int $z) : int{
		if($y < Overworld::MIN_Y || $y >= Overworld::MAX_Y){
			return Block::EMPTY_STATE_ID;
		}
		$chunk = $this->chunk($x, $z);
		return $chunk === null ? Block::EMPTY_STATE_ID : $chunk->getBlockStateId($x & 15, $y, $z & 15);
	}

	public function getBlock(int $x, int $y, int $z) : Block{
		return RuntimeBlockStateRegistry::getInstance()->fromStateId($this->get($x, $y, $z));
	}

	public function set(int $x, int $y, int $z, int $state) : void{
		if($y < Overworld::MIN_Y || $y >= Overworld::MAX_Y){
			return;
		}
		$chunk = $this->chunk($x, $z);
		$chunk?->setBlockStateId($x & 15, $y, $z & 15, $state);
	}

	public function setBlock(int $x, int $y, int $z, Block $block) : void{
		$this->set($x, $y, $z, $block->getStateId());
	}

	public function getBiome(int $x, int $y, int $z) : int{
		$chunk = $this->chunk($x, $z);
		return $chunk === null ? -1 : $chunk->getBiomeId($x & 15, max(Overworld::MIN_Y, min(Overworld::MAX_Y - 1, $y)), $z & 15);
	}

	/**
	 * Highest non-air block of a column, or null if the column is empty / unavailable.
	 */
	public function highest(int $x, int $z) : ?int{
		$chunk = $this->chunk($x, $z);
		return $chunk?->getHighestBlockAt($x & 15, $z & 15);
	}
}
