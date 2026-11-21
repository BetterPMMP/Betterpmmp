<?php

/*
 * BetterPMMP - console menu: simple info window with a back button.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class ConsoleInfoForm implements Form{

	public function __construct(private string $title, private string $text){}

	public function jsonSerialize() : array{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->text,
			"buttons" => [["text" => "Zurück zum Menü"], ["text" => "Schließen"]]
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === 0){
			ConsoleMenu::open($player);
		}
	}
}
