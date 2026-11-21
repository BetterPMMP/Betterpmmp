<?php

/*
 * BetterPMMP - /console: run server console commands from inside the game.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\console\menu\ConsoleMenu;
use pocketmine\network\Rcon;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function in_array;
use function strtolower;
use function implode;

/**
 * /console           opens the console menu
 * /console <befehl>  runs the command as console right away and shows the output
 * /console koppeln   shows a code to pair BetterPMMP-Konsole.exe with this server
 * /console entkoppeln removes your pairings
 */
class ConsoleMenuCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("console", "Opens the server console menu", "/console [command]", ["konsole", "cmd"]);
		$this->setPermission(DefaultPermissionNames::COMMAND_CONSOLE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}
		if(!$sender instanceof Player){
			$sender->sendMessage("Du bist schon in der Konsole.");
			return true;
		}
		if(!ConsoleMenu::isEnabled()){
			$sender->sendMessage(TextFormat::RED . "Das Konsolen-Menü ist in betterpmmp.yml ausgeschaltet (console-menu.enabled).");
			return true;
		}
		if(!ConsoleMenu::isAllowed($sender)){
			$sender->sendMessage(TextFormat::RED . "Dein Name steht nicht in console-menu.players (betterpmmp.yml).");
			return true;
		}
		$sub = strtolower($args[0] ?? "");
		if(count($args) === 1 && in_array($sub, ["koppeln", "pair", "link"], true)){
			ConsoleMenu::showPairing($sender);
			return true;
		}
		if(count($args) === 1 && in_array($sub, ["entkoppeln", "unpair", "unlink"], true)){
			$n = Rcon::unpair($sender->getName());
			$sender->sendMessage(TextFormat::YELLOW . "$n Kopplung(en) mit BetterPMMP-Konsole.exe entfernt.");
			return true;
		}
		if(count($args) > 0){
			ConsoleMenu::run($sender, implode(" ", $args));
		}else{
			ConsoleMenu::open($sender);
		}
		return true;
	}
}
