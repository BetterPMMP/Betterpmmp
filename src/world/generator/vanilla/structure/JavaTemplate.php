<?php

/*
 * BetterPMMP - vanilla structure templates (structure NBT) and their blocks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\Block;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\utils\SlabType;
use pocketmine\block\utils\StairShape;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use function array_key_exists;
use function count;
use function dirname;
use function file_get_contents;
use function gzdecode;
use function is_file;
use function ksort;
use function str_ends_with;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * A vanilla structure template: the blocks of one building part, read from the structure NBT file the
 * game itself uses (resources/structures). Blocks are kept by their Java name and properties and turned
 * into blocks of this server when they are placed, already rotated.
 */
final class JavaTemplate{

	/** @var array<string, ?JavaTemplate> */
	private static array $cache = [];
	/** @var array<string, ?Block> */
	private static array $blockCache = [];

	/**
	 * @param list<array{int, int, int, string, array<string, string>, string}> $blocks [x, y, z, name, properties, data marker]
	 */
	private function __construct(
		public readonly int $sizeX,
		public readonly int $sizeY,
		public readonly int $sizeZ,
		public readonly array $blocks
	){}

	/** Loads resources/structures/<name>.nbt, for example "end_city/ship". */
	public static function get(string $name) : ?self{
		if(array_key_exists($name, self::$cache)){
			return self::$cache[$name];
		}
		return self::$cache[$name] = self::read(dirname(__DIR__, 5) . "/resources/structures/" . $name . ".nbt");
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
			$palette = [];
			foreach($root->getListTag("palette") ?? [] as $entry){
				$name = "minecraft:air";
				$properties = [];
				if($entry instanceof CompoundTag){
					//old files: Name/Properties, files since 26.1: id/properties
					$nameTag = $entry->getTag("Name") ?? $entry->getTag("id");
					if($nameTag instanceof StringTag){
						$name = $nameTag->getValue();
					}
					foreach($entry->getCompoundTag("Properties") ?? $entry->getCompoundTag("properties") ?? [] as $key => $value){
						if($value instanceof StringTag){
							$properties[(string) $key] = $value->getValue();
						}
					}
				}
				ksort($properties);
				$palette[] = [str_starts_with($name, "minecraft:") ? substr($name, 10) : $name, $properties];
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
				$marker = "";
				$nbt = $entry->getCompoundTag("nbt");
				if($nbt !== null && $palette[$state][0] === "structure_block" && ($metadata = $nbt->getTag("metadata")) instanceof StringTag){
					$marker = $metadata->getValue();
				}
				$blocks[] = [$pos[0], $pos[1], $pos[2], $palette[$state][0], $palette[$state][1], $marker];
			}
			return new self($size[0], $size[1], $size[2], $blocks);
		}catch(\Throwable){
			return null;
		}
	}

	/** A horizontal Java direction turned clockwise by quarter turns, as a Facing; other names are returned as they are. */
	public static function facing(string $name, int $rotation) : ?int{
		$facing = match($name){
			"north" => Facing::NORTH,
			"east" => Facing::EAST,
			"south" => Facing::SOUTH,
			"west" => Facing::WEST,
			"up" => Facing::UP,
			"down" => Facing::DOWN,
			default => null,
		};
		if($facing === null || Facing::axis($facing) === Axis::Y){
			return $facing;
		}
		for($i = $rotation & 3; $i > 0; --$i){
			$facing = Facing::rotateY($facing, true);
		}
		return $facing;
	}

	/**
	 * The block of this server for a Java block, turned clockwise by quarter turns; null if it has none.
	 * @param array<string, string> $properties
	 */
	public static function block(string $name, array $properties, int $rotation) : ?Block{
		$rotation &= 3;
		$key = $name . "|" . $rotation;
		foreach($properties as $property => $value){
			$key .= "|" . $property . "=" . $value;
		}
		if(array_key_exists($key, self::$blockCache)){
			return self::$blockCache[$key];
		}
		try{
			$block = self::create($name, $properties, $rotation);
		}catch(\Throwable){
			$block = null;
		}
		return self::$blockCache[$key] = $block;
	}

	private static function color(string $name) : DyeColor{
		foreach(DyeColor::cases() as $color){
			if(strtolower($color->name) === $name){
				return $color;
			}
		}
		return DyeColor::WHITE;
	}

	/**
	 * @param array<string, string> $p
	 */
	private static function create(string $name, array $p, int $rotation) : ?Block{
		$facing = isset($p["facing"]) ? self::facing($p["facing"], $rotation) : null;
		$axis = match($p["axis"] ?? ""){
			"x" => ($rotation & 1) === 1 ? Axis::Z : Axis::X,
			"z" => ($rotation & 1) === 1 ? Axis::X : Axis::Z,
			"y" => Axis::Y,
			default => null,
		};
		if(str_ends_with($name, "_stained_glass")){
			return VanillaBlocks::STAINED_GLASS()->setColor(self::color(substr($name, 0, -14)));
		}
		if(str_ends_with($name, "_wall_banner")){
			return VanillaBlocks::WALL_BANNER()->setColor(self::color(substr($name, 0, -12)))->setFacing($facing ?? Facing::NORTH);
		}
		if($name === "dragon_wall_head"){
			return VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::DRAGON)->setFacing($facing ?? Facing::NORTH);
		}
		$block = match($name){
			"air", "cave_air", "void_air" => VanillaBlocks::AIR(),
			"purpur_block" => VanillaBlocks::PURPUR(),
			default => null,
		};
		if($block === null){
			$all = VanillaBlocks::getAll();
			$block = $all[strtoupper($name)] ?? null;
			if($block === null){
				return null;
			}
			$block = clone $block;
		}
		if($block instanceof \pocketmine\block\Stair){
			$block->setFacing($facing ?? Facing::NORTH);
			$block->setUpsideDown(($p["half"] ?? "bottom") === "top");
			$block->setShape(match($p["shape"] ?? "straight"){
				"inner_left" => StairShape::INNER_LEFT,
				"inner_right" => StairShape::INNER_RIGHT,
				"outer_left" => StairShape::OUTER_LEFT,
				"outer_right" => StairShape::OUTER_RIGHT,
				default => StairShape::STRAIGHT,
			});
			return $block;
		}
		if($block instanceof \pocketmine\block\Slab){
			return $block->setSlabType(match($p["type"] ?? "bottom"){
				"top" => SlabType::TOP,
				"double" => SlabType::DOUBLE,
				default => SlabType::BOTTOM,
			});
		}
		if($axis !== null && $block instanceof \pocketmine\block\utils\PillarRotation){
			$block->setAxis($axis);
		}
		if($facing !== null && ($block instanceof \pocketmine\block\utils\HorizontalFacing || $block instanceof \pocketmine\block\utils\AnyFacing)){
			$block->setFacing($facing);
		}
		return $block;
	}
}
