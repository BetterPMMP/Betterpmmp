<?php

/*
 * BetterPMMP - /tag like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function implode;
use function strtolower;

/** /tag <targets> add|remove <name> | /tag <targets> list */
final class TagCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("tag", "Manages tags stored in entities.", "/tag <targets> <add|remove|list> [name]");
		$this->setPermission(DefaultPermissionNames::COMMAND_TAG);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$entities = TargetSelector::select($sender, $args[0]);
		if($entities === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		$action = strtolower($args[1]);
		if($action === "list"){
			foreach($entities as $entity){
				$tags = $entity->getCommandTags();
				$sender->sendMessage(\pocketmine\command\defaults\ScoreboardCommand::scoreName($entity) . " has " . count($tags) . " tags: " . implode(", ", $tags));
			}
			return true;
		}
		if(!isset($args[2]) || ($action !== "add" && $action !== "remove")){
			throw new InvalidCommandSyntaxException();
		}
		$changed = 0;
		foreach($entities as $entity){
			if($action === "add" ? $entity->addCommandTag($args[2]) : $entity->removeCommandTag($args[2])){
				++$changed;
			}
		}
		if($changed === 0){
			$sender->sendMessage("§cNo tags were " . ($action === "add" ? "added" : "removed"));
			return true;
		}
		$sender->sendMessage(($action === "add" ? "Added tag '" : "Removed tag '") . $args[2] . "' " . ($action === "add" ? "to " : "from ") . $changed . " entities");
		return true;
	}
}
