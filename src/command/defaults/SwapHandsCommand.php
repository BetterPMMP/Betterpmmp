<?php

/*
 * BetterPMMP - /swaphands.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;

/** Swaps the items in the main hand and the off hand (any item). */
class SwapHandsCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("swaphands", "Swaps the items in your main hand and off hand", "/swaphands", ["swap", "offhand"]);
		$this->setPermission(DefaultPermissionNames::COMMAND_SWAPHANDS);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}
		if(!$sender instanceof Player){
			$sender->sendMessage("This command can only be used in game");
			return true;
		}
		$sender->swapHandItems();
		return true;
	}
}
