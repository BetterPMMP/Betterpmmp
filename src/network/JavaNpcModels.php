<?php

/*
 * BetterPMMP - 3D NPC models for Java Edition players (Java bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\player\Player;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function count;
use function crc32;
use function file_exists;
use function glob;
use function gzcompress;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function pack;
use function sha1;
use function sqrt;
use function str_repeat;
use function strlen;
use function substr;
use function unlink;
use const JSON_UNESCAPED_SLASHES;

/**
 * NPCs (non-player Humans) with their own 3D model (a skin with custom geometry)
 * cannot be shown by a Java client: Java player models have a fixed shape.
 * ViaBedrock can, however, rebuild custom Bedrock entities from a resource pack.
 *
 * For Java players BetterPMMP therefore builds a small extra resource pack with a
 * client entity per NPC model (geometry, texture and render controller taken from
 * the skin) and sends those NPCs as that custom entity instead of as a player.
 * Clicking them works as before; Bedrock players are not affected.
 */
final class JavaNpcModels{

	public const IDENTIFIER_PREFIX = "betterpmmp:npc_";
	private const DEFAULT_GEOMETRIES = ["", "geometry.humanoid.custom", "geometry.humanoid.customSlim", "geometry.humanoid"];

	/** @var array<string, true> identifiers contained in the current pack */
	private static array $identifiers = [];

	public static function hasCustomModel(Skin $skin) : bool{
		return !in_array($skin->getGeometryName(), self::DEFAULT_GEOMETRIES, true) && $skin->getGeometryData() !== "";
	}

	public static function identifier(Skin $skin) : string{
		return self::IDENTIFIER_PREFIX . substr(sha1($skin->getGeometryName() . "\0" . $skin->getGeometryData() . "\0" . $skin->getSkinData()), 0, 12);
	}

	/** @return list<string> identifiers the current Java pack provides */
	public static function getIdentifiers() : array{
		return array_keys(self::$identifiers);
	}

	/** Custom entity identifier a Java player sees this entity as, or null to send it normally. */
	public static function identifierFor(int $runtimeId) : ?string{
		if(self::$identifiers === []){
			return null;
		}
		$entity = Server::getInstance()->getWorldManager()->findEntity($runtimeId);
		if(!$entity instanceof Human || $entity instanceof Player || !self::hasCustomModel($entity->getSkin())){
			return null;
		}
		$identifier = self::identifier($entity->getSkin());
		return isset(self::$identifiers[$identifier]) ? $identifier : null;
	}

	/**
	 * Builds the extra pack for the NPC models that exist right now (null if there are none).
	 */
	public static function buildPack(Server $server) : ?ResourcePack{
		/** @var array<string, Skin> $skins */
		$skins = [];
		foreach($server->getWorldManager()->getWorlds() as $world){
			foreach($world->getEntities() as $entity){
				if($entity instanceof Human && !$entity instanceof Player && self::hasCustomModel($entity->getSkin())){
					$skins[self::identifier($entity->getSkin())] = $entity->getSkin();
				}
			}
		}
		if($skins === []){
			self::$identifiers = [];
			return null;
		}
		ksort($skins);
		$dir = Path::join($server->getDataPath(), "java-bridge", "npc-packs");
		if(!is_dir($dir) && !@mkdir($dir, 0777, true)){
			return null;
		}
		$hash = substr(sha1(implode(",", array_keys($skins))), 0, 16);
		$zip = Path::join($dir, "npc-models-$hash.zip");
		if(!file_exists($zip)){
			foreach(glob(Path::join($dir, "npc-models-*.zip")) ?: [] as $old){
				@unlink($old);
			}
			$files = self::packFiles($skins, $hash);
			if($files === null || !self::writeZip($zip, $files)){
				return null;
			}
		}
		try{
			$pack = new ZippedResourcePack($zip);
		}catch(\Throwable $e){
			$server->getLogger()->warning("[Java-Bridge] NPC-Modell-Pack fehlerhaft: " . $e->getMessage());
			return null;
		}
		self::$identifiers = [];
		foreach(array_keys($skins) as $identifier){
			self::$identifiers[$identifier] = true;
		}
		return $pack;
	}

