<?php

/*
 * BetterPMMP - console menu: main window.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function is_int;

/** Buttons: type a command, quick commands from betterpmmp.yml, recent commands. */
final class ConsoleMainForm implements Form{

	/** @var array<int, array{string, string}> button index => [kind, command] */
	private array $actions = [];

	public function __construct(private Player $player){}

	public function jsonSerialize() : array{
		$buttons = [];
		$this->actions = [];
		$buttons[] = ["text" => TextFormat::DARK_GREEN . "Befehl eingeben"];
		$this->actions[] = ["input", ""];
		foreach(ConsoleMenu::quickButtons() as [$label, $command]){
			$buttons[] = ["text" => $label . "\n" . TextFormat::DARK_GRAY . "/" . $command];
			$this->actions[] = ["run", $command];
		}
		$history = ConsoleMenu::history($this->player);
		foreach($history as $command){
			$buttons[] = ["text" => TextFormat::DARK_BLUE . "Verlauf\n" . TextFormat::DARK_GRAY . "/" . $command];
			$this->actions[] = ["input", $command];
		}
		$buttons[] = ["text" => TextFormat::DARK_PURPLE . "Mit PC-Programm koppeln\n" . TextFormat::DARK_GRAY . "BetterPMMP-Konsole.exe"];
		$this->actions[] = ["pair", ""];
		$buttons[] = ["text" => TextFormat::RED . "Schließen"];
		$this->actions[] = ["close", ""];
		return [
			"type" => "form",
			"title" => "Server-Konsole",
			"content" => "Befehle laufen mit vollen Konsolen-Rechten.\n{player} wird durch deinen Namen ersetzt.",
			"buttons" => $buttons
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_int($data) || $data < 0 || $data >= count($this->actions)){
			throw new FormValidationException("Invalid button $data");
		}
		[$kind, $command] = $this->actions[$data];
		match($kind){
			"input" => ConsoleMenu::openInput($player, $command),
			"run" => ConsoleMenu::run($player, $command),
			"pair" => ConsoleMenu::showPairing($player),
			default => null
		};
	}
}
