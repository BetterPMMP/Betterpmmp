<?php

/*
 * BetterPMMP - items of the current Bedrock palette that the core lacks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\data\bedrock\item\ItemDeserializer;
use pocketmine\data\bedrock\item\ItemSerializer;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\ArmorInventory;
use function array_key_exists;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_decode;
use function ksort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function ucwords;

/**
 * Registers every item of the Bedrock 1.26.60 item palette that is neither a
 * block item nor known to the core (elytra, shield, crossbow, saddle, lead,
 * boats, bundles, horse armor, pottery sherds, banner patterns, ...).
 *
 * Type IDs are fixed (base + position in the sorted name list) so that every
 * thread agrees on them. Elytra, mace, shield, crossbow and brush get real
 * item behaviour (armor slot / durability / attack damage); the rest are plain
 * items with the vanilla stack size.
 */
final class ExtraVanillaItems{

	private const BASE_TYPE_ID = 40000;

	/** Legacy aliases, education edition and technical entries of the palette. */
	private const SKIP = [
		"minecraft:air", "minecraft:banner_pattern", "minecraft:boat", "minecraft:carpet", "minecraft:chest_boat",
		"minecraft:colored_torch_bp", "minecraft:colored_torch_rg", "minecraft:concrete", "minecraft:concrete_powder",
		"minecraft:coral", "minecraft:coral_block", "minecraft:coral_fan", "minecraft:coral_fan_dead", "minecraft:double_plant",
		"minecraft:double_stone_block_slab", "minecraft:double_stone_block_slab2", "minecraft:double_stone_block_slab3",
		"minecraft:double_stone_block_slab4", "minecraft:dye", "minecraft:fence", "minecraft:hard_stained_glass",
		"minecraft:hard_stained_glass_pane", "minecraft:leaves", "minecraft:leaves2", "minecraft:log", "minecraft:log2",
		"minecraft:monster_egg", "minecraft:planks", "minecraft:red_flower", "minecraft:sapling", "minecraft:shulker_box",
		"minecraft:skull", "minecraft:spawn_egg", "minecraft:stained_glass", "minecraft:stained_glass_pane",
		"minecraft:stained_hardened_clay", "minecraft:stone_block_slab", "minecraft:stone_block_slab2",
		"minecraft:stone_block_slab3", "minecraft:stone_block_slab4", "minecraft:stonebrick", "minecraft:tallgrass",
		"minecraft:wood", "minecraft:wooden_slab", "minecraft:wool", "minecraft:chemistry_table", "minecraft:balloon",
		"minecraft:glow_stick", "minecraft:ice_ball", "minecraft:sparkler", "minecraft:board", "minecraft:portfolio",
		"minecraft:camera", "minecraft:photo_item", "minecraft:rapid_fertilizer", "minecraft:light_block",
	];

	/** @var array<string, Item>|null */
	private static ?array $items = null;
	private static bool $serializerMapped = false;
	private static bool $deserializerMapped = false;

	/** @return array<string, Item> Bedrock name => item */
	public static function getAll() : array{
		if(self::$items !== null){
			return self::$items;
		}
		self::$items = [];
		$names = self::missingNames();
		foreach($names as $index => $name){
			self::$items[$name] = self::create($name, new ItemIdentifier(self::BASE_TYPE_ID + $index));
		}
		return self::$items;
	}

	public static function get(string $name) : ?Item{
		$item = self::getAll()[$name] ?? null;
		return $item === null ? null : clone $item;
	}

	/**
	 * Names of the palette that are not block items and not known to the core,
	 * sorted so that type IDs stay stable.
	 * @return list<string>
	 */
	private static function missingNames() : array{
		$data = \pocketmine\data\bedrock\BedrockDataFiles::REQUIRED_ITEM_LIST_JSON;
		$list = json_decode((string) file_get_contents($data), true);
		$blockItems = json_decode((string) file_get_contents(\pocketmine\data\bedrock\BedrockDataFiles::BLOCK_ID_TO_ITEM_ID_MAP_JSON), true);
		if(!is_array($list) || !is_array($blockItems)){
			return [];
		}
		$blockItemNames = [];
		foreach($blockItems as $itemName){
			$blockItemNames[$itemName] = true;
		}
		$known = self::coreItemNames();
		$names = [];
		foreach($list as $name => $_){
			if(isset($blockItemNames[$name]) || isset($known[$name]) || in_array($name, self::SKIP, true)){
				continue;
			}
			$names[$name] = true;
		}
		ksort($names);
		return array_keys($names);
	}

