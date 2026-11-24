<?php

/*
 * BetterPMMP - /function like vanilla Bedrock (.mcfunction files).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\GameRules;
use Symfony\Component\Filesystem\Path;
use function count;
use function file;
use function glob;
use function is_file;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * /function <name> runs <name>.mcfunction line by line, like Bedrock. Files are looked up in
 * "functions/" in the server folder and in "behavior_packs/<pack>/functions/". At most functionCommandLimit
 * commands (game rule, default 10000) run per call, nested functions included.
 */
final class FunctionCommand extends VanillaCommand{
	private static int $running = 0;
	private static int $depth = 0;

	public function __construct(){
		parent::__construct("function", "Runs commands found in the corresponding function file.", "/function <name>");
		$this->setPermission(DefaultPermissionNames::COMMAND_FUNCTION);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$name = trim($args[0], "\"/");
		if($name === "" || str_contains($name, "..") || preg_match('/^[A-Za-z0-9_\/\-.]+$/', $name) !== 1){
			$sender->sendMessage("§cInvalid function name: " . $args[0]);
			return true;
		}
		$file = self::find($sender->getServer()->getDataPath(), $name);
		if($file === null){
			$sender->sendMessage("§cFunction " . $name . " not found");
			return true;
		}
		$lines = @file($file);
		if($lines === false){
			$sender->sendMessage("§cFunction " . $name . " could not be read");
			return true;
		}
		$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
		$limit = $world !== null ? GameRules::int($world, "functioncommandlimit") : 10000;
		if(self::$depth === 0){
			self::$running = 0;
		}
		++self::$depth;
		$count = 0;
		try{
			foreach($lines as $line){
				$line = trim($line);
				if($line === "" || str_starts_with($line, "#")){
					continue;
				}
				if(++self::$running > $limit){
					$sender->sendMessage("§cFunction " . $name . " stopped: more than " . $limit . " commands (game rule functionCommandLimit)");
					break;
				}
				$sender->getServer()->getCommandMap()->dispatch($sender, ltrim($line, "/"));
				++$count;
			}
		}finally{
			--self::$depth;
		}
		if(self::$depth === 0){
			$sender->sendMessage("Successfully executed " . $count . " function entries");
		}
		return true;
	}

	private static function find(string $dataPath, string $name) : ?string{
		$candidates = [Path::join($dataPath, "functions", $name . ".mcfunction")];
		foreach(glob(Path::join($dataPath, "behavior_packs", "*", "functions"), GLOB_ONLYDIR) ?: [] as $dir){
			$candidates[] = Path::join($dir, $name . ".mcfunction");
		}
		foreach($candidates as $candidate){
			if(is_file($candidate)){
				return $candidate;
			}
		}
		return null;
	}
}
