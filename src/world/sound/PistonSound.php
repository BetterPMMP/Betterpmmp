<?php

/*
 * BetterPMMP - vanilla redstone components.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

class PistonSound implements Sound{

	public function __construct(private bool $extend){}

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::nonActorSound($this->extend ? LevelSoundEvent::PISTON_OUT : LevelSoundEvent::PISTON_IN, $pos, false)];
	}
}
