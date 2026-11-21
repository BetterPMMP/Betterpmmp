<?php

/*
 * BetterPMMP - in-game console menu (/console).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\BetterPMMPConfig;
use pocketmine\console\CapturingConsoleSender;
use pocketmine\network\Rcon;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use function array_filter;
use function array_map;
use function array_slice;
use function array_unshift;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Lets admins run server console commands from a menu inside Minecraft.
 * Who may use it: the permission pocketmine.command.console (OPs by default) and,
 * if console-menu.players in betterpmmp.yml is not empty, only the names listed there.
 */
final class ConsoleMenu{

	private const MAX_HISTORY = 10;
	private const MAX_OUTPUT_CHARS = 6000;

	/** @var array<string, string[]> lower-case player name => last commands (newest first) */
	private static array $history = [];
	private static ?CapturingConsoleSender $sender = null;

	public static function isEnabled() : bool{
		return BetterPMMPConfig::flag("console-menu", "enabled", true);
	}

	/** Extra check on top of the permission: the optional name list from betterpmmp.yml. */
	public static function isAllowed(Player $player) : bool{
		$names = BetterPMMPConfig::list("console-menu", "players");
		if(count($names) === 0){
			return true;
		}
		$name = strtolower($player->getName());
		foreach($names as $entry){
			if(strtolower(trim((string) $entry)) === $name){
				return true;
			}
		}
		return false;
	}

	/**
	 * Quick buttons from betterpmmp.yml (console-menu.buttons), "Name: command" entries.
	 * @return array<int, array{string, string}> [label, command]
	 */
	public static function quickButtons() : array{
		$out = [];
		foreach(BetterPMMPConfig::list("console-menu", "buttons") as $entry){
			if(is_array($entry)){
				foreach($entry as $label => $command){
					if(is_string($command) && trim($command) !== ""){
						$out[] = [(string) $label, trim($command)];
					}
				}
			}elseif(is_string($entry) && trim($entry) !== ""){
				$out[] = [trim($entry), trim($entry)];
			}
		}
		return $out;
	}

	/** @return string[] */
	public static function history(Player $player) : array{
		return self::$history[strtolower($player->getName())] ?? [];
	}

	public static function open(Player $player) : void{
		if(!$player->isConnected()){
			return;
		}
		if(!self::canUse($player)){
			$player->sendMessage(TextFormat::RED . "Du darfst das Konsolen-Menü nicht benutzen.");
			return;
		}
		$player->sendForm(new ConsoleMainForm($player));
	}

	/** Shows a pairing code for BetterPMMP-Konsole.exe in chat and as a window. */
	public static function showPairing(Player $player) : void{
		if(!self::canUse($player)){
			return;
		}
		if(!Rcon::isRunning()){
			$player->sendMessage(TextFormat::RED . "RCON ist aus (betterpmmp.yml: rcon.enabled) - koppeln geht gerade nicht.");
			return;
		}
		$code = Rcon::createPairingCode($player->getName());
		$text = "Code für BetterPMMP-Konsole.exe:\n\n" . TextFormat::BOLD . TextFormat::GREEN . $code . TextFormat::RESET .
			"\n\nIn der .exe beim Server auf \"Koppeln\" klicken und den Code eingeben.\nGilt 5 Minuten. RCON-Port: " . Rcon::port() . " (TCP)";
		$player->sendMessage(TextFormat::GREEN . "Kopplungs-Code: " . TextFormat::BOLD . $code . TextFormat::RESET . TextFormat::GRAY . " (5 Minuten gültig)");
		$player->sendForm(new ConsoleInfoForm("Mit PC koppeln", $text));
	}

	public static function openInput(Player $player, string $default = "") : void{
		if(self::canUse($player)){
			$player->sendForm(new ConsoleInputForm($default));
		}
	}

	public static function canUse(Player $player) : bool{
		return self::isEnabled() && $player->hasPermission("pocketmine.command.console") && self::isAllowed($player);
	}

	/**
	 * Runs one command as the server console and shows the output to the player.
	 * Commands marked as dangerous (console-menu.confirm) ask first.
	 */
	public static function run(Player $player, string $command, bool $confirmed = false) : void{
		if(!$player->isConnected() || !self::canUse($player)){
			return;
		}
		$command = trim($command);
		if($command !== "" && $command[0] === "/"){
			$command = mb_substr($command, 1);
		}
		if($command === ""){
			self::open($player);
			return;
		}
		$command = str_replace("{player}", $player->getName(), $command);
		$label = strtolower(explode(" ", $command, 2)[0]);
		$confirm = BetterPMMPConfig::list("console-menu", "confirm");
		if(!$confirmed && in_array($label, array_map(fn($c) => strtolower((string) $c), $confirm), true)){
			$player->sendForm(new ConsoleConfirmForm($command));
			return;
		}

		$key = strtolower($player->getName());
		$list = self::$history[$key] ?? [];
		$list = array_values(array_filter($list, fn(string $c) => $c !== $command));
		array_unshift($list, $command);
		self::$history[$key] = array_slice($list, 0, self::MAX_HISTORY);

		$server = Server::getInstance();
		$server->getLogger()->info("[Konsolen-Menü] " . $player->getName() . " führt aus: " . $command);
		self::$sender ??= new CapturingConsoleSender($server, $server->getLanguage());
		self::$sender->takeOutput();
		$known = $server->dispatchCommand(self::$sender, $command);
		$output = self::$sender->takeOutput();
		if(!$known && $output === ""){
			$output = TextFormat::RED . "Unbekannter Befehl oder vom Plugin abgebrochen.";
		}
		if($output === ""){
			$output = TextFormat::GRAY . "(keine Ausgabe)";
		}
		if(mb_strlen($output) > self::MAX_OUTPUT_CHARS){
			$output = mb_substr($output, 0, self::MAX_OUTPUT_CHARS) . "\n" . TextFormat::GRAY . "... (gekürzt, Rest steht in der Server-Konsole)";
		}
		if($player->isConnected()){
			$player->sendForm(new ConsoleResultForm($command, $output));
		}
	}
}
