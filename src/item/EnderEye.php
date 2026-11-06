<?php

/*
 * BetterPMMP - eye of ender.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\object\EyeOfEnderSignal;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use pocketmine\world\generator\vanilla\structure\Stronghold;
use pocketmine\world\sound\ThrowSound;
use function strtolower;

/**
 * Thrown with right click in the overworld: flies towards the nearest
 * stronghold. Placing it in end portal frames is handled by the frame block.
 */
class EnderEye extends Item{

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$world = $player->getWorld();
		if($world->getDimensionId() !== DimensionIds::OVERWORLD){
			return ItemUseResult::NONE;
		}
		try{
			$generator = strtolower($world->getProvider()->getWorldData()->getGenerator());
		}catch(\Throwable){
			return ItemUseResult::NONE;
		}
		if($generator !== "vanilla"){
			return ItemUseResult::NONE;
		}
		$pos = $player->getPosition();
		$best = null;
		$bestDistance = PHP_FLOAT_MAX;
		foreach(Stronghold::positions($world->getSeed()) as [$cx, $cz]){
			$x = ($cx << 4) + 8;
			$z = ($cz << 4) + 8;
			$distance = ($x - $pos->x) ** 2 + ($z - $pos->z) ** 2;
			if($distance < $bestDistance){
				$bestDistance = $distance;
				$best = new Vector3($x, 0, $z);
			}
		}
		if($best === null){
			return ItemUseResult::NONE;
		}
		$eye = new EyeOfEnderSignal(Location::fromObject($player->getEyePos(), $world), $best);
		$eye->spawnToAll();
		$world->addSound($pos, new ThrowSound());
		$this->pop();
		return ItemUseResult::SUCCESS;
	}
}
