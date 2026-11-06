<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\object\FishingHook;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;

/** BetterPMMP: casts and reels in a bobber like vanilla. */
class FishingRod extends Durable{

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getMaxDurability() : int{
		return 384;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$hook = FishingHook::getHookOf($player);
		if($hook !== null){
			$damage = $hook->reel();
			$player->getWorld()->addSound($player->getPosition(), new VanillaSound(LevelSoundEvent::THROW));
			if($damage > 0 && $player->hasFiniteResources()){
				$this->applyDamage($damage);
			}
			return ItemUseResult::SUCCESS;
		}
		FishingHook::cast($player, $this);
		$player->getWorld()->addSound($player->getPosition(), new VanillaSound(LevelSoundEvent::THROW));
		return ItemUseResult::SUCCESS;
	}
}
