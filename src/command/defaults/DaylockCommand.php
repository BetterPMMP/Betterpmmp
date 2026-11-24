<?php

/*
 * BetterPMMP - /daylock (/alwaysday) like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function strtolower;

/** /daylock [true|false]: locks the time at noon (doDaylightCycle false) or lets it run again */
final class DaylockCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("daylock", "Locks and unlocks the day-night cycle.", "/daylock [lock: Boolean]", ["alwaysday"]);
		$this->setPermission(DefaultPermissionNames::COMMAND_DAYLOCK);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$lock = strtolower($args[0] ?? "true") !== "false";
		$world = $this->senderPosition($sender)->getWorld();
		if($lock){
			$world->setTime(World::TIME_DAY + 5000); //vanilla: noon (6000)
		}
		GameRules::set($world, "dodaylightcycle", !$lock);
		Command::broadcastCommandMessage($sender, $lock ? "Day-Night cycle locked" : "Day-Night cycle unlocked");
		return true;
	}
}
