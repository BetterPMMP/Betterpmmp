<?php

/*
 * BetterPMMP - vanilla target selectors (@a @p @r @s @e @n) for commands.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\utils;

use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;
use function array_slice;
use function count;
use function explode;
use function is_numeric;
use function shuffle;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * Resolves a command target like Bedrock does: a player name, or a selector with arguments,
 * for example <code>@e[type=zombie,r=10,c=3]</code> or <code>@a[m=survival,name=!Steve]</code>.
 *
 * Supported arguments: x, y, z, r, rm, dx, dy, dz, c, type (with !), name (with !), m (with !), l, lm.
 */
final class TargetSelector{

	public static function isSelector(string $target) : bool{
		return strlen($target) >= 2 && $target[0] === "@";
	}

	/**
	 * @return Entity[]
	 * @throws InvalidCommandSyntaxException on a malformed selector
	 */
	public static function select(CommandSender $sender, string $target) : array{
		$server = $sender->getServer();
		if(!self::isSelector($target)){
			$player = $server->getPlayerExact($target) ?? $server->getPlayerByPrefix($target);
			return $player === null ? [] : [$player];
		}
		$kind = strtolower($target[1]);
		$args = self::parseArguments(substr($target, 2));
		$origin = self::origin($sender, $server);

		if($kind === "s"){
			$self = $sender instanceof ExecuteContext ? $sender->executor : $sender;
			$candidates = $self instanceof Entity && !$self->isClosed() ? [$self] : [];
		}elseif($kind === "e" || $kind === "n"){
			$candidates = [];
			foreach($origin === null ? $server->getWorldManager()->getWorlds() : [$origin->getWorld()] as $world){
				foreach($world->getEntities() as $entity){
					if(!$entity->isClosed() && !$entity->isFlaggedForDespawn()){
						$candidates[] = $entity;
					}
				}
			}
		}elseif($kind === "a" || $kind === "p" || $kind === "r"){
			$candidates = [];
			foreach($server->getOnlinePlayers() as $player){
				if($player->isOnline() && ($kind === "a" || $player->isAlive())){
					$candidates[] = $player;
				}
			}
		}else{
			throw new InvalidCommandSyntaxException();
		}

		$center = $origin?->asVector3();
		if(isset($args["x"]) || isset($args["y"]) || isset($args["z"])){
			$base = $center ?? Vector3::zero();
			$center = new Vector3(
				self::coordinate($args["x"] ?? "~", $base->x),
				self::coordinate($args["y"] ?? "~", $base->y),
				self::coordinate($args["z"] ?? "~", $base->z)
			);
		}

		$result = [];
		foreach($candidates as $entity){
			if(self::matches($entity, $args, $center, $origin)){
				$result[] = $entity;
			}
		}

		$limit = isset($args["c"]) && is_numeric($args["c"]) ? (int) $args["c"] : null;
		if($kind === "r"){
			shuffle($result);
			$limit ??= 1;
		}elseif($center !== null && ($kind === "p" || $kind === "n" || $limit !== null)){
			usort($result, fn(Entity $a, Entity $b) => $a->getPosition()->distanceSquared($center) <=> $b->getPosition()->distanceSquared($center));
			if($limit !== null && $limit < 0){
				$result = array_reverse($result); //c=-N: the N farthest
				$limit = -$limit;
			}
		}
		if($kind === "p" || $kind === "n"){
			$limit ??= 1;
		}
		return $limit === null ? $result : array_slice($result, 0, $limit);
	}

	/** Players only (for commands that need players). @return Player[] */
	public static function selectPlayers(CommandSender $sender, string $target) : array{
		$players = [];
		foreach(self::select($sender, $target) as $entity){
			if($entity instanceof Player){
				$players[] = $entity;
			}
		}
		return $players;
	}

	private static function origin(CommandSender $sender, Server $server) : ?Position{
		if($sender instanceof ExecuteContext){
			return $sender->position->isValid() ? $sender->position : null;
		}
		if($sender instanceof Entity){
			return $sender->getPosition();
		}
		if(method_exists($sender, "getPosition")){
			$position = $sender->getPosition();
			if($position instanceof Position && $position->isValid()){
				return $position;
			}
		}
		return null;
	}

	private static function coordinate(string $value, float $base) : float{
		if(str_starts_with($value, "~")){
			$rest = substr($value, 1);
			return $base + ($rest === "" ? 0.0 : (float) $rest);
		}
		return (float) $value;
	}

