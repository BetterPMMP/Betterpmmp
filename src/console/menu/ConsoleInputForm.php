<?php

/*
 * BetterPMMP - console menu: command input.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function is_array;
use function is_string;

final class ConsoleInputForm implements Form{

	public function __construct(private string $default = ""){}

	public function jsonSerialize() : array{
		return [
			"type" => "custom_form",
			"title" => "Befehl eingeben",
			"content" => [
				["type" => "input", "text" => "Konsolen-Befehl (ohne /):", "placeholder" => "z. B. time set day", "default" => $this->default]
			]
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			ConsoleMenu::open($player);
			return;
		}
		if(!is_array($data) || !isset($data[0]) || !is_string($data[0])){
			throw new FormValidationException("Expected one text field");
		}
		ConsoleMenu::run($player, $data[0]);
	}
}
