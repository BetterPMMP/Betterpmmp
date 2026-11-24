<?php

/*
 * BetterPMMP - custom entities from custom_entities.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\custom;

use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\utils\Config;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getimagesizefromstring;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function max;
use function mkdir;
use function preg_match;
use function round;
use function str_replace;
use function str_starts_with;
use function strpos;
use function substr;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Lets server owners add creatures without a plugin:
 *
 *   custom_entities.yml                  the list (created with an example on first start)
 *   custom_entities/<texture>.png        the skin
 *   custom_entities/<model>.geo.json     optional Blockbench model (Bedrock entity); without one the entity is a box
 *
 * Passive creatures wander and flee, hostile ones hunt players and hit them like a zombie. They are summoned
 * with /summon and saved with the world. The client gets the model and skin through the custom resource pack.
 */
final class CustomEntities{
	private const BASE_RUNTIME_ID = 5000;

	/** @var array<string, CustomEntityType> */
	private static array $types = [];
	/** @var array<string, array{biomes: list<string>, dimension: string, group: array{int, int}, max: int, chance: float, time: string}> */
	private static array $spawning = [];
	/** @var array<int, int> world id => calls of tickSpawning() */
	private static array $spawnCalls = [];
	/** @var array<string, array{texture: string, geometry: ?string, animationFile: ?string, animations: array<string, string>}> identifier => files for the pack */
	private static array $files = [];

	/** @return array<string, CustomEntityType> */
	public static function getTypes() : array{
		return self::$types;
	}

	public static function load(string $dataPath, \Logger $logger) : void{
		$file = Path::join($dataPath, "custom_entities.yml");
		if(!file_exists($file)){
			@file_put_contents($file, self::EXAMPLE);
		}
		$dir = Path::join($dataPath, "custom_entities");
		if(!is_dir($dir)){
			@mkdir($dir);
		}
		$entries = (new Config($file, Config::YAML, ["entities" => []]))->get("entities", []);
		foreach(is_array($entries) ? $entries : [] as $identifier => $data){
			$identifier = (string) $identifier;
			if(!is_array($data)){
				continue;
			}
			if(preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $identifier) !== 1 || str_starts_with($identifier, "minecraft:")){
				$logger->warning("[Custom entities] \"$identifier\" is not a valid id (use namespace:name with a-z, 0-9 and _, not minecraft:)");
				continue;
			}
			$short = substr($identifier, (int) strpos($identifier, ":") + 1);
			$texture = Path::join($dir, (string) ($data["texture"] ?? $short . ".png"));
			if(!is_file($texture) || substr((string) file_get_contents($texture, length: 8), 0, 8) !== "\x89PNG\r\n\x1a\n"){
				$logger->warning("[Custom entities] $identifier: texture $texture is missing or not a PNG file");
				continue;
			}
			$geometry = null;
			if(isset($data["model"])){
				$geometry = Path::join($dir, (string) $data["model"]);
				if(!is_file($geometry) || self::geometryIdentifier((string) file_get_contents($geometry)) === null){
					$logger->warning("[Custom entities] $identifier: model $geometry is missing or not a Bedrock geometry (.geo.json from Blockbench)");
					continue;
				}
			}
			$animationFile = null;
			$animations = [];
			if(isset($data["animation-file"])){
				$animationFile = Path::join($dir, (string) $data["animation-file"]);
				$known = is_file($animationFile) ? json_decode((string) file_get_contents($animationFile), true) : null;
				if(!is_array($known) || !is_array($known["animations"] ?? null)){
					$logger->warning("[Custom entities] $identifier: animation file $animationFile is missing or not a Bedrock animation file (.animation.json)");
					$animationFile = null;
				}else{
					foreach(["idle", "walk", "attack"] as $state){
						$name = $data["animations"][$state] ?? null;
						if(is_string($name) && isset($known["animations"][$name])){
							$animations[$state] = $name;
						}elseif($name !== null){
							$logger->warning("[Custom entities] $identifier: animation \"$name\" is not in $animationFile");
						}
					}
				}
			}
			$spawn = is_array($data["spawn"] ?? null) ? $data["spawn"] : null;
			if($spawn !== null){
				self::$spawning[$identifier] = [
					"biomes" => array_map(fn($b) => strtolower((string) $b), is_array($spawn["biomes"] ?? null) ? $spawn["biomes"] : []),
					"dimension" => strtolower((string) ($spawn["dimension"] ?? "overworld")),
					"group" => [max(1, (int) ($spawn["group"][0] ?? 1)), max(1, (int) ($spawn["group"][1] ?? $spawn["group"][0] ?? 1))],
					"max" => max(1, (int) ($spawn["max-nearby"] ?? 4)),
					"chance" => max(0.0, min(1.0, (float) ($spawn["chance"] ?? 0.1))),
					"time" => strtolower((string) ($spawn["time"] ?? "any")),
				];
			}
			$drops = [];
			foreach(is_array($data["drops"] ?? null) ? $data["drops"] : [] as $item => $amount){
				[$min, $max] = is_array($amount) ? [(int) ($amount[0] ?? 1), (int) ($amount[1] ?? $amount[0] ?? 1)] : [(int) $amount, (int) $amount];
				$drops[] = [(string) $item, max(0, $min), max($min, $max)];
			}
			$type = new CustomEntityType(
				$identifier,
				(string) ($data["name"] ?? $identifier),
				($data["behaviour"] ?? $data["behavior"] ?? "passive") === "hostile",
				max(1, (int) ($data["health"] ?? 20)),
				max(0.0, (float) ($data["speed"] ?? 0.25)),
				max(0.0, (float) ($data["damage"] ?? 3)),
				max(1.0, (float) ($data["follow-range"] ?? 16)),
				max(0.1, (float) ($data["width"] ?? 0.6)),
				max(0.1, (float) ($data["height"] ?? 1.8)),
				max(0, (int) ($data["experience"] ?? 3)),
				$drops,
				(bool) ($data["fireproof"] ?? false)
			);
			$class = $type->hostile ? CustomHostileEntity::class : CustomPassiveEntity::class;
			EntityFactory::getInstance()->register($class, fn(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity => new $class(EntityDataHelper::parseLocation($nbt, $world), $nbt, $type), [$identifier]);
			self::$types[$identifier] = $type;
			self::$files[$identifier] = ["texture" => $texture, "geometry" => $geometry, "animationFile" => $animationFile, "animations" => $animations];
		}
		if(self::$types !== []){
			$logger->info("[Custom entities] " . count(self::$types) . " custom entit" . (count(self::$types) === 1 ? "y" : "ies") . " loaded");
		}
	}

