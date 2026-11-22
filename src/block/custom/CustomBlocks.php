<?php

/*
 * BetterPMMP - custom blocks from custom_blocks.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\custom;

use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockToolType;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\Config;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function hash;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function min;
use function mkdir;
use function preg_match;
use function putenv;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strpos;
use function substr;
use function usort;

/**
 * Lets server owners add simple blocks (one cube, one texture or top/side/bottom textures) without a plugin:
 *
 *   custom_blocks.yml            the block list (created with an example on first start)
 *   custom_blocks/<texture>.png  the textures
 *
 * Blocks are part of every thread's block registry (chunks are sent and generated in worker threads), so the
 * definitions are read once on the main thread and handed to the workers through an environment variable.
 * The client learns about the blocks from the StartGame block palette; network ids are the vanilla ones with
 * the custom blocks sorted in by the FNV-1a 64 hash of their names, the order the client uses.
 *
 * Without entries in custom_blocks.yml nothing of this is active.
 */
final class CustomBlocks{
	private const ENV = "BETTERPMMP_CUSTOM_BLOCKS";
	private const BASE_TYPE_ID = 38000;
	/** numeric ids for the block palette and the block items (the official server uses 10000 and up) */
	private const BASE_NUMERIC_ID = 10000;

	/** @var array<string, array{name: string, textures: array{up: string, down: string, side: string}, hardness: float, tool: string, light: int, blast: float}>|null */
	private static ?array $definitions = null;
	/** @var array<string, Block>|null */
	private static ?array $blocks = null;

