<?php

/*
 * BetterPMMP - console menu: confirmation for dangerous commands (e.g. stop).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function is_bool;

final class ConsoleConfirmForm implements Form{

	public function __construct(private string $command){}

	public function jsonSerialize() : array{
		return [
			"type" => "modal",
			"title" => "Sicher?",
			"content" => "Wirklich ausführen?\n\n" . TextFormat::RED . "/" . $this->command,
			"button1" => "Ja, ausführen",
			"button2" => "Abbrechen"
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_bool($data)){
			throw new FormValidationException("Expected true or false");
		}
		if($data){
			ConsoleMenu::run($player, $this->command, true);
		}else{
			ConsoleMenu::open($player);
		}
	}
}