	/**
	 * Natural spawning of custom entities that have a "spawn" section, every 10 seconds around a random player:
	 * in the given biomes and dimension, on solid ground with room to stand, 24-48 blocks away, at most
	 * max-nearby of the kind within 64 blocks. Hostile ones only where it is dark (block light 0, night or under a roof).
	 */
	public static function tickSpawning(World $world) : void{
		//called every 10 ticks per world: try every 20th call (10 seconds)
		if(self::$spawning === [] || (self::$spawnCalls[$world->getId()] = (self::$spawnCalls[$world->getId()] ?? 0) + 1) % 20 !== 0){
			return;
		}
		if(!\pocketmine\world\GameRules::bool($world, "domobspawning")){
			return;
		}
		$players = $world->getPlayers();
		if($players === []){
			return;
		}
		$player = $players[array_rand($players)];
		$dimension = match($world->getDimensionId()){
			\pocketmine\data\bedrock\DimensionIds::NETHER => "nether",
			\pocketmine\data\bedrock\DimensionIds::THE_END => "the_end",
			default => "overworld",
		};
		foreach(self::$spawning as $identifier => $rule){
			$type = self::$types[$identifier];
			if($rule["dimension"] !== $dimension || mt_rand() / mt_getrandmax() > $rule["chance"] || ($type->hostile && $world->getDifficulty() === World::DIFFICULTY_PEACEFUL)){
				continue;
			}
			$nearby = 0;
			foreach($world->getEntities() as $entity){
				if($entity instanceof CustomPassiveEntity || $entity instanceof CustomHostileEntity){
					if($entity->getIdentifier() === $identifier && $entity->getPosition()->distanceSquared($player->getPosition()) < 64 * 64){
						++$nearby;
					}
				}
			}
			if($nearby >= $rule["max"]){
				continue;
			}
			$angle = mt_rand() / mt_getrandmax() * M_PI * 2;
			$distance = mt_rand(24, 48);
			$x = (int) ($player->getPosition()->x + cos($angle) * $distance);
			$z = (int) ($player->getPosition()->z + sin($angle) * $distance);
			if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
				continue;
			}
			$y = $world->getHighestBlockAt($x, $z);
			if($y === null || !$world->getBlockAt($x, $y, $z)->isSolid() || $world->getBlockAt($x, $y + 1, $z)->isSolid() || $world->getBlockAt($x, $y + 2, $z)->isSolid()){
				continue;
			}
			if($rule["biomes"] !== []){
				$biome = \pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap::getInstance()->legacyToString($world->getBiomeId($x, $y + 1, $z)) ?? "";
				if(!in_array(str_replace("minecraft:", "", $biome), array_map(fn(string $b) => str_replace("minecraft:", "", $b), $rule["biomes"]), true)){
					continue;
				}
			}
			$time = $world->getTimeOfDay();
			$night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
			if(($rule["time"] === "night" && !$night) || ($rule["time"] === "day" && $night)){
				continue;
			}
			if($type->hostile && ($world->getBlockLightAt($x, $y + 1, $z) > 0 || ($world->getRealBlockSkyLightAt($x, $y + 1, $z) > 7 && !$night))){
				continue;
			}
			$count = mt_rand($rule["group"][0], max($rule["group"][0], $rule["group"][1]));
			for($i = 0; $i < $count && $nearby + $i < $rule["max"]; ++$i){
				$class = $type->hostile ? CustomHostileEntity::class : CustomPassiveEntity::class;
				$location = \pocketmine\entity\Location::fromObject(new \pocketmine\math\Vector3($x + 0.5 + mt_rand(-2, 2), $y + 1, $z + 0.5 + mt_rand(-2, 2)), $world, mt_rand(0, 359), 0);
				$entity = new $class($location, null, $type);
				$entity->markNaturalSpawned();
				$entity->spawnToAll();
			}
		}
	}

	/** The identifier list for the client with the custom entities added (they can be summoned with commands). */
	public static function actorIdentifiers(AvailableActorIdentifiersPacket $vanilla) : AvailableActorIdentifiersPacket{
		if(self::$types === []){
			return $vanilla;
		}
		$root = clone $vanilla->identifiers->getRoot();
		if(!$root instanceof CompoundTag){
			return $vanilla;
		}
		$list = $root->getListTag("idlist") ?? new ListTag([], \pocketmine\nbt\NBT::TAG_Compound);
		$list = clone $list;
		$index = 0;
		foreach(self::$types as $identifier => $_){
			$list->push(CompoundTag::create()
				->setString("bid", "")
				->setByte("hasspawnegg", 0)
				->setString("id", $identifier)
				->setInt("rid", self::BASE_RUNTIME_ID + $index++)
				->setByte("summonable", 1)
				->setByte("experimental", 0));
		}
		$root->setTag("idlist", $list);
		return AvailableActorIdentifiersPacket::create(new CacheableNbt($root));
	}

	/** The identifier of the first model in a .geo.json, or null. */
	private static function geometryIdentifier(string $json) : ?string{
		$data = json_decode($json, true);
		$id = is_array($data) ? ($data["minecraft:geometry"][0]["description"]["identifier"] ?? null) : null;
		return is_string($id) && str_starts_with($id, "geometry.") ? $id : null;
	}

	/**
	 * Client entity definitions, models and skins for the resource pack.
	 * @return array<string, string> path in the pack => contents
	 */
	public static function packFiles() : array{
		$files = [];
		foreach(self::$files as $identifier => $source){
			$type = self::$types[$identifier];
			$key = str_replace(":", "_", $identifier);
			$skin = (string) file_get_contents($source["texture"]);
			$files["textures/entity/betterpmmp/" . $key . ".png"] = $skin;
			if($source["geometry"] !== null){
				$geometryJson = (string) file_get_contents($source["geometry"]);
				$geometry = (string) self::geometryIdentifier($geometryJson);
				$files["models/entity/" . $key . ".geo.json"] = $geometryJson;
			}else{
				$geometry = "geometry.betterpmmp." . $key;
				$files["models/entity/" . $key . ".geo.json"] = self::boxGeometry($geometry, $type, $skin);
			}
			$description = [
				"identifier" => $identifier,
				"materials" => ["default" => "entity_alphatest"],
				"textures" => ["default" => "textures/entity/betterpmmp/" . $key],
				"geometry" => ["default" => $geometry],
				"render_controllers" => ["controller.render.default"],
			];
			if($source["animationFile"] !== null && $source["animations"] !== []){
				$files["animations/" . $key . ".animation.json"] = (string) file_get_contents($source["animationFile"]);
				$description["animations"] = $source["animations"];
				//like vanilla mobs: idle always, walking blended by the movement speed, attacking while it swings
				$animate = [];
				if(isset($source["animations"]["idle"])){
					$animate[] = "idle";
				}
				if(isset($source["animations"]["walk"])){
					$animate[] = ["walk" => "query.modified_move_speed"];
				}
				if(isset($source["animations"]["attack"])){
					$animate[] = ["attack" => "variable.attack_time > 0"];
				}
				$description["scripts"] = ["pre_animation" => ["variable.attack_time = query.attack_time;"], "animate" => $animate];
			}
			$files["entity/" . $key . ".entity.json"] = (string) json_encode([
				"format_version" => "1.10.0",
				"minecraft:client_entity" => ["description" => $description],
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			$files["texts/entities.lang"] = ($files["texts/entities.lang"] ?? "") . "entity." . $identifier . ".name=" . $type->name . "\n";
		}
		return $files;
	}

	/** A plain box of the entity's size, the skin laid out like a Blockbench box UV. */
	private static function boxGeometry(string $identifier, CustomEntityType $type, string $skin) : string{
		$size = @getimagesizefromstring($skin);
		$textureWidth = is_array($size) ? $size[0] : 64;
		$textureHeight = is_array($size) ? $size[1] : 64;
		$w = round($type->width * 16, 2);
		$h = round($type->height * 16, 2);
		return (string) json_encode([
			"format_version" => "1.12.0",
			"minecraft:geometry" => [[
				"description" => ["identifier" => $identifier, "texture_width" => $textureWidth, "texture_height" => $textureHeight, "visible_bounds_width" => max(1, $type->width * 2), "visible_bounds_height" => max(1, $type->height + 1), "visible_bounds_offset" => [0, $type->height / 2, 0]],
				"bones" => [["name" => "body", "pivot" => [0, 0, 0], "cubes" => [["origin" => [-$w / 2, 0, -$w / 2], "size" => [$w, $h, $w], "uv" => [0, 0]]]]],
			]],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	private const EXAMPLE = <<<'YAML'
# BetterPMMP custom entities
#
# Every entry adds a creature. Put its skin (PNG) into the folder "custom_entities". A model made with
# Blockbench ("Bedrock Entity", exported as .geo.json) can go there too; without one the creature is a box
# of its size with the skin wrapped around it. Model and skin reach Bedrock players as a resource pack.
# Without a model the skin is wrapped around the box like Blockbench's box UV: it has to be at least
# (4 x width x 16) pixels wide and (width x 16 + height x 16) pixels high, e.g. 64 x 48 for 0.8 x 2 blocks.
# Restart the server after a change. Spawn one with: /summon myserver:goblin
#
# Settings:
#   name           shown name
#   behaviour      passive (wanders, flees when hit) or hostile (hunts and hits players like a zombie)
#   texture        skin in custom_entities/ (default: <name after the colon>.png)
#   model          Blockbench model in custom_entities/ (optional)
#   health         default 20
#   speed          movement attribute like vanilla (zombie 0.23, cow 0.25; default 0.25)
#   damage         hit damage on Normal for hostile creatures (default 3)
#   follow-range   how far hostile creatures notice players (default 16)
#   width, height  hit box in blocks (default 0.6 x 1.8)
#   experience     dropped experience (default 3)
#   drops          item: amount or item: [min, max]
#   fireproof      true = no fire or lava damage
#   animation-file Blockbench animations in custom_entities/ (.animation.json), with
#   animations     idle, walk, attack: the animation names in that file (walk plays while moving,
#                  attack while hitting)
#   spawn          natural spawning: biomes (list, e.g. [plains, forest]; empty = all), dimension
#                  (overworld, nether, the_end), group [min, max], max-nearby (within 64 blocks),
#                  chance (0 to 1 per try, every 10 seconds), time (day, night or any).
#                  Hostile ones only spawn in the dark and never on Peaceful
#
# Never remove or rename an entity that already exists in a world: it would be deleted when its chunk loads.
#
# Example (remove the # in front of the lines to use it):
#
# entities:
#   myserver:goblin:
#     name: "Goblin"
#     behaviour: hostile
#     texture: goblin.png
#     model: goblin.geo.json
#     health: 16
#     speed: 0.28
#     damage: 4
#     drops:
#       gold_nugget: [1, 3]
#     animation-file: goblin.animation.json
#     animations:
#       walk: animation.goblin.walk
#       attack: animation.goblin.attack
#     spawn:
#       biomes: [plains, forest]
#       group: [1, 3]
#       time: night
#   myserver:snail:
#     name: "Snail"
#     texture: snail.png
#     width: 0.5
#     height: 0.4
#     speed: 0.1

entities: {}
YAML;
}
