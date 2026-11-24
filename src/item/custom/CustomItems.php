<?php

/*
 * BetterPMMP - custom items from custom_items.yml with an automatically built resource pack.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\custom;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CreativeInventory;
use pocketmine\block\BlockToolType;
use pocketmine\item\Armor;
use pocketmine\item\ArmorTypeInfo;
use pocketmine\item\ToolTier;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\resourcepacks\ResourcePackManager;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\utils\Config;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hexdec;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function json_encode;
use function ksort;
use function max;
use function md5;
use function mkdir;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Lets server owners add items without writing a plugin, like PowerNukkitX's custom item API:
 *
 *   custom_items.yml               the item list (created with an example on first start)
 *   custom_items/<texture>.png     the textures (16x16 or bigger, square)
 *
 * Every item gets an own item id, appears in the creative inventory and works with /give. The textures
 * are put into a resource pack (betterpmmp_custom_items.mcpack) that is sent to Bedrock players; its
 * id and version change with its contents, so clients download it again only after a change.
 */
final class CustomItems{
	private const BASE_TYPE_ID = 50000;

	/** @var array<string, Item> identifier => item */
	private static array $items = [];

	/** @return array<string, Item> */
	public static function getAll() : array{
		return self::$items;
	}

	public static function load(string $dataPath, ResourcePackManager $packs, \Logger $logger) : void{
		$file = Path::join($dataPath, "custom_items.yml");
		if(!file_exists($file)){
			@file_put_contents($file, self::EXAMPLE);
		}
		$textureDir = Path::join($dataPath, "custom_items");
		if(!is_dir($textureDir)){
			@mkdir($textureDir);
		}
		$config = new Config($file, Config::YAML, ["items" => []]);
		$entries = $config->get("items", []);
		if(!is_array($entries)){
			$entries = [];
		}

		$definitions = [];
		foreach($entries as $identifier => $data){
			$identifier = (string) $identifier;
			if(!is_array($data)){
				$logger->warning("[Custom items] $identifier: expected a list of settings");
				continue;
			}
			if(preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $identifier) !== 1 || str_starts_with($identifier, "minecraft:")){
				$logger->warning("[Custom items] \"$identifier\" is not a valid id (use namespace:name with a-z, 0-9 and _, not minecraft:)");
				continue;
			}
			$texture = Path::join($textureDir, (string) ($data["texture"] ?? (substr($identifier, (int) strpos($identifier, ":") + 1) . ".png")));
			if(!is_file($texture) || substr((string) file_get_contents($texture, length: 8), 0, 8) !== "\x89PNG\r\n\x1a\n"){
				$logger->warning("[Custom items] $identifier: texture $texture is missing or not a PNG file");
				continue;
			}
			$definitions[$identifier] = [
				"name" => (string) ($data["name"] ?? $identifier),
				"texture" => $texture,
				"max-stack" => max(1, min(64, (int) ($data["max-stack"] ?? 64))),
				"hand-equipped" => (bool) ($data["hand-equipped"] ?? false),
				"glint" => (bool) ($data["glint"] ?? false),
				"food" => is_array($data["food"] ?? null) ? $data["food"] : null,
				"tool" => is_array($data["tool"] ?? null) ? $data["tool"] : null,
				"armor" => is_array($data["armor"] ?? null) ? $data["armor"] : null,
			];
			if($definitions[$identifier]["tool"] !== null && self::toolType((string) ($definitions[$identifier]["tool"]["type"] ?? "")) === null){
				$logger->warning("[Custom items] $identifier: tool type must be pickaxe, axe, shovel, hoe or sword");
				unset($definitions[$identifier]);
			}elseif($definitions[$identifier]["armor"] !== null && self::armorSlot((string) ($definitions[$identifier]["armor"]["slot"] ?? "")) === null){
				$logger->warning("[Custom items] $identifier: armor slot must be helmet, chestplate, leggings or boots");
				unset($definitions[$identifier]);
			}
		}
		if($definitions === [] && \pocketmine\block\custom\CustomBlocks::definitions() === [] && \pocketmine\entity\custom\CustomEntities::getTypes() === []){
			return;
		}
		ksort($definitions); //stable type ids no matter in which order the file lists them

