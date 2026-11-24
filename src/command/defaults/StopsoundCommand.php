<?php

/*
 * BetterPMMP - /stopsound like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\permission\DefaultPermissionNames;
use function count;

/** /stopsound <player> [sound] */
final class StopsoundCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("stopsound", "Stops a sound.", "/stopsound <player> [sound]");
		$this->setPermission(DefaultPermissionNames::COMMAND_STOPSOUND);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$players = TargetSelector::selectPlayers($sender, $args[0]);
		if($players === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		$sound = $args[1] ?? "";
		foreach($players as $player){
			$player->getNetworkSession()->sendDataPacket(StopSoundPacket::create($sound, $sound === "", $sound === ""));
		}
		$sender->sendMessage($sound === "" ? "Stopped all sounds" : "Stopped sound '" . $sound . "'");
		return true;
	}
}
