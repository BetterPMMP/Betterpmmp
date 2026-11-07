<?php

/*
 * BetterPMMP - frogspawn and sniffer eggs.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Location;
use pocketmine\entity\SnifferMob;
use pocketmine\entity\TadpoleMob;
use function mt_rand;

/**
 * Frogspawn hatches 2-6 tadpoles after a few minutes on water. A sniffer egg
 * cracks twice and then hatches a baby sniffer (about 20 minutes, twice as
 * fast on moss).
 */
class VanillaHatchable extends GenericBedrockBlock{

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($this->getBedrockName() === "minecraft:frog_spawn"){
			if(!$this->getSide(\pocketmine\math\Facing::DOWN) instanceof Water){
				$world->useBreakOn($this->position);
				return;
			}
			if(mt_rand(0, 2) !== 0){
				return;
			}
			$world->setBlock($this->position, VanillaBlocks::AIR());
			for($i = mt_rand(2, 6); $i > 0; --$i){
				$tadpole = new TadpoleMob(Location::fromObject($this->position->add(0.5 + mt_rand(-3, 3) / 10, -0.5, 0.5 + mt_rand(-3, 3) / 10), $world, mt_rand(0, 359), 0));
				$tadpole->spawnToAll();
			}
			return;
		}
		// sniffer egg
		$below = $this->getSide(\pocketmine\math\Facing::DOWN);
		$onMoss = $below instanceof GenericBedrockBlock && $below->getBedrockName() === "minecraft:moss_block";
		if(mt_rand(0, $onMoss ? 3 : 7) !== 0){
			return;
		}
		$tag = $this->getStateData()->getStates()["cracked_state"] ?? null;
		$state = $tag === null ? "no_cracks" : (string) $tag->getValue();
		if($state === "max_cracked"){
			$world->setBlock($this->position, VanillaBlocks::AIR());
			$baby = new SnifferMob(Location::fromObject($this->position->add(0.5, 0, 0.5), $world, mt_rand(0, 359), 0));
			$baby->setBaby(true);
			$baby->spawnToAll();
			return;
		}
		$world->setBlock($this->position, $this->withProperties(["cracked_state" => $state === "no_cracks" ? "cracked" : "max_cracked"]));
	}
}
