<?php

/*
 * BetterPMMP - server settings (betterpmmp.yml).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine;

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\utils\Config;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function copy;
use function str_starts_with;
use function str_replace;
use function rtrim;
use function preg_match;
use function implode;
use function file_put_contents;
use function file_get_contents;
use function explode;
use function count;
use function array_slice;
use function array_keys;
use function array_key_exists;
use function file_exists;
use function in_array;
use function is_array;
use function is_scalar;
use function max;
use function strtolower;

/**
 * Reads betterpmmp.yml from the server folder (created from the bundled
 * default on first start). Nether and End worlds always behave like vanilla.
 */
final class BetterPMMPConfig{

	/** @var array<string, mixed> */
	private static array $data = [];
	private static bool $loaded = false;
	private static string $defaultWorld = "world";

	public static function load(string $dataPath, string $defaultWorld) : void{
		self::$defaultWorld = $defaultWorld;
		$file = Path::join($dataPath, "betterpmmp.yml");
		$defaults = Path::join(\pocketmine\RESOURCE_PATH, "betterpmmp.yml");
		if(!file_exists($file)){
			@copy($defaults, $file);
		}
		try{
			$config = new Config($file, Config::YAML, []);
			self::$data = $config->getAll();
		}catch(\Throwable){
			self::$data = [];
		}
		self::$loaded = true;
		self::addMissingSections($file, $defaults);
	}

	/**
	 * Sections added in newer versions (e.g. java-bridge) are appended to an existing betterpmmp.yml, with their
	 * comments, so they show up for the owner. Existing settings are never touched.
	 */
	private static function addMissingSections(string $file, string $defaultsFile) : void{
		$default = @file_get_contents($defaultsFile);
		$current = @file_get_contents($file);
		if($default === false || $current === false || self::$data === []){
			return;
		}
		$lines = explode("\n", str_replace("\r\n", "\n", $default));
		$added = [];
		foreach($lines as $index => $line){
			if(preg_match('/^([a-z0-9-]+):/', $line, $m) !== 1 || array_key_exists($m[1], self::$data)){
				continue;
			}
			$start = $index;
			while($start > 0 && str_starts_with($lines[$start - 1], "#")){
				--$start;
			}
			$end = $index + 1;
			while($end < count($lines) && ($lines[$end] === "" || str_starts_with($lines[$end], " ") || str_starts_with($lines[$end], "\t"))){
				++$end;
			}
			$added[$m[1]] = rtrim(implode("\n", array_slice($lines, $start, $end - $start)));
		}
		if($added === []){
			return;
		}
		if(@file_put_contents($file, rtrim($current) . "\n\n" . implode("\n\n", $added) . "\n") === false){
			return;
		}
		try{
			self::$data = (new Config($file, Config::YAML, []))->getAll();
		}catch(\Throwable){
		}
		\GlobalLogger::get()->info("[BetterPMMP] betterpmmp.yml um neue Abschnitte ergänzt: " . implode(", ", array_keys($added)));
	}

	private static function get(string $section, string $key, mixed $default) : mixed{
		if(!self::$loaded){
			return $default;
		}
		$s = self::$data[$section] ?? null;
		return is_array($s) && isset($s[$key]) ? $s[$key] : $default;
	}

	private static function bool(string $section, string $key, bool $default) : bool{
		return (bool) self::get($section, $key, $default);
	}

	public static function int(string $section, string $key, int $default) : int{
		return (int) self::get($section, $key, $default);
	}

	/** Whether natural mobs may spawn in this world at all. */
	public static function mobSpawningEnabled(World $world) : bool{
		if($world->getDimensionId() !== DimensionIds::OVERWORLD){
			return true; // Nether and End are always vanilla
		}
		$name = strtolower($world->getFolderName());
		$disabled = self::get("mob-spawning", "disabled-worlds", []);
		if(is_array($disabled) && self::listContains($disabled, $name)){
			return false;
		}
		$worlds = self::get("mob-spawning", "worlds", ["*"]);
		if(!is_array($worlds)){
			return true;
		}
		return self::listContains($worlds, $name) || in_array("*", $worlds, true);
	}

	/** @param mixed[] $list */
	private static function listContains(array $list, string $name) : bool{
		foreach($list as $entry){
			$entry = strtolower((string) $entry);
			if($entry === $name || ($entry === "default" && $name === strtolower(self::$defaultWorld))){
				return true;
			}
		}
		return false;
	}

	/** A spawning category switch ("monsters", "animals", "ambient", "water", "village-residents", "pillagers", "wardens"). */
	public static function spawns(World $world, string $category) : bool{
		if($world->getDimensionId() !== DimensionIds::OVERWORLD){
			return true;
		}
		return self::bool("mob-spawning", $category, true);
	}

	/** A yes/no switch from betterpmmp.yml (missing keys use the default). */
	public static function flag(string $section, string $key, bool $default) : bool{
		return self::bool($section, $key, $default);
	}

	/** A text value from betterpmmp.yml (missing keys use the default). */
	public static function string(string $section, string $key, string $default) : string{
		$v = self::get($section, $key, $default);
		return is_scalar($v) ? (string) $v : $default;
	}

	public static function pathfindingSecondsPerTick() : float{
		return max(1, self::int("mob-ai", "pathfinding-ms-per-tick", 6)) / 1000;
	}
}
