<?php

/*
 * BetterPMMP - structure template loading and rotated placement.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\block\Door;
use pocketmine\block\Stair;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\PillarRotation;
use pocketmine\block\utils\StairShape;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use function count;
use function file_get_contents;
use function gzdecode;
use function is_file;
use function method_exists;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * A structure template in the structure-NBT format (as used by the bundled
 * village houses). The template's horizontal jigsaw marks its entrance side,
 * which is used to rotate the template so the entrance faces a road.
 */
final class StructureTemplate{

	/** @var array<string, ?StructureTemplate> */
	private static array $cache = [];

	/**
	 * @param array{int, int, int, Block}[] $blocks
	 */
	private function __construct(
		public readonly int $sizeX,
		public readonly int $sizeY,
		public readonly int $sizeZ,
		private array $blocks,
		public readonly int $front
	){}

	public static function load(string $path) : ?self{
		if(array_key_exists($path, self::$cache)){
			return self::$cache[$path];
		}
		return self::$cache[$path] = self::read($path);
	}

	private static function read(string $path) : ?self{
		if(!is_file($path)){
			return null;
		}
		$compressed = @file_get_contents($path);
		$decoded = $compressed === false ? false : @gzdecode($compressed);
		if($decoded === false){
			return null;
		}
		try{
			$root = (new BigEndianNbtSerializer())->read($decoded)->mustGetCompoundTag();
			$size = [];
			foreach($root->getListTag("size") ?? [] as $dimension){
				$size[] = (int) $dimension->getValue();
			}
			if(count($size) !== 3){
				return null;
			}
			$registry = VanillaBlocks::getAll();
			$palette = [];
			$front = Facing::NORTH;
			$jigsawStates = [];
			foreach($root->getListTag("palette") ?? [] as $index => $entry){
				if(!$entry instanceof CompoundTag){
					$palette[] = VanillaBlocks::AIR();
					continue;
				}
				$source = strtoupper(str_replace("minecraft:", "", $entry->getString("Name", "air")));
				$properties = [];
				foreach($entry->getCompoundTag("Properties") ?? [] as $key => $value){
					if($value instanceof StringTag){
						$properties[$key] = $value->getValue();
					}
				}
				if($source === "JIGSAW"){
					$jigsawStates[$index] = $properties["facing"] ?? ($properties["orientation"] ?? "");
				}
				$name = match($source){
					"GRASS_BLOCK" => "GRASS",
					"SNOW_BLOCK" => "SNOW",
					"DIRT_PATH" => "GRASS_PATH",
					"WALL_TORCH" => "TORCH",
					"POTTED_CACTUS" => "CACTUS",
					"JIGSAW", "STRUCTURE_BLOCK", "STRUCTURE_VOID" => "AIR",
					default => str_ends_with($source, "_BED") ? "BED" : (str_ends_with($source, "_TERRACOTTA") && $source !== "TERRACOTTA" ? "STAINED_CLAY" : ($source === "TERRACOTTA" ? "HARDENED_CLAY" : $source))
				};
				$block = clone ($registry[$name] ?? VanillaBlocks::AIR());
				if($block instanceof Bed){
					$block->setColor(self::dyeColor(strtolower(substr($source, 0, -4))));
				}elseif($name === "STAINED_CLAY" && method_exists($block, "setColor")){
					$block->setColor(self::dyeColor(strtolower(substr($source, 0, -11))));
				}
				$palette[] = self::applyProperties($block, $properties);
			}
			$blocks = [];
			foreach($root->getListTag("blocks") ?? [] as $entry){
				if(!$entry instanceof CompoundTag){
					continue;
				}
				$pos = [];
				foreach($entry->getListTag("pos") ?? [] as $coordinate){
					$pos[] = (int) $coordinate->getValue();
				}
				$state = $entry->getInt("state", -1);
				if(count($pos) !== 3 || !isset($palette[$state])){
					continue;
				}
				if(isset($jigsawStates[$state])){
					$facing = self::facing(explode("_", $jigsawStates[$state])[0]);
					if($facing !== null){
						$front = $facing;
					}
				}
				$blocks[] = [$pos[0], $pos[1], $pos[2], $palette[$state]];
			}
			return new self($size[0], $size[1], $size[2], $blocks, $front);
		}catch(\Throwable){
			return null;
		}
	}

	private static function facing(string $name) : ?int{
		return match($name){
			"north" => Facing::NORTH,
			"south" => Facing::SOUTH,
			"east" => Facing::EAST,
			"west" => Facing::WEST,
			default => null
		};
	}

