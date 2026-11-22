<?php

/*
 * BetterPMMP - /schedule like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\Server;
use function count;
use function is_numeric;
use function preg_match;
use function strtolower;

/**
 * /schedule delay add <function> <time>[t|s|d] [append|replace]
 * /schedule clear <function>
 * /schedule on_area_loaded add <from: x y z> <to: x y z> <function>
 * Runs the function from the console later (like a function started by the world).
 */
final class ScheduleCommand extends WorldEditingCommand{

	/** @var list<array{int, string}> [server tick, function] */
	private static array $delayed = [];
	/** @var list<array{string, int, int, int, int, string}> [world, minChunkX, minChunkZ, maxChunkX, maxChunkZ, function] */
	private static array $onArea = [];

	public function __construct(){
		parent::__construct("schedule", "Schedules an action to be executed.", "/schedule <delay|clear|on_area_loaded> ...");
		$this->setPermission(DefaultPermissionNames::COMMAND_SCHEDULE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$server = $sender->getServer();
		switch(strtolower($args[0])){
			case "delay":
				if(strtolower($args[1]) !== "add" || !isset($args[2], $args[3]) || preg_match('/^(\d+(?:\.\d+)?)([tsd]?)$/i', $args[3], $m) !== 1){
					throw new InvalidCommandSyntaxException();
				}
				$ticks = (int) match(strtolower($m[2])){
					"s" => (float) $m[1] * 20,
					"d" => (float) $m[1] * 24000,
					default => (float) $m[1],
				};
				if(strtolower($args[4] ?? "replace") === "replace"){
					self::remove($args[2]);
				}
				self::$delayed[] = [$server->getTick() + $ticks, $args[2]];
				$sender->sendMessage("Scheduled function " . $args[2] . " in " . $ticks . " ticks");
				return true;
			case "clear":
				$removed = self::remove($args[1]);
				$sender->sendMessage("Cleared " . $removed . " scheduled function(s) " . $args[1]);
				return true;
			case "on_area_loaded":
				if(strtolower($args[1]) !== "add" || count($args) < 9){
					throw new InvalidCommandSyntaxException();
				}
				$origin = $this->senderPosition($sender);
				$a = $this->parsePosition($sender, $args, 2, $origin);
				$b = $this->parsePosition($sender, $args, 5, $origin);
				self::$onArea[] = [$origin->getWorld()->getFolderName(), min((int) $a->x, (int) $b->x) >> 4, min((int) $a->z, (int) $b->z) >> 4, max((int) $a->x, (int) $b->x) >> 4, max((int) $a->z, (int) $b->z) >> 4, $args[8]];
				$sender->sendMessage("Function " . $args[8] . " runs once the area is loaded");
				return true;
		}
		throw new InvalidCommandSyntaxException();
	}

	private static function remove(string $function) : int{
		$before = count(self::$delayed);
		self::$delayed = array_values(array_filter(self::$delayed, fn(array $entry) => $entry[1] !== $function));
		return $before - count(self::$delayed);
	}

	/** Called every server tick. */
	public static function tick(Server $server) : void{
		if(self::$delayed === [] && self::$onArea === []){
			return;
		}
		$now = $server->getTick();
		$due = [];
		foreach(self::$delayed as $k => [$tick, $function]){
			if($tick <= $now){
				$due[] = $function;
				unset(self::$delayed[$k]);
			}
		}
		self::$delayed = array_values(self::$delayed);
		if($now % 20 === 0){
			foreach(self::$onArea as $k => [$worldName, $x1, $z1, $x2, $z2, $function]){
				$world = $server->getWorldManager()->getWorldByName($worldName);
				if($world === null){
					continue;
				}
				for($x = $x1; $x <= $x2; ++$x){
					for($z = $z1; $z <= $z2; ++$z){
						if(!$world->isChunkLoaded($x, $z)){
							continue 3;
						}
					}
				}
				$due[] = $function;
				unset(self::$onArea[$k]);
			}
			self::$onArea = array_values(self::$onArea);
		}
		if($due === []){
			return;
		}
		$console = new ConsoleCommandSender($server, $server->getLanguage());
		foreach($due as $function){
			$server->getCommandMap()->dispatch($console, "function " . $function);
		}
	}
}