	/**
	 * @return array<string, string>
	 * @throws InvalidCommandSyntaxException
	 */
	private static function parseArguments(string $raw) : array{
		$raw = trim($raw);
		if($raw === ""){
			return [];
		}
		if($raw[0] !== "[" || $raw[strlen($raw) - 1] !== "]"){
			throw new InvalidCommandSyntaxException();
		}
		$args = [];
		foreach(explode(",", substr($raw, 1, -1)) as $pair){
			$pair = trim($pair);
			if($pair === ""){
				continue;
			}
			$parts = explode("=", $pair, 2);
			if(count($parts) !== 2){
				throw new InvalidCommandSyntaxException();
			}
			$key = strtolower(trim($parts[0]));
			if($key === "tag"){
				$args["tag"] = isset($args["tag"]) ? $args["tag"] . "\0" . trim($parts[1]) : trim($parts[1]); //several tag= filters all apply
			}else{
				$args[$key] = trim($parts[1]);
			}
		}
		return $args;
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function matches(Entity $entity, array $args, ?Vector3 $center, ?Position $origin) : bool{
		$pos = $entity->getPosition();
		if($center !== null){
			$distance = $pos->distance($center);
			if(isset($args["r"]) && $distance > (float) $args["r"]){
				return false;
			}
			if(isset($args["rm"]) && $distance < (float) $args["rm"]){
				return false;
			}
			if(isset($args["dx"]) || isset($args["dy"]) || isset($args["dz"])){
				foreach(["x" => "dx", "y" => "dy", "z" => "dz"] as $axis => $key){
					$delta = (float) ($args[$key] ?? 0);
					$min = min($center->{$axis}, $center->{$axis} + $delta);
					$max = max($center->{$axis}, $center->{$axis} + $delta) + 1;
					if($pos->{$axis} < $min || $pos->{$axis} >= $max){
						return false;
					}
				}
			}
			if(($origin !== null && (isset($args["r"]) || isset($args["dx"]) || isset($args["dy"]) || isset($args["dz"]))) && $entity->getWorld() !== $origin->getWorld()){
				return false;
			}
		}
		if(isset($args["type"])){
			[$negate, $type] = self::negation($args["type"]);
			$id = $entity instanceof Player ? "minecraft:player" : (method_exists($entity, "getIdentifier") ? $entity->getIdentifier() : $entity::getNetworkTypeId());
			$wanted = str_contains($type, ":") ? strtolower($type) : "minecraft:" . strtolower($type);
			if(($id === $wanted) === $negate){
				return false;
			}
		}
		if(isset($args["name"])){
			[$negate, $name] = self::negation($args["name"]);
			$entityName = $entity instanceof Player ? $entity->getName() : ($entity->getNameTag() !== "" ? $entity->getNameTag() : ($entity instanceof Living ? $entity->getName() : ""));
			if((strtolower($entityName) === strtolower(trim($name, "\""))) === $negate){
				return false;
			}
		}
		if(isset($args["tag"])){
			foreach(explode("\0", $args["tag"]) as $filter){
				[$negate, $tag] = self::negation($filter);
				$has = $tag === "" ? $entity->getCommandTags() === [] : $entity->hasCommandTag($tag);
				if($has === $negate){
					return false; //tag=x needs x, tag=!x must not have x, tag= needs none, tag=! needs any
				}
			}
		}
		if(isset($args["m"])){
			if(!$entity instanceof Player){
				return false;
			}
			[$negate, $mode] = self::negation($args["m"]);
			$gameMode = match(strtolower($mode)){
				"0", "s", "survival" => GameMode::SURVIVAL,
				"1", "c", "creative" => GameMode::CREATIVE,
				"2", "a", "adventure" => GameMode::ADVENTURE,
				"3", "sp", "spectator" => GameMode::SPECTATOR,
				default => null,
			};
			if($gameMode === null || ($entity->getGamemode() === $gameMode) === $negate){
				return false;
			}
		}
		if(isset($args["l"]) || isset($args["lm"])){
			if(!$entity instanceof Player){
				return false;
			}
			$level = $entity->getXpManager()->getXpLevel();
			if((isset($args["l"]) && $level > (int) $args["l"]) || (isset($args["lm"]) && $level < (int) $args["lm"])){
				return false;
			}
		}
		return true;
	}

	/** @return array{bool, string} */
	private static function negation(string $value) : array{
		return str_starts_with($value, "!") ? [true, substr($value, 1)] : [false, $value];
	}
}
