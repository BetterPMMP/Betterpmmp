<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\player\Player;

/** BetterPMMP: a mob that can be tamed (wolf, cat, parrot). */
interface TameablePet{
	public function isTamed() : bool;
	public function isOwnedBy(Player $player) : bool;
	public function isSitting() : bool;
	public function setSitting(bool $sitting) : void;
}
