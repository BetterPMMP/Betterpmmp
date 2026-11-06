<?php

/*
 * BetterPMMP - any Bedrock level sound by its name.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;

/** A Bedrock level sound event, e.g. new VanillaSound(LevelSoundEvent::MACE_SMASH_GROUND). */
final class VanillaSound implements Sound{

	public function __construct(private string $event, private int $extraData = -1){}

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::nonActorSound($this->event, $pos, false, $this->extraData)];
	}
}