	/**
	 * String IDs the core already serializes, read from the core's own
	 * registrar so this never needs the (possibly not yet created) global
	 * deserializer.
	 * @return array<string, true>
	 */
	private static function coreItemNames() : array{
		static $names = null;
		if($names !== null){
			return $names;
		}
		$deserializer = new ItemDeserializer(\pocketmine\world\format\io\GlobalBlockStateHandlers::getDeserializer());
		$names = [];
		$list = json_decode((string) file_get_contents(\pocketmine\data\bedrock\BedrockDataFiles::REQUIRED_ITEM_LIST_JSON), true);
		foreach(is_array($list) ? $list : [] as $name => $_){
			if($deserializer->getDeserializerForId($name) !== null){
				$names[$name] = true;
			}
		}
		return $names;
	}

	private static function displayName(string $name) : string{
		$short = substr($name, strlen("minecraft:"));
		return ucwords(str_replace("_", " ", $short));
	}

	private static function create(string $name, ItemIdentifier $id) : Item{
		$display = self::displayName($name);
		return match(true){
			$name === "minecraft:elytra" => new Elytra($id, "Elytra", new ArmorTypeInfo(0, 432, ArmorInventory::SLOT_CHEST)),
			$name === "minecraft:mace" => new SimpleDurableItem($id, "Mace", 500, 6, 1),
			$name === "minecraft:shield" => new SimpleDurableItem($id, "Shield", 336, 1, 1),
			$name === "minecraft:crossbow" => new SimpleDurableItem($id, "Crossbow", 465, 1, 1),
			$name === "minecraft:brush" => new SimpleDurableItem($id, "Brush", 64, 1, 1),
			$name === "minecraft:carrot_on_a_stick" => new SimpleDurableItem($id, "Carrot on a Stick", 25, 1, 1),
			$name === "minecraft:warped_fungus_on_a_stick" => new SimpleDurableItem($id, "Warped Fungus on a Stick", 100, 1, 1),
			str_ends_with($name, "_boat") || str_ends_with($name, "_raft") => new VehicleBoatItem($id, $display, $name, 1),
			default => new GenericBedrockItem($id, $display, $name, self::stackSize($name)),
		};
	}

	/** Vanilla stack sizes. */
	private static function stackSize(string $name) : int{
		$name = substr($name, strlen("minecraft:"));
		if(str_ends_with($name, "_egg") && !str_ends_with($name, "spawn_egg")){
			return 16; // blue_egg, brown_egg
		}
		if(str_contains($name, "boat") || str_contains($name, "raft") || str_contains($name, "minecart") || str_ends_with($name, "_bucket")
			|| str_contains($name, "horse_armor") || str_contains($name, "nautilus_armor") || str_ends_with($name, "_harness")
			|| str_ends_with($name, "bundle") || str_ends_with($name, "banner_pattern") || str_starts_with($name, "music_disc")
			|| in_array($name, ["saddle", "wolf_armor", "filled_map", "armor_stand"], true)){
			return $name === "armor_stand" ? 16 : 1;
		}
		if(str_ends_with($name, "_sign") || str_ends_with($name, "_hanging_sign")){
			return 16;
		}
		return 64;
	}

	public static function registerSerializers(ItemSerializer $serializer) : void{
		if(self::$serializerMapped){
			return;
		}
		self::$serializerMapped = true;
		foreach(self::getAll() as $name => $item){
			$serializer->map($item, fn() => new SavedItemData($name));
		}
	}

	public static function registerDeserializers(ItemDeserializer $deserializer) : void{
		if(self::$deserializerMapped){
			return;
		}
		self::$deserializerMapped = true;
		foreach(self::getAll() as $name => $item){
			if($deserializer->getDeserializerForId($name) === null){
				$deserializer->map($name, fn() => clone $item);
			}
		}
	}

	/** Aliases for /give and the item parser (name without the "minecraft:" prefix). */
	public static function registerAliases(StringToItemParser $parser) : void{
		foreach(self::getAll() as $name => $item){
			$alias = substr($name, strlen("minecraft:"));
			if($parser->parse($alias) === null){
				$parser->register($alias, fn() => clone $item);
			}
		}
	}

	public static function isRegistered(string $name) : bool{
		return array_key_exists($name, self::getAll());
	}
}