	/**
	 * Main thread, before anything touches the block registry: reads custom_blocks.yml.
	 */
	public static function prepare(string $dataPath, \Logger $logger) : void{
		$file = Path::join($dataPath, "custom_blocks.yml");
		if(!file_exists($file)){
			@file_put_contents($file, self::EXAMPLE);
		}
		$textureDir = Path::join($dataPath, "custom_blocks");
		if(!is_dir($textureDir)){
			@mkdir($textureDir);
		}
		$entries = (new Config($file, Config::YAML, ["blocks" => []]))->get("blocks", []);
		$definitions = [];
		foreach(is_array($entries) ? $entries : [] as $identifier => $data){
			$identifier = (string) $identifier;
			if(!is_array($data)){
				continue;
			}
			if(preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $identifier) !== 1 || str_starts_with($identifier, "minecraft:")){
				$logger->warning("[Custom blocks] \"$identifier\" is not a valid id (use namespace:name with a-z, 0-9 and _, not minecraft:)");
				continue;
			}
			$short = substr($identifier, (int) strpos($identifier, ":") + 1);
			$texture = (string) ($data["texture"] ?? $short . ".png");
			$textures = [
				"up" => (string) ($data["texture-top"] ?? $texture),
				"down" => (string) ($data["texture-bottom"] ?? $texture),
				"side" => (string) ($data["texture-side"] ?? $texture),
			];
			foreach($textures as $face => $name){
				$path = Path::join($textureDir, $name);
				if(!is_file($path) || substr((string) file_get_contents($path, length: 8), 0, 8) !== "\x89PNG\r\n\x1a\n"){
					$logger->warning("[Custom blocks] $identifier: texture $path is missing or not a PNG file");
					continue 2;
				}
				$textures[$face] = $path;
			}
			$tool = (string) ($data["tool"] ?? "none");
			if(!in_array($tool, ["none", "pickaxe", "axe", "shovel", "hoe", "shears", "sword"], true)){
				$logger->warning("[Custom blocks] $identifier: unknown tool \"$tool\", using none");
				$tool = "none";
			}
			$hardness = max(0.0, (float) ($data["hardness"] ?? 1.5));
			$definitions[$identifier] = [
				"name" => (string) ($data["name"] ?? $identifier),
				"textures" => $textures,
				"hardness" => $hardness,
				"tool" => $tool,
				"light" => max(0, min(15, (int) ($data["light"] ?? 0))),
				"blast" => max(0.0, (float) ($data["blast-resistance"] ?? $hardness * 5)),
			];
		}
		ksort($definitions); //fixed ids whatever the order in the file
		if($definitions !== [] && self::registryAlreadyBuilt()){
			$logger->error("[Custom blocks] The block registry was created before the custom blocks were read - custom blocks are disabled");
			$definitions = [];
		}
		self::$definitions = $definitions;
		putenv(self::ENV . "=" . json_encode($definitions));
		if($definitions !== []){
			$logger->info("[Custom blocks] " . count($definitions) . " custom block(s) loaded");
		}
	}

	private static function registryAlreadyBuilt() : bool{
		return (new \ReflectionProperty(RuntimeBlockStateRegistry::class, "instance"))->getValue() !== null;
	}

	/** @return array<string, array{name: string, textures: array{up: string, down: string, side: string}, hardness: float, tool: string, light: int, blast: float}> */
	public static function definitions() : array{
		if(self::$definitions === null){
			//worker threads: the main thread has put the definitions into the environment
			$raw = getenv(self::ENV);
			$decoded = is_string($raw) ? json_decode($raw, true) : null;
			self::$definitions = is_array($decoded) ? $decoded : [];
		}
		return self::$definitions;
	}

	/** @return array<string, Block> identifier => block */
	public static function getAll() : array{
		if(self::$blocks === null){
			self::$blocks = [];
			$index = 0;
			foreach(self::definitions() as $identifier => $definition){
				$tool = match($definition["tool"]){
					"pickaxe" => BlockToolType::PICKAXE,
					"axe" => BlockToolType::AXE,
					"shovel" => BlockToolType::SHOVEL,
					"hoe" => BlockToolType::HOE,
					"shears" => BlockToolType::SHEARS,
					"sword" => BlockToolType::SWORD,
					default => BlockToolType::NONE,
				};
				self::$blocks[$identifier] = new CustomBlock(
					new BlockIdentifier(self::BASE_TYPE_ID + $index++),
					$definition["name"],
					new BlockTypeInfo(new BlockBreakInfo($definition["hardness"], $tool, 0, $definition["blast"])),
					$definition["light"]
				);
			}
		}
		return self::$blocks;
	}

	/** RuntimeBlockStateRegistry constructor (every thread). */
	public static function registerBlocks(RuntimeBlockStateRegistry $registry) : void{
		foreach(self::getAll() as $block){
			$registry->register($block);
		}
	}

	/** GlobalBlockStateHandlers (every thread): saving, loading and network names. */
	public static function registerStates(\pocketmine\data\bedrock\block\convert\BlockSerializerDeserializerRegistrar $registrar) : void{
		foreach(self::getAll() as $identifier => $block){
			$registrar->mapSimple($block, $identifier);
		}
	}

	/** StringToItemParser (every thread): /give, /setblock, /fill. */
	public static function registerAliases(StringToItemParser $parser) : void{
		foreach(self::getAll() as $identifier => $block){
			$parser->registerBlock($identifier, fn() => clone $block);
		}
	}

	/**
	 * TypeConverter (every thread): the network block palette with the custom blocks sorted in like the client
	 * sorts them (by the FNV-1a 64 hash of the block name, states of a block kept together).
	 */
	public static function extendDictionary(BlockStateDictionary $dictionary) : BlockStateDictionary{
		if(self::definitions() === []){
			return $dictionary;
		}
		$groups = [];
		foreach($dictionary->getStates() as $state){
			$groups[$state->getStateName()][] = $state;
		}
		foreach(self::definitions() as $identifier => $_){
			$groups[$identifier] = [new BlockStateDictionaryEntry($identifier, [], 0)];
		}
		$names = array_keys($groups);
		usort($names, fn(string $a, string $b) => strcmp(hash("fnv164", $a), hash("fnv164", $b)));
		$states = [];
		foreach($names as $name){
			foreach($groups[$name] as $state){
				$states[] = $state;
			}
		}
		return new BlockStateDictionary($states);
	}

	/** @return list<BlockPaletteEntry> definitions for the StartGame packet */
	public static function paletteEntries() : array{
		$entries = [];
		$index = 0;
		foreach(self::definitions() as $identifier => $definition){
			$materials = CompoundTag::create();
			foreach(["up", "down", "side"] as $face){
				$material = CompoundTag::create()
					->setString("texture", self::textureKey($identifier, $face, $definition))
					->setString("render_method", "opaque")
					->setByte("face_dimming", 1)
					->setByte("ambient_occlusion", 1);
				$materials->setTag($face === "side" ? "*" : $face, $material);
			}
			$components = CompoundTag::create()
				->setTag("minecraft:material_instances", CompoundTag::create()
					->setTag("mappings", CompoundTag::create())
					->setTag("materials", $materials))
				->setTag("minecraft:geometry", CompoundTag::create()
					->setString("identifier", "minecraft:geometry.full_block")
					->setString("culling", "")
					->setTag("bone_visibility", CompoundTag::create()))
				->setTag("minecraft:light_emission", CompoundTag::create()->setByte("emission", $definition["light"]))
				->setTag("minecraft:light_dampening", CompoundTag::create()->setByte("lightLevel", 15))
				->setTag("minecraft:destructible_by_mining", CompoundTag::create()->setFloat("value", $definition["hardness"]))
				->setTag("minecraft:destructible_by_explosion", CompoundTag::create()->setFloat("explosion_resistance", $definition["blast"]))
				->setTag("minecraft:display_name", CompoundTag::create()->setString("value", $definition["name"]));
			$nbt = CompoundTag::create()
				->setTag("components", $components)
				->setTag("menu_category", CompoundTag::create()->setString("category", "construction")->setString("group", "")->setByte("is_hidden_in_commands", 0))
				->setInt("molangVersion", 1)
				->setTag("properties", new ListTag([], \pocketmine\nbt\NBT::TAG_Compound))
				->setTag("vanilla_block_data", CompoundTag::create()->setInt("block_id", self::BASE_NUMERIC_ID + $index++));
			$entries[] = new BlockPaletteEntry($identifier, new CacheableNbt($nbt));
		}
		return $entries;
	}

	/** @return list<ItemTypeEntry> the block items (negative ids like all block items above 255) */
	public static function itemEntries() : array{
		$entries = [];
		$index = 0;
		foreach(self::definitions() as $identifier => $_){
			$entries[] = new ItemTypeEntry($identifier, 255 - (self::BASE_NUMERIC_ID + $index++), false, 2, new CacheableNbt(CompoundTag::create()));
		}
		return $entries;
	}

	/** Main thread after start: creative inventory. */
	public static function addToCreative() : void{
		foreach(self::getAll() as $block){
			CreativeInventory::getInstance()->add($block->asItem(), CreativeCategory::CONSTRUCTION);
		}
	}

	/** @param array{textures: array{up: string, down: string, side: string}} $definition */
	public static function textureKey(string $identifier, string $face, array $definition) : string{
		//faces with the same picture share one texture
		return str_replace(":", "_", $identifier) . "_" . substr(hash("crc32b", $definition["textures"][$face]), 0, 6);
	}

	/**
	 * Files for the resource pack built by {@link \pocketmine\item\custom\CustomItems}.
	 * @return array<string, string> path in the pack => contents
	 */
	public static function packFiles() : array{
		$files = [];
		$textureData = [];
		$lang = "";
		foreach(self::definitions() as $identifier => $definition){
			foreach(["up", "down", "side"] as $face){
				$key = self::textureKey($identifier, $face, $definition);
				$files["textures/blocks/" . $key . ".png"] = (string) file_get_contents($definition["textures"][$face]);
				$textureData[$key] = ["textures" => "textures/blocks/" . $key];
			}
			$lang .= "tile." . $identifier . ".name=" . $definition["name"] . "\n";
		}
		if($files !== []){
			$files["textures/terrain_texture.json"] = (string) json_encode(["resource_pack_name" => "betterpmmp_custom", "texture_name" => "atlas.terrain", "padding" => 8, "num_mip_levels" => 4, "texture_data" => $textureData], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			$files["texts/blocks.lang"] = $lang;
		}
		return $files;
	}

	private const EXAMPLE = <<<'YAML'
# BetterPMMP custom blocks
#
# Every entry adds a full block. Put its texture (PNG, square, e.g. 16x16) into the folder "custom_blocks".
# The textures are sent to Bedrock players as a resource pack automatically. Restart the server after a change.
# Place a block with: /give @s myserver:ruby_block  or  /setblock ~ ~ ~ myserver:ruby_block
#
# Settings:
#   name               shown name
#   texture            file in custom_blocks/ for all sides (default: <name after the colon>.png)
#   texture-top / texture-bottom / texture-side   different pictures for these sides
#   hardness           like vanilla (stone 1.5, dirt 0.5, obsidian 50; default 1.5)
#   tool               none, pickaxe, axe, shovel, hoe, shears or sword (breaks faster with it)
#   light              0 to 15
#   blast-resistance   default hardness x 5
#
# Never remove or rename a block that is already placed in a world: it would become an unknown block.
#
# Example (remove the # in front of the lines to use it):
#
# blocks:
#   myserver:ruby_block:
#     name: "Ruby Block"
#     texture: ruby_block.png
#     hardness: 5
#     tool: pickaxe
#   myserver:crystal_lamp:
#     name: "Crystal Lamp"
#     texture: crystal_lamp.png
#     light: 15

blocks: {}
YAML;
}
