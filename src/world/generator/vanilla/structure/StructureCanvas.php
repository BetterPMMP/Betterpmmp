<?php

/*
 * BetterPMMP - clipped drawing surface for structure pieces.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\BlockWriter;

/**
 * Drawing helpers for structure pieces. Writes are limited to one chunk (the
 * one being populated); reads see all chunks available to the population.
 */
final class StructureCanvas{
	private int $minX;
	private int $minZ;
	private int $maxX;
	private int $maxZ;
	private int $airId;

	public function __construct(
		public readonly BlockWriter $writer,
		int $chunkX,
		int $chunkZ
	){
		$this->minX = $chunkX << 4;
		$this->minZ = $chunkZ << 4;
		$this->maxX = $this->minX + 15;
		$this->maxZ = $this->minZ + 15;
		$this->airId = VanillaBlocks::AIR()->getStateId();
	}

	public function inside(int $x, int $z) : bool{
		return $x >= $this->minX && $x <= $this->maxX && $z >= $this->minZ && $z <= $this->maxZ;
	}

	public function set(int $x, int $y, int $z, Block $block, ?CompoundTag $tile = null) : void{
		if($x >= $this->minX && $x <= $this->maxX && $z >= $this->minZ && $z <= $this->maxZ){
			$this->writer->setBlock($x, $y, $z, $block, $tile);
		}
	}

	public function setId(int $x, int $y, int $z, int $state) : void{
		if($x >= $this->minX && $x <= $this->maxX && $z >= $this->minZ && $z <= $this->maxZ){
			$this->writer->set($x, $y, $z, $state);
		}
	}

	public function get(int $x, int $y, int $z) : int{
		return $this->writer->get($x, $y, $z);
	}

	public function getBlock(int $x, int $y, int $z) : Block{
		return $this->writer->getBlock($x, $y, $z);
	}

	public function isAir(int $x, int $y, int $z) : bool{
		return $this->writer->get($x, $y, $z) === $this->airId;
	}

	public function fill(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, Block $block) : void{
		$x1c = max($x1, $this->minX);
		$x2c = min($x2, $this->maxX);
		$z1c = max($z1, $this->minZ);
		$z2c = min($z2, $this->maxZ);
		if($x1c > $x2c || $z1c > $z2c){
			return;
		}
		$id = $block->getStateId();
		$tile = $block->getIdInfo()->getTileClass() !== null;
		for($x = $x1c; $x <= $x2c; ++$x){
			for($z = $z1c; $z <= $z2c; ++$z){
				for($y = $y1; $y <= $y2; ++$y){
					if($tile){
						$this->writer->setBlock($x, $y, $z, $block);
					}else{
						$this->writer->set($x, $y, $z, $id);
					}
				}
			}
		}
	}

	/** Hollow box: walls/floor/ceiling of $wall, inside of $inside (null = untouched). */
	public function box(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, Block $wall, ?Block $inside) : void{
		$this->fill($x1, $y1, $z1, $x2, $y1, $z2, $wall);
		$this->fill($x1, $y2, $z1, $x2, $y2, $z2, $wall);
		$this->fill($x1, $y1, $z1, $x1, $y2, $z2, $wall);
		$this->fill($x2, $y1, $z1, $x2, $y2, $z2, $wall);
		$this->fill($x1, $y1, $z1, $x2, $y2, $z1, $wall);
		$this->fill($x1, $y1, $z2, $x2, $y2, $z2, $wall);
		if($inside !== null && $x2 - $x1 >= 2 && $y2 - $y1 >= 2 && $z2 - $z1 >= 2){
			$this->fill($x1 + 1, $y1 + 1, $z1 + 1, $x2 - 1, $y2 - 1, $z2 - 1, $inside);
		}
	}

	/** Fills with a block chosen per position (e.g. random cracked/mossy variants). */
	public function fillWith(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, \Closure $picker) : void{
		for($x = max($x1, $this->minX); $x <= min($x2, $this->maxX); ++$x){
			for($z = max($z1, $this->minZ); $z <= min($z2, $this->maxZ); ++$z){
				for($y = $y1; $y <= $y2; ++$y){
					$block = $picker($x, $y, $z);
					if($block !== null){
						$this->writer->setBlock($x, $y, $z, $block);
					}
				}
			}
		}
	}

	/** Replaces only air (and liquids if $liquids) in a region. */
	public function fillAir(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, Block $block, bool $liquids = false) : void{
		$id = $block->getStateId();
		for($x = max($x1, $this->minX); $x <= min($x2, $this->maxX); ++$x){
			for($z = max($z1, $this->minZ); $z <= min($z2, $this->maxZ); ++$z){
				for($y = $y1; $y <= $y2; ++$y){
					$cur = $this->writer->get($x, $y, $z);
					if($cur === $this->airId || ($liquids && $this->isLiquid($cur))){
						$this->writer->set($x, $y, $z, $id);
					}
				}
			}
		}
	}

	private function isLiquid(int $state) : bool{
		$block = \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($state);
		return $block->getTypeId() === BlockTypeIds::WATER || $block->getTypeId() === BlockTypeIds::LAVA;
	}

	/**
	 * Extends a foundation of $block downwards from y-1 until solid ground is
	 * reached (max $maxDepth blocks), like vanilla's structure foundations.
	 */
	public function foundation(int $x, int $y, int $z, Block $block, int $maxDepth = 24) : void{
		if(!$this->inside($x, $z)){
			return;
		}
		$id = $block->getStateId();
		for($yy = $y - 1, $end = $y - $maxDepth; $yy > $end; --$yy){
			$cur = $this->writer->getBlock($x, $yy, $z);
			if($cur->isSolid() && !$cur instanceof \pocketmine\block\Leaves && !$cur instanceof \pocketmine\block\Wood){
				return;
			}
			$this->writer->set($x, $yy, $z, $id);
		}
	}

	/** Clears blocks above a level (removes terrain/trees covering a surface structure). */
	public function clearAbove(int $x1, int $z1, int $x2, int $z2, int $fromY, int $height) : void{
		$this->fill($x1, $fromY, $z1, $x2, $fromY + $height, $z2, VanillaBlocks::AIR());
	}
}
