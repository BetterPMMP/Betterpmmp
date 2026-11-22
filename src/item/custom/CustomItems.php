<?php

/*
 * BetterPMMP - custom items from custom_items.yml with an automatically built resource pack.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\custom;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\CreativeInventory;
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
			];
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
			$food = $definition["food"];
			$item = $food !== null
				? new CustomFood(new ItemIdentifier($typeId), $definition["name"], $definition["max-stack"], (int) ($food["nutrition"] ?? 4), (float) ($food["saturation"] ?? 2.4), (bool) ($food["can-always-eat"] ?? false))
				: new CustomItem(new ItemIdentifier($typeId), $definition["name"], $definition["max-stack"]);
			try{
				$serializer->map($item, fn() => new SavedItemData($identifier));
				$deserializer->map($identifier, fn() => clone $item);
				$parser->register($identifier, fn() => clone $item);
			}catch(\InvalidArgumentException $e){
				$logger->warning("[Custom items] $identifier could not be registered: " . $e->getMessage());
				continue;
			}
			$runtimeId = $nextRuntimeId++;
			$newEntries[] = new ItemTypeEntry($identifier, $runtimeId, true, 1, new CacheableNbt(self::components($identifier, $runtimeId, $definition)));
			self::$items[$identifier] = $item;
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
#
# Example (remove the # in front of the lines to use it):
#
# items:
#   myserver:ruby:
#     name: "Ruby"
#     texture: ruby.png
#   myserver:golden_bread:
#     name: "Golden Bread"
#     food:
#       nutrition: 8
#       saturation: 12.8
#       can-always-eat: true

items: {}
YAML;
}
