<?php

/*
 * BetterPMMP - console menu: command output.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function is_int;

final class ConsoleResultForm implements Form{

	public function __construct(private string $command, private string $output){}

	public function jsonSerialize() : array{
		return [
			"type" => "form",
			"title" => "Ausgabe",
			"content" => TextFormat::GRAY . "> /" . $this->command . TextFormat::RESET . "\n\n" . $this->output,
			"buttons" => [
				["text" => "Nochmal ausführen"],
				["text" => "Bearbeiten"],
				["text" => "Neuer Befehl"],
				["text" => "Zurück zum Menü"]
			]
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_int($data)){
			throw new FormValidationException("Expected a button index");
		}
		match($data){
			0 => ConsoleMenu::run($player, $this->command),
			1 => ConsoleMenu::openInput($player, $this->command),
			2 => ConsoleMenu::openInput($player),
			3 => ConsoleMenu::open($player),
			default => throw new FormValidationException("Invalid button $data")
		};
	}
}
