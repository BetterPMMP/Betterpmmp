<?php

/*
 * BetterPMMP - eyeblossom (pale garden).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\world\World;

/** Opens at night and closes by day (vanilla: on random ticks). */
class VanillaEyeblossom extends GenericBedrockBlock{

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		$time = $world->getTimeOfDay();
		$night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
		$open = $this->getBedrockName() === "minecraft:open_eyeblossom";
		if($night === $open){
			return;
		}
		$other = ExtraVanillaBlocks::get($night ? "minecraft:open_eyeblossom" : "minecraft:closed_eyeblossom");
		if($other !== null){
			$world->setBlock($this->position, $other);
		}
	}
}
