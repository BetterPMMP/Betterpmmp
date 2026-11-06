<?php

/*
 * BetterPMMP - ender dragon death animation.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\animation;

use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;

final class DragonDeathAnimation implements Animation{

	public function __construct(private Living $entity){}

	public function encode() : array{
		return [
			ActorEventPacket::create($this->entity->getId(), ActorEvent::ENDER_DRAGON_DEATH, 0, null)
		];
	}
}