		$dictionary = TypeConverter::getInstance()->getItemTypeDictionary();
		$nextRuntimeId = 0;
		foreach($dictionary->getEntries() as $entry){
			$nextRuntimeId = max($nextRuntimeId, $entry->getNumericId() + 1);
		}
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();
		$parser = StringToItemParser::getInstance();
		$newEntries = [];
		$index = 0;
		foreach($definitions as $identifier => $definition){
			$typeId = self::BASE_TYPE_ID + $index++;
			$item = self::createItem(new ItemIdentifier($typeId), $definition);
			try{
				$serializer->map($item, fn() => new SavedItemData($identifier));
				$deserializer->map($identifier, fn() => clone $item);
				$parser->register($identifier, fn() => clone $item);
			}catch(\InvalidArgumentException $e){
				$logger->warning("[Custom items] $identifier could not be registered: " . $e->getMessage());
				continue;
			}
			$runtimeId = $nextRuntimeId++;
			self::$items[$identifier] = $item;
			$newEntries[] = new ItemTypeEntry($identifier, $runtimeId, true, 1, new CacheableNbt(self::components($identifier, $runtimeId, $definition)));
		}
		self::addToDictionary($dictionary, $newEntries);
		foreach(self::$items as $item){
			CreativeInventory::getInstance()->add(clone $item, CreativeCategory::ITEMS);
		}