	private static function dyeColor(string $name) : DyeColor{
		foreach(DyeColor::cases() as $color){
			if(strtolower($color->name) === $name){
				return $color;
			}
		}
		return DyeColor::WHITE;
	}

	/**
	 * @param array<string, string> $states
	 */
	private static function applyProperties(Block $block, array $states) : Block{
		if(isset($states["facing"]) && ($facing = self::facing($states["facing"])) !== null){
			if($block instanceof HorizontalFacing){
				$block->setFacing($facing);
			}elseif(method_exists($block, "setFacing")){
				try{
					$block->setFacing($facing);
				}catch(\Throwable){
				}
			}
		}
		if($block instanceof PillarRotation && isset($states["axis"])){
			$block->setAxis(match($states["axis"]){"x" => Axis::X, "z" => Axis::Z, default => Axis::Y});
		}
		if($block instanceof Stair){
			$block->setUpsideDown(($states["half"] ?? "bottom") === "top");
			$block->setShape(match($states["shape"] ?? "straight"){
				"inner_left" => StairShape::INNER_LEFT,
				"inner_right" => StairShape::INNER_RIGHT,
				"outer_left" => StairShape::OUTER_LEFT,
				"outer_right" => StairShape::OUTER_RIGHT,
				default => StairShape::STRAIGHT
			});
		}
		if($block instanceof Door){
			$block->setTop(($states["half"] ?? "lower") === "upper");
			$block->setHingeRight(($states["hinge"] ?? "left") === "right");
			$block->setOpen(($states["open"] ?? "false") === "true");
		}
		if($block instanceof Bed){
			$block->setHead(($states["part"] ?? "foot") === "head");
		}
		return $block;
	}

	/** Number of clockwise quarter turns needed so the entrance faces $direction. */
	public function turnsToFace(int $direction) : int{
		$facing = $this->front;
		for($turns = 0; $turns < 4; ++$turns){
			if($facing === $direction){
				return $turns;
			}
			$facing = Facing::rotateY($facing, true);
		}
		return 0;
	}

	/** @return int[] [sizeX, sizeZ] after rotation */
	public function rotatedSize(int $turns) : array{
		return ($turns & 1) === 1 ? [$this->sizeZ, $this->sizeX] : [$this->sizeX, $this->sizeZ];
	}

	/**
	 * Places the template with its minimum corner at (x, y, z) after rotating it
	 * clockwise by $turns quarter turns.
	 */
	public function place(BlockWriter $writer, int $x, int $y, int $z, int $turns) : void{
		$turns &= 3;
		foreach($this->blocks as [$bx, $by, $bz, $block]){
			[$rx, $rz] = $this->rotatePoint($bx, $bz, $turns);
			$writer->setBlock($x + $rx, $y + $by, $z + $rz, $turns === 0 ? $block : self::rotateBlock(clone $block, $turns));
		}
	}

	/**
	 * Same as {@link place()}, but through a structure canvas (writes are
	 * clipped to the chunk being populated).
	 */
	public function placeOnCanvas(\pocketmine\world\generator\vanilla\structure\StructureCanvas $canvas, int $x, int $y, int $z, int $turns) : void{
		$turns &= 3;
		foreach($this->blocks as [$bx, $by, $bz, $block]){
			[$rx, $rz] = $this->rotatePoint($bx, $bz, $turns);
			$canvas->set($x + $rx, $y + $by, $z + $rz, $turns === 0 ? $block : self::rotateBlock(clone $block, $turns));
		}
	}

	/** @return int[] */
	private function rotatePoint(int $x, int $z, int $turns) : array{
		$sx = $this->sizeX;
		$sz = $this->sizeZ;
		for($i = 0; $i < $turns; ++$i){
			//clockwise seen from above: east (+x) -> south (+z)
			[$x, $z] = [$sz - 1 - $z, $x];
			[$sx, $sz] = [$sz, $sx];
		}
		return [$x, $z];
	}

	private static function rotateBlock(Block $block, int $turns) : Block{
		if($block instanceof PillarRotation && ($turns & 1) === 1){
			$axis = $block->getAxis();
			if($axis !== Axis::Y){
				$block->setAxis($axis === Axis::X ? Axis::Z : Axis::X);
			}
		}
		if(method_exists($block, "getFacing") && method_exists($block, "setFacing")){
			$facing = $block->getFacing();
			if(is_int($facing) && Facing::axis($facing) !== Axis::Y){
				for($i = 0; $i < $turns; ++$i){
					$facing = Facing::rotateY($facing, true);
				}
				try{
					$block->setFacing($facing);
				}catch(\Throwable){
				}
			}
		}
		return $block;
	}
}
