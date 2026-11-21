<?php

/*
 * BetterPMMP - popup: allow BetterPMMP-Konsole.exe to log in without a code.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console\menu;

use pocketmine\form\Form;
use pocketmine\network\Rcon;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

final class ConsoleApproveForm implements Form{

	public function __construct(private int $clientId, private string $ip){}

	public function jsonSerialize() : array{
		return [
			"type" => "modal",
			"title" => "BetterPMMP-Konsole",
			"content" => "Das Konsolen-Programm von deiner IP (" . $this->ip . ") will sich verbinden.\n\n" .
				TextFormat::YELLOW . "Nur erlauben, wenn du das gerade selbst gestartet hast!" . TextFormat::RESET .
				"\n\nDanach merkt sich der Server das Programm (/console entkoppeln zum Entfernen).",
			"button1" => "Erlauben",
			"button2" => "Ablehnen"
		];
	}

	public function handleResponse(Player $player, $data) : void{
		Rcon::answerApproval($this->clientId, $player->getName(), $data === true);
	}
}