	/**
	 * @param array<string, Skin> $skins
	 * @return array<string, string>|null file name => contents
	 */
	private static function packFiles(array $skins, string $hash) : ?array{
		$uuid = static fn(string $seed) : string => self::uuidFrom(sha1("betterpmmp-npc-pack-$hash-$seed"));
		$files = [
			"manifest.json" => self::json([
				"format_version" => 2,
				"header" => [
					"name" => "BetterPMMP NPC-Modelle (Java)",
					"description" => "3D-Modelle der Server-NPCs für Java-Spieler",
					"uuid" => $uuid("header"),
					"version" => [1, 0, 0],
					"min_engine_version" => [1, 21, 0],
				],
				"modules" => [[
					"type" => "resources",
					"uuid" => $uuid("module"),
					"version" => [1, 0, 0],
				]],
			]),
			"render_controllers/betterpmmp_npc.render_controllers.json" => self::json([
				"format_version" => "1.8.0",
				"render_controllers" => [
					"controller.render.betterpmmp_npc" => [
						"geometry" => "Geometry.default",
						"materials" => [["*" => "Material.default"]],
						"textures" => ["Texture.default"],
					],
				],
			]),
		];
		foreach($skins as $identifier => $skin){
			$name = substr($identifier, strlen(self::IDENTIFIER_PREFIX));
			$geometryId = "geometry.betterpmmp.npc_$name";
			$geometry = self::renameGeometry($skin->getGeometryData(), $skin->getGeometryName(), $geometryId);
			$png = self::png($skin->getSkinData());
			if($geometry === null || $png === null){
				continue;
			}
			$files["models/entity/betterpmmp_npc_$name.geo.json"] = $geometry;
			$files["textures/entity/betterpmmp/npc_$name.png"] = $png;
			$files["entity/betterpmmp_npc_$name.entity.json"] = self::json([
				"format_version" => "1.10.0",
				"minecraft:client_entity" => [
					"description" => [
						"identifier" => $identifier,
						"materials" => ["default" => "entity_alphatest"],
						"textures" => ["default" => "textures/entity/betterpmmp/npc_$name"],
						"geometry" => ["default" => $geometryId],
						"render_controllers" => ["controller.render.betterpmmp_npc"],
					],
				],
			]);
		}
		return count($files) > 2 ? $files : null;
	}

	/** Takes the skin's geometry (format 1.12+ or the older format) and gives it a unique identifier. */
	private static function renameGeometry(string $json, string $geometryName, string $newIdentifier) : ?string{
		$data = json_decode($json, true);
		if(!is_array($data)){
			return null;
		}
		if(is_array($data["minecraft:geometry"] ?? null)){
			foreach($data["minecraft:geometry"] as $geometry){
				if(is_array($geometry) && ($geometry["description"]["identifier"] ?? null) === $geometryName){
					$geometry["description"]["identifier"] = $newIdentifier;
					return self::json(["format_version" => "1.12.0", "minecraft:geometry" => [$geometry]]);
				}
			}
			return null;
		}
		foreach($data as $key => $geometry){
			// old format: "geometry.name" or "geometry.name:geometry.parent" (parents are not supported)
			if($key === $geometryName && is_array($geometry)){
				return self::json(["format_version" => "1.8.0", $newIdentifier => $geometry]);
			}
		}
		return null;
	}

	/** Raw RGBA skin data (64x32, 64x64, 128x128 ...) as PNG. */
	private static function png(string $rgba) : ?string{
		$pixels = intdiv(strlen($rgba), 4);
		if($pixels === 64 * 32){
			[$width, $height] = [64, 32];
		}else{
			$width = $height = (int) sqrt($pixels);
			if($width * $height !== $pixels || $width === 0){
				return null;
			}
		}
		$raw = "";
		$rowLength = $width * 4;
		for($y = 0; $y < $height; ++$y){
			$raw .= "\0" . substr($rgba, $y * $rowLength, $rowLength);
		}
		$compressed = gzcompress($raw, 9);
		if($compressed === false){
			return null;
		}
		$chunk = static fn(string $type, string $data) : string => pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
		return "\x89PNG\r\n\x1a\n"
			. $chunk("IHDR", pack("NNCCCCC", $width, $height, 8, 6, 0, 0, 0))
			. $chunk("IDAT", $compressed)
			. $chunk("IEND", "");
	}

	/** @param array<string, string> $files */
	private static function writeZip(string $path, array $files) : bool{
		$zip = new \ZipArchive();
		if($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
			return false;
		}
		foreach($files as $name => $contents){
			$zip->addFromString($name, $contents);
		}
		return $zip->close();
	}

	/** @param mixed[] $data */
	private static function json(array $data) : string{
		return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
	}

	private static function uuidFrom(string $hex) : string{
		$hex = substr($hex . str_repeat("0", 32), 0, 32);
		$hex[12] = "4";
		$hex[16] = "8";
		return substr($hex, 0, 8) . "-" . substr($hex, 8, 4) . "-" . substr($hex, 12, 4) . "-" . substr($hex, 16, 4) . "-" . substr($hex, 20, 12);
	}
}
