<?php

/*
 * BetterPMMP - vanilla game rules (/gamerule), stored in level.dat like the Bedrock Dedicated Server.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\GameRulesChangedPacket;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\GameRule;
use pocketmine\network\mcpe\protocol\types\IntGameRule;
use pocketmine\world\format\io\data\BaseNbtWorldData;
use function is_bool;
use function spl_object_id;
use function strtolower;

/**
 * The Bedrock game rules of a world. They are kept in the world's level.dat under the same lowercase keys
 * the official server uses (keepinventory, dodaylightcycle, randomtickspeed ...), so a world copied to or
 * from a vanilla server keeps them. Rules the client needs (coordinates, day cycle, immediate respawn ...)
 * are sent to the players of the world whenever they change.
 */
final class GameRules{

	/** key => [name shown in /gamerule, default] (bool or int) */
	public const RULES = [
		"commandblockoutput" => ["commandBlockOutput", true],
		"commandblocksenabled" => ["commandBlocksEnabled", true],
		"dodaylightcycle" => ["doDaylightCycle", true],
		"doentitydrops" => ["doEntityDrops", true],
		"dofiretick" => ["doFireTick", true],
		"doimmediaterespawn" => ["doImmediateRespawn", false],
		"doinsomnia" => ["doInsomnia", true],
		"dolimitedcrafting" => ["doLimitedCrafting", false],
		"domobloot" => ["doMobLoot", true],
		"domobspawning" => ["doMobSpawning", true],
		"dotiledrops" => ["doTileDrops", true],
		"doweathercycle" => ["doWeatherCycle", true],
		"drowningdamage" => ["drowningDamage", true],
		"falldamage" => ["fallDamage", true],
		"firedamage" => ["fireDamage", true],
		"freezedamage" => ["freezeDamage", true],
		"functioncommandlimit" => ["functionCommandLimit", 10000],
		"keepinventory" => ["keepInventory", false],
		"locatorbar" => ["locatorBar", true],
		"maxcommandchainlength" => ["maxCommandChainLength", 65535],
		"mobgriefing" => ["mobGriefing", true],
		"naturalregeneration" => ["naturalRegeneration", true],
		"playerssleepingpercentage" => ["playersSleepingPercentage", 100],
		"projectilescanbreakblocks" => ["projectilesCanBreakBlocks", true],
		"pvp" => ["pvp", true],
		"randomtickspeed" => ["randomTickSpeed", 1],
		"recipesunlock" => ["recipesUnlock", true],
		"respawnblocksexplode" => ["respawnBlocksExplode", true],
		"sendcommandfeedback" => ["sendCommandFeedback", true],
		"showbordereffect" => ["showBorderEffect", true],
		"showcoordinates" => ["showCoordinates", false],
		"showdaysplayed" => ["showDaysPlayed", false],
		"showdeathmessages" => ["showDeathMessages", true],
		"showrecipemessages" => ["showRecipeMessages", true],
		"showtags" => ["showTags", true],
		"spawnradius" => ["spawnRadius", 5],
		"tntexplodes" => ["tntExplodes", true],
		"tntexplosiondropdecay" => ["tntExplosionDropDecay", false],
	];

	/**
	 * Rules the server handles itself; the client gets a fixed value so it does not do the same thing twice
	 * (natural regeneration) or show something the server does not support (locator bar).
	 */
	private const CLIENT_OVERRIDES = [
		"naturalregeneration" => false,
		"locatorbar" => false,
	];

	/** @var array<int, array<string, bool|int>> world object id => cached values */
	private static array $cache = [];

	public static function key(string $name) : ?string{
		$key = strtolower($name);
		return isset(self::RULES[$key]) ? $key : null;
	}

	public static function get(World $world, string $key) : bool|int{
		$id = spl_object_id($world);
		if(isset(self::$cache[$id][$key])){
			return self::$cache[$id][$key];
		}
		$default = self::RULES[$key][1] ?? false;
		$value = $default;
		$data = $world->getProvider()->getWorldData();
		if($data instanceof BaseNbtWorldData){
			$tag = $data->getCompoundTag()->getTag($key);
			if($tag instanceof ByteTag || $tag instanceof IntTag){
				$value = is_bool($default) ? $tag->getValue() !== 0 : $tag->getValue();
			}
		}
		return self::$cache[$id][$key] = $value;
	}

	public static function bool(World $world, string $key) : bool{
		return (bool) self::get($world, $key);
	}

	public static function int(World $world, string $key) : int{
		return (int) self::get($world, $key);
	}

	public static function set(World $world, string $key, bool|int $value) : void{
		$default = self::RULES[$key][1];
		$value = is_bool($default) ? (bool) $value : (int) $value;
		self::$cache[spl_object_id($world)][$key] = $value;
		$data = $world->getProvider()->getWorldData();
		if($data instanceof BaseNbtWorldData){
			if(is_bool($value)){
				$data->getCompoundTag()->setByte($key, $value ? 1 : 0);
			}else{
				$data->getCompoundTag()->setInt($key, $value);
			}
		}
		if($key === "dodaylightcycle"){
			$value ? $world->startTime() : $world->stopTime();
		}
		$packet = GameRulesChangedPacket::create([$key => self::networkRule($key, $value)]);
		foreach($world->getPlayers() as $player){
			$player->getNetworkSession()->sendDataPacket($packet);
		}
	}

	/** Called when a world is loaded: applies the rules the world itself acts on. */
	public static function apply(World $world) : void{
		unset(self::$cache[spl_object_id($world)]);
		if(!self::bool($world, "dodaylightcycle")){
			$world->stopTime();
		}
	}

	public static function forget(World $world) : void{
		unset(self::$cache[spl_object_id($world)]);
	}

	/** @return array<string, GameRule> all rules for StartGame / GameRulesChanged */
	public static function network(World $world) : array{
		$rules = [];
		foreach(self::RULES as $key => [$name, $default]){
			$rules[$key] = self::networkRule($key, self::get($world, $key)); //the client takes the lowercase names
		}
		return $rules;
	}

	private static function networkRule(string $key, bool|int $value) : GameRule{
		if(isset(self::CLIENT_OVERRIDES[$key])){
			return new BoolGameRule(self::CLIENT_OVERRIDES[$key], false);
		}
		return is_bool($value) ? new BoolGameRule($value, false) : new IntGameRule($value, false);
	}

	public static function sendTo(\pocketmine\player\Player $player) : void{
		$player->getNetworkSession()->sendDataPacket(GameRulesChangedPacket::create(self::network($player->getWorld())));
	}
}
