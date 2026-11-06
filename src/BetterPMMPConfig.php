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
use function file_exists;
use function in_array;
use function is_array;
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
		if(!file_exists($file)){
			@copy(Path::join(\pocketmine\RESOURCE_PATH, "betterpmmp.yml"), $file);
		}
		try{
			$config = new Config($file, Config::YAML, []);
			self::$data = $config->getAll();
		}catch(\Throwable){
			self::$data = [];
		}
		self::$loaded = true;
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

	public static function pathfindingSecondsPerTick() : float{
		return max(1, self::int("mob-ai", "pathfinding-ms-per-tick", 6)) / 1000;
	}
}
