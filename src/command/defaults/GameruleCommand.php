<?php

/*
 * BetterPMMP - /gamerule like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\GameRules;
use function count;
use function implode;
use function is_bool;
use function is_numeric;
use function strtolower;

/** /gamerule [rule] [value] - in the sender's world (console: the default world) */
final class GameruleCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("gamerule", "Sets or queries a game rule value.", "/gamerule [rule] [value]");
		$this->setPermission(DefaultPermissionNames::COMMAND_GAMERULE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$world = $this->senderPosition($sender)->getWorld();
		if(count($args) === 0){
			$list = [];
			foreach(GameRules::RULES as $key => [$name, $default]){
				$value = GameRules::get($world, $key);
				$list[] = $name . " = " . (is_bool($value) ? ($value ? "true" : "false") : $value);
			}
			$sender->sendMessage(implode(", ", $list));
			return true;
		}
		$key = GameRules::key($args[0]);
		if($key === null){
			return $this->error($sender, "Unknown game rule: " . $args[0]);
		}
		[$name, $default] = GameRules::RULES[$key];
		if(count($args) === 1){
			$value = GameRules::get($world, $key);
			$sender->sendMessage($name . " = " . (is_bool($value) ? ($value ? "true" : "false") : $value));
			return true;
		}
		$raw = strtolower($args[1]);
		if(is_bool($default)){
			if($raw !== "true" && $raw !== "false"){
				return $this->error($sender, "'" . $args[1] . "' is not a valid value for " . $name . " (true or false)");
			}
			$value = $raw === "true";
		}else{
			if(!is_numeric($raw) || (int) $raw < 0){
				return $this->error($sender, "'" . $args[1] . "' is not a valid value for " . $name . " (a number)");
			}
			$value = (int) $raw;
		}
		GameRules::set($world, $key, $value);
		Command::broadcastCommandMessage($sender, "Game rule " . $name . " has been updated to " . ($value === true ? "true" : ($value === false ? "false" : $value)));
		return true;
	}
}