		try{
			$pack = self::buildPack($dataPath, $definitions);
			$packs->setResourceStack([...$packs->getResourceStack(), new ZippedResourcePack($pack)]);
		}catch(\Throwable $e){
			$logger->error("[Custom items] The resource pack with the textures could not be built: " . $e->getMessage());
		}
		if(self::$items !== []){
			$logger->info("[Custom items] " . count(self::$items) . " custom item(s) loaded");
		}
	}

	private const TOOL_TYPES = [
		"pickaxe" => BlockToolType::PICKAXE,
		"axe" => BlockToolType::AXE,
		"shovel" => BlockToolType::SHOVEL,
		"hoe" => BlockToolType::HOE,
		"sword" => BlockToolType::SWORD,
	];
	private const ARMOR_SLOTS = [
		"helmet" => [ArmorInventory::SLOT_HEAD, "slot.armor.head", "armor_head"],
		"chestplate" => [ArmorInventory::SLOT_CHEST, "slot.armor.chest", "armor_torso"],
		"leggings" => [ArmorInventory::SLOT_LEGS, "slot.armor.legs", "armor_legs"],
		"boots" => [ArmorInventory::SLOT_FEET, "slot.armor.feet", "armor_feet"],
	];
	/** blocks each tool mines faster, as Molang for the client (it predicts the breaking time) */
	private const DIGGER_TAGS = [
		"pickaxe" => "q.any_tag('minecraft:is_pickaxe_item_destructible', 'stone', 'metal', 'rail', 'iron_pick_diggable', 'diamond_pick_diggable', 'gold_pick_diggable', 'stone_pick_diggable')",
		"axe" => "q.any_tag('minecraft:is_axe_item_destructible', 'wood', 'pumpkin', 'plant', 'log')",
		"shovel" => "q.any_tag('minecraft:is_shovel_item_destructible', 'dirt', 'sand', 'gravel', 'grass', 'snow')",
		"hoe" => "q.any_tag('minecraft:is_hoe_item_destructible', 'crop')",
		"sword" => "q.any_tag('minecraft:is_sword_item_destructible')",
	];

	private static function toolType(string $name) : ?int{
		return self::TOOL_TYPES[strtolower($name)] ?? null;
	}

	/** @return array{int, string, string}|null [armor inventory slot, client slot, enchantable slot] */
	private static function armorSlot(string $name) : ?array{
		return self::ARMOR_SLOTS[strtolower($name)] ?? null;
	}

	private static function tier(string $name) : ToolTier{
		foreach(ToolTier::cases() as $tier){
			if(strtolower($tier->name) === strtolower($name)){
				return $tier;
			}
		}
		return ToolTier::IRON;
	}

	/** @param array<string, mixed> $definition */
	private static function createItem(ItemIdentifier $id, array $definition) : Item{
		if(($food = $definition["food"]) !== null){
			return new CustomFood($id, $definition["name"], $definition["max-stack"], (int) ($food["nutrition"] ?? 4), (float) ($food["saturation"] ?? 2.4), (bool) ($food["can-always-eat"] ?? false));
		}
		if(($tool = $definition["tool"]) !== null){
			$tier = self::tier((string) ($tool["tier"] ?? "iron"));
			$type = (string) $tool["type"];
			//vanilla attack: sword = tier + 4, axe = tier + 3 (iron 6, diamond 7), pickaxe = tier + 2, shovel = tier + 1, hoe 1
			$attack = (int) ($tool["attack"] ?? match($type){
				"sword" => $tier->getBaseAttackPoints(),
				"axe" => $tier->getBaseAttackPoints() - 1,
				"pickaxe" => $tier->getBaseAttackPoints() - 2,
				"shovel" => $tier->getBaseAttackPoints() - 3,
				default => 1,
			});
			return new CustomTool($id, $definition["name"], $tier, self::TOOL_TYPES[$type], max(1, (int) ($tool["durability"] ?? $tier->getMaxDurability())), max(1, $attack));
		}
		if(($armor = $definition["armor"]) !== null){
			[$slot] = self::ARMOR_SLOTS[strtolower((string) $armor["slot"])];
			return new Armor($id, $definition["name"], new ArmorTypeInfo(
				max(0, (int) ($armor["defense"] ?? 2)),
				max(1, (int) ($armor["durability"] ?? 200)),
				$slot,
				max(0, (int) ($armor["toughness"] ?? 0)),
				(bool) ($armor["fireproof"] ?? false)
			));
		}
		return new CustomItem($id, $definition["name"], $definition["max-stack"]);
	}

	/**
	 * Item components as the client expects them for data-driven items.
	 * @param array{name: string, texture: string, max-stack: int, hand-equipped: bool, glint: bool, food: ?array<mixed>} $definition
	 */
	private static function components(string $identifier, int $runtimeId, array $definition) : CompoundTag{
		$texture = str_replace(":", "_", $identifier);
		$icon = CompoundTag::create()->setTag("textures", CompoundTag::create()->setString("default", $texture));
		$properties = CompoundTag::create()
			->setTag("minecraft:icon", $icon)
			->setInt("max_stack_size", $definition["max-stack"])
			->setByte("allow_off_hand", 1)
			->setByte("hand_equipped", $definition["hand-equipped"] ? 1 : 0)
			->setByte("foil", $definition["glint"] ? 1 : 0)
			->setInt("creative_category", 4) //items
			->setString("creative_group", "");
		$components = CompoundTag::create()
			->setTag("item_properties", $properties)
			->setTag("minecraft:icon", clone $icon)
			->setTag("minecraft:display_name", CompoundTag::create()->setString("value", $definition["name"]))
			->setTag("minecraft:max_stack_size", CompoundTag::create()->setByte("value", $definition["max-stack"]));
		if(($tool = $definition["tool"]) !== null){
			$type = strtolower((string) $tool["type"]);
			$tier = self::tier((string) ($tool["tier"] ?? "iron"));
			$item = self::$items[$identifier] ?? null;
			$properties->setInt("max_stack_size", 1)->setByte("hand_equipped", 1)
				->setString("enchantable_slot", $type)->setInt("enchantable_value", $tier->getEnchantability());
			$components->setTag("minecraft:max_stack_size", CompoundTag::create()->setByte("value", 1));
			$components->setTag("minecraft:durability", CompoundTag::create()->setInt("max_durability", max(1, (int) ($tool["durability"] ?? $tier->getMaxDurability()))));
			$components->setTag("minecraft:damage", CompoundTag::create()->setInt("value", $item instanceof CustomTool ? $item->getAttackPoints() : 1));
			$components->setTag("minecraft:digger", CompoundTag::create()
				->setByte("use_efficiency", 1)
				->setTag("destroy_speeds", new ListTag([CompoundTag::create()
					->setTag("block", CompoundTag::create()->setString("tags", self::DIGGER_TAGS[$type]))
					->setInt("speed", $tier->getBaseEfficiency())], \pocketmine\nbt\NBT::TAG_Compound)));
			$components->setTag("minecraft:tags", CompoundTag::create()->setTag("tags", new ListTag([
				new StringTag("minecraft:is_" . $type),
				new StringTag("minecraft:" . strtolower($tier->name) . "_tier"),
				new StringTag("minecraft:is_tool"),
			], \pocketmine\nbt\NBT::TAG_String)));
		}
		if(($armor = $definition["armor"]) !== null){
			[, $clientSlot, $enchantSlot] = self::ARMOR_SLOTS[strtolower((string) $armor["slot"])];
			$properties->setInt("max_stack_size", 1)->setString("enchantable_slot", $enchantSlot)->setInt("enchantable_value", 10);
			$components->setTag("minecraft:max_stack_size", CompoundTag::create()->setByte("value", 1));
			$components->setTag("minecraft:durability", CompoundTag::create()->setInt("max_durability", max(1, (int) ($armor["durability"] ?? 200))));
			$components->setTag("minecraft:wearable", CompoundTag::create()->setString("slot", $clientSlot)->setInt("protection", max(0, (int) ($armor["defense"] ?? 2))));
		}
		$food = $definition["food"];
		if($food !== null){
			$properties->setInt("use_duration", 32)->setInt("use_animation", 1); //eat
			$components->setTag("minecraft:food", CompoundTag::create()
				->setInt("nutrition", (int) ($food["nutrition"] ?? 4))
				->setFloat("saturation_modifier", (float) ($food["saturation"] ?? 2.4) / max(1, (int) ($food["nutrition"] ?? 4)) / 2)
				->setByte("can_always_eat", (bool) ($food["can-always-eat"] ?? false) ? 1 : 0));
			$components->setTag("minecraft:use_modifiers", CompoundTag::create()->setFloat("use_duration", 1.6)->setFloat("movement_modifier", 0.35));
		}
		return CompoundTag::create()
			->setTag("components", $components)
			->setInt("id", $runtimeId)
			->setString("name", $identifier);
	}

	/** @param list<ItemTypeEntry> $entries */
	private static function addToDictionary(ItemTypeDictionary $dictionary, array $entries) : void{
		(function() use ($entries) : void{
			foreach($entries as $entry){
				$this->itemTypes[] = $entry;
				$this->stringToIntMap[$entry->getStringId()] = $entry->getNumericId();
				$this->intToStringIdMap[$entry->getNumericId()] = $entry->getStringId();
			}
		})->call($dictionary);
	}

	/**
	 * @param array<string, array{name: string, texture: string, max-stack: int, hand-equipped: bool, glint: bool, food: ?array<mixed>}> $definitions
	 * @return string path of the pack
	 */
	private static function buildPack(string $dataPath, array $definitions) : string{
		$files = [];
		$textureData = [];
		$lang = "";
		foreach($definitions as $identifier => $definition){
			$key = str_replace(":", "_", $identifier);
			$files["textures/items/" . $key . ".png"] = (string) file_get_contents($definition["texture"]);
			$textureData[$key] = ["textures" => "textures/items/" . $key];
			$lang .= "item." . $identifier . ".name=" . $definition["name"] . "\n";
		}
		foreach([...\pocketmine\block\custom\CustomBlocks::packFiles(), ...\pocketmine\entity\custom\CustomEntities::packFiles()] as $name => $data){
			$files[$name] = $data;
		}
		$lang .= ($files["texts/blocks.lang"] ?? "") . ($files["texts/entities.lang"] ?? "");
		unset($files["texts/blocks.lang"], $files["texts/entities.lang"]);
		$files["textures/item_texture.json"] = (string) json_encode(["resource_pack_name" => "betterpmmp_custom_items", "texture_name" => "atlas.items", "texture_data" => $textureData], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$files["texts/en_US.lang"] = $lang;
		$files["texts/de_DE.lang"] = $lang;
		$files["texts/languages.json"] = '["en_US","de_DE"]';

		//same contents => same id and version => the client keeps its cached copy
		$hash = md5((string) json_encode(array_map(fn(string $data) => md5($data), $files)));
		$uuid = sprintf("%s-%s-4%s-8%s-%s", substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
		$moduleUuid = sprintf("%s-%s-4%s-8%s-%s", substr(md5($hash), 0, 8), substr(md5($hash), 8, 4), substr(md5($hash), 13, 3), substr(md5($hash), 17, 3), substr(md5($hash), 20, 12));
		$version = [1, (int) hexdec(substr($hash, 0, 4)), (int) hexdec(substr($hash, 4, 4))];
		$files["manifest.json"] = (string) json_encode([
			"format_version" => 2,
			"header" => ["name" => "BetterPMMP custom content", "description" => "Textures and models of the server's custom items, blocks and entities", "uuid" => $uuid, "version" => $version, "min_engine_version" => [1, 21, 0]],
			"modules" => [["type" => "resources", "uuid" => $moduleUuid, "version" => $version]],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		$path = Path::join($dataPath, "betterpmmp_custom_items.mcpack");
		$zip = new \ZipArchive();
		if($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
			throw new \RuntimeException("cannot write $path");
		}
		foreach($files as $name => $data){
			$zip->addFromString($name, $data);
		}
		$zip->close();
		return $path;
	}

	private const EXAMPLE = <<<'YAML'
# BetterPMMP custom items
#
# Every entry adds an item. Put its texture (PNG, square, e.g. 16x16) into the folder "custom_items".
# The textures are sent to Bedrock players as a resource pack automatically.
# After a change, restart the server. Give an item with: /give @s myserver:ruby
#
# Settings:
#   name           shown name
#   texture        file in custom_items/ (default: <name after the colon>.png)
#   max-stack      1 to 64 (default 64)
#   hand-equipped  true = held like a tool (default false)
#   glint          true = enchantment glint (default false)
#   food           makes it edible: nutrition (hunger points), saturation, can-always-eat
#   tool           makes it a tool: type (pickaxe, axe, shovel, hoe, sword), tier (wood, stone, iron, gold,
#                  diamond, netherite, copper; default iron), durability, attack (defaults from the tier)
#   armor          makes it armour: slot (helmet, chestplate, leggings, boots), defense, durability, toughness,
#                  fireproof. It protects, but is not drawn on the player's body (that needs a model)
#
# Example (remove the # in front of the lines to use it):
#
# items:
#   myserver:ruby:
#     name: "Ruby"
#     texture: ruby.png
#   myserver:ruby_sword:
#     name: "Ruby Sword"
#     tool:
#       type: sword
#       tier: diamond
#       attack: 8
#       durability: 2000
#   myserver:ruby_helmet:
#     name: "Ruby Helmet"
#     armor:
#       slot: helmet
#       defense: 3
#       durability: 400
#   myserver:golden_bread:
#     name: "Golden Bread"
#     food:
#       nutrition: 8
#       saturation: 12.8
#       can-always-eat: true

items: {}
YAML;
}
