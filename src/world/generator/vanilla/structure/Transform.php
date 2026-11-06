<?php

/*
 * BetterPMMP - rotated local coordinates for structure drawing.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;

/**
 * Maps local coordinates (x = right, z = forward, y = up) of a structure
 * rotated by 0-3 clockwise quarter turns onto world coordinates, and rotates
 * facings accordingly. Local (0, 0, 0) is the origin corner.
 */
final class Transform{
	private int $fx;
	private int $fz;
	private int $rx;
	private int $rz;

	public function __construct(
		public readonly StructureCanvas $c,
		public readonly int $ox,
		public readonly int $oy,
		public readonly int $oz,
		public readonly int $rotation
	){
		$forward = Facing::SOUTH;
		for($i = 0; $i < ($rotation & 3); ++$i){
			$forward = Facing::rotateY($forward, true);
		}
		[$this->fx, , $this->fz] = Facing::OFFSET[$forward];
		[$this->rx, , $this->rz] = Facing::OFFSET[Facing::rotateY($forward, false)];
	}

	/** @return int[] */
	public function pos(int $lx, int $ly, int $lz) : array{
		return [$this->ox + $this->rx * $lx + $this->fx * $lz, $this->oy + $ly, $this->oz + $this->rz * $lx + $this->fz * $lz];
	}

	/** Local facing (NORTH = towards -z local, EAST = +x local ...) to world facing. */
	public function facing(int $local) : int{
		if(Facing::axis($local) === Axis::Y){
			return $local;
		}
		//local SOUTH (+z) is forward
		$world = $local;
		for($i = 0; $i < ($this->rotation & 3); ++$i){
			$world = Facing::rotateY($world, true);
		}
		return $world;
	}

	public function set(int $lx, int $ly, int $lz, Block $block, ?CompoundTag $tile = null) : void{
		[$x, $y, $z] = $this->pos($lx, $ly, $lz);
		$this->c->set($x, $y, $z, $block, $tile);
	}

	public function get(int $lx, int $ly, int $lz) : int{
		[$x, $y, $z] = $this->pos($lx, $ly, $lz);
		return $this->c->get($x, $y, $z);
	}

	public function isAir(int $lx, int $ly, int $lz) : bool{
		[$x, $y, $z] = $this->pos($lx, $ly, $lz);
		return $this->c->isAir($x, $y, $z);
	}

	public function fill(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, Block $block) : void{
		[$ax, $ay, $az] = $this->pos($x1, $y1, $z1);
		[$bx, $by, $bz] = $this->pos($x2, $y2, $z2);
		$this->c->fill(min($ax, $bx), min($ay, $by), min($az, $bz), max($ax, $bx), max($ay, $by), max($az, $bz), $block);
	}

	public function box(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, Block $wall, ?Block $inside) : void{
		[$ax, $ay, $az] = $this->pos($x1, $y1, $z1);
		[$bx, $by, $bz] = $this->pos($x2, $y2, $z2);
		$this->c->box(min($ax, $bx), min($ay, $by), min($az, $bz), max($ax, $bx), max($ay, $by), max($az, $bz), $wall, $inside);
	}

	public function foundation(int $lx, int $ly, int $lz, Block $block, int $depth = 24) : void{
		[$x, $y, $z] = $this->pos($lx, $ly, $lz);
		$this->c->foundation($x, $y, $z, $block, $depth);
	}

	/** World bounding box of a local box: [minX, minZ, maxX, maxZ]. */
	public static function bounds(int $ox, int $oz, int $rotation, int $sizeX, int $sizeZ) : array{
		$forward = Facing::SOUTH;
		for($i = 0; $i < ($rotation & 3); ++$i){
			$forward = Facing::rotateY($forward, true);
		}
		[$fx, , $fz] = Facing::OFFSET[$forward];
		[$rx, , $rz] = Facing::OFFSET[Facing::rotateY($forward, false)];
		$xs = [$ox, $ox + $rx * ($sizeX - 1) + $fx * ($sizeZ - 1), $ox + $rx * ($sizeX - 1), $ox + $fx * ($sizeZ - 1)];
		$zs = [$oz, $oz + $rz * ($sizeX - 1) + $fz * ($sizeZ - 1), $oz + $rz * ($sizeX - 1), $oz + $fz * ($sizeZ - 1)];
		return [min($xs), min($zs), max($xs), max($zs)];
	}
}
