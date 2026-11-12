<?php

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\player\Player;

/** BetterPMMP: a trident thrown by a drowned - it cannot be picked up (vanilla) and vanishes after a minute. */
class MobTrident extends Trident implements NeverSavedWithChunkEntity{

	public function onCollideWithPlayer(Player $player) : void{}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->ticksLived > 1200){
			$this->flagForDespawn();
		}
		return parent::entityBaseTick($tickDiff);
	}
}
