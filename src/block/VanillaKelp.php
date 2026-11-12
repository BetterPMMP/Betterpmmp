<?php

/*
 * BetterPMMP - kelp growth.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\particle\HappyVillagerParticle;
use function max;
use function min;
use function mt_rand;

/**
 * Vanilla kelp: the top of a kelp plant grows one block into the still water
 * above it (14 % per random tick) until its age reaches 25. Bone meal grows it
 * up to a few blocks at once. Kelp breaks without kelp or a solid block below.
 */
class VanillaKelp extends GenericBedrockBlock{

	public const MAX_AGE = 25;

	public function getAge() : int{
		$tag = $this->getStateData()->getStates()["kelp_age"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	private function canGrowInto(Block $block) : bool{
		return $block instanceof Water && $block->isSource();
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if($this->getAge() >= self::MAX_AGE || mt_rand(1, 100) > 14){
			return;
		}
		$this->growUp();
	}

	private function growUp() : bool{
		$above = $this->getSide(Facing::UP);
		if(!$this->canGrowInto($above)){
			return false;
		}
		$this->position->getWorld()->setBlock($above->getPosition(), $this->withProperties(["kelp_age" => max(0, min(self::MAX_AGE, $this->getAge() + 1))]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer){
			return false;
		}
		// bone meal works on the top block of the plant
		$top = $this;
		while(($next = $top->getSide(Facing::UP)) instanceof self){
			$top = $next;
		}
		if(!$top->canGrowInto($top->getSide(Facing::UP))){
			return false;
		}
		$world = $this->position->getWorld();
		for($i = mt_rand(1, 3); $i > 0 && $top instanceof self && $top->growUp(); --$i){
			$top = $world->getBlock($top->getPosition()->up());
		}
		$world->addParticle($this->position->add(0.5, 0.5, 0.5), new HappyVillagerParticle());
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		// kelp grows in water: breaking it leaves the water behind
		$this->position->getWorld()->setBlock($this->position, VanillaBlocks::WATER());
		return true;
	}

	public function onNearbyBlockChange() : void{
		$below = $this->getSide(Facing::DOWN);
		if(!$below instanceof self && !$below->isSolid()){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}
}
