<?php

/*
 * BetterPMMP - /tellraw like vanilla (Bedrock rawtext JSON).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\entity\Entity;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function array_slice;
use function count;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;

/**
 * /tellraw <target> <raw json message>
 * {"rawtext":[{"text":"Hello "},{"selector":"@p"},{"translate":"chat.type.announcement","with":["a","b"]}]}
 */
final class TellrawCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("tellraw", "Sends a JSON message to players.", "/tellraw <target> <raw json message>");
		$this->setPermission(DefaultPermissionNames::COMMAND_TELLRAW);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$data = json_decode(implode(" ", array_slice($args, 1)), true);
		if(!is_array($data)){
			$sender->sendMessage("§cInvalid JSON message");
			return true;
		}
		$targets = TargetSelector::selectPlayers($sender, $args[0]);
		if(count($targets) === 0){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		foreach($targets as $player){
			$player->sendMessage($this->render($sender, $data, $player));
		}
		return true;
	}

	/** @param mixed[] $part */
	private function render(CommandSender $sender, array $part, Player $viewer) : string|Translatable{
		if(isset($part["rawtext"]) && is_array($part["rawtext"])){
			$out = "";
			foreach($part["rawtext"] as $child){
				if(is_array($child)){
					$rendered = $this->render($sender, $child, $viewer);
					$out .= $rendered instanceof Translatable ? $viewer->getLanguage()->translate($rendered) : $rendered;
				}
			}
			return $out;
		}
		if(isset($part["text"]) && is_scalar($part["text"])){
			return (string) $part["text"];
		}
		if(isset($part["selector"]) && is_string($part["selector"])){
			$names = [];
			foreach(TargetSelector::select($sender, $part["selector"]) as $entity){
				$names[] = $entity instanceof Player ? $entity->getDisplayName() : ($entity->getNameTag() !== "" ? $entity->getNameTag() : $entity->getName());
			}
			return implode(", ", $names);
		}
		if(isset($part["translate"]) && is_string($part["translate"])){
			$with = [];
			$params = $part["with"] ?? [];
			if(is_array($params) && isset($params["rawtext"])){
				$params = [$this->render($sender, $params, $viewer)];
			}
			foreach(is_array($params) ? $params : [] as $param){
				$with[] = is_array($param) ? $this->render($sender, $param, $viewer) : (string) $param;
			}
			return new Translatable($part["translate"], $with);
		}
		return "";
	}
}
