<?php

/*
 * BetterPMMP - dried ghast (1.21.90).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\HappyGhastMob;
use pocketmine\entity\Location;
use pocketmine\math\Facing;
use function mt_rand;

/**
 * Rehydrates in water: four stages, about 20 minutes in total, then it turns
 * into a ghastling (baby happy ghast). Out of water it dries again.
 * (The core has no waterlogging yet, so water next to the block counts.)
 */
class VanillaDriedGhast extends GenericBedrockBlock{

	public function ticksRandomly() : bool{
		return true;
	}

	private function inWater() : bool{
		foreach(Facing::ALL as $face){
			if($this->getSide($face) instanceof Water){
				return true;
			}
		}
		return false;
	}

	private function level() : int{
		$tag = $this->getStateData()->getStates()["rehydration_level"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		$level = $this->level();
		if(!$this->inWater()){
			if($level > 0 && mt_rand(0, 3) === 0){
				$world->setBlock($this->position, $this->withProperties(["rehydration_level" => $level - 1]));
			}
			return;
		}
		if(mt_rand(0, 99) >= 23){
			return;
		}
		if($level < 3){
			$world->setBlock($this->position, $this->withProperties(["rehydration_level" => $level + 1]));
			return;
		}
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$ghastling = new HappyGhastMob(Location::fromObject($this->position->add(0.5, 0.1, 0.5), $world, mt_rand(0, 359), 0));
		$ghastling->setGhastling(true);
		$ghastling->spawnToAll();
	}
}
