<?php

/*
 * BetterPMMP - turtle eggs.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\TurtleMob;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use pocketmine\world\World;
use function array_search;
use function mt_rand;

/**
 * Vanilla turtle eggs (1-4 per block): on sand they crack twice and hatch baby
 * turtles - mostly at night, always at dawn chance. Walking or landing on
 * them may break an egg (not while sneaking); zombies trample them on
 * purpose. Using a turtle egg on the block adds another egg.
 */
class VanillaTurtleEgg extends GenericBedrockBlock{

	private const COUNTS = ["one_egg", "two_egg", "three_egg", "four_egg"];

	public function getEggCount() : int{
		$tag = $this->getStateData()->getStates()["turtle_egg_count"] ?? null;
		$index = $tag === null ? 0 : array_search((string) $tag->getValue(), self::COUNTS, true);
		return ($index === false ? 0 : $index) + 1;
	}

	private function cracks() : string{
		$tag = $this->getStateData()->getStates()["cracked_state"] ?? null;
		return $tag === null ? "no_cracks" : (string) $tag->getValue();
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [new \pocketmine\math\AxisAlignedBB(0.1875, 0, 0.1875, 0.8125, 0.4375, 0.8125)];
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$egg = $item->getBlock();
		if(!$egg instanceof self || $this->getEggCount() >= 4){
			return false;
		}
		$this->position->getWorld()->setBlock($this->position, $this->withProperties(["turtle_egg_count" => self::COUNTS[$this->getEggCount()]]));
		$this->position->getWorld()->addSound($this->position, new VanillaSound(LevelSoundEvent::PLACE));
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$below = $this->getSide(Facing::DOWN);
		if($below->getTypeId() !== BlockTypeIds::SAND && $below->getTypeId() !== BlockTypeIds::RED_SAND){
			return;
		}
		$world = $this->position->getWorld();
		$time = $world->getTimeOfDay();
		$night = $time >= World::TIME_NIGHT || $time < 1000;
		if(!$night && mt_rand(0, 499) !== 0){
			return;
		}
		$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_TURTLE_EGG_CRACK));
		$state = $this->cracks();
		if($state !== "max_cracked"){
			$world->setBlock($this->position, $this->withProperties(["cracked_state" => $state === "no_cracks" ? "cracked" : "max_cracked"]));
			return;
		}
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_TURTLE_EGG_HATCH));
		for($i = $this->getEggCount(); $i > 0; --$i){
			$baby = new TurtleMob(Location::fromObject($this->position->add(0.3 + mt_rand(0, 4) / 10, 0, 0.3 + mt_rand(0, 4) / 10), $world, mt_rand(0, 359), 0));
			$baby->setBaby(true);
			$baby->spawnToAll();
		}
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$entity instanceof Living || $entity instanceof TurtleMob){
			return true;
		}
		if($entity instanceof Player && ($entity->isSneaking() || $entity->isSpectator())){
			return true;
		}
		$chance = $entity instanceof Zombie ? 3 : 100;
		if(mt_rand(0, $chance - 1) !== 0){
			return true;
		}
		$this->breakEgg();
		return false;
	}

	public function onEntityLand(Entity $entity) : ?float{
		if($entity instanceof Living && mt_rand(0, 2) === 0){
			$this->breakEgg();
		}
		return null;
	}

	private function breakEgg() : void{
		$world = $this->position->getWorld();
		$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_TURTLE_EGG_BREAK));
		$count = $this->getEggCount();
		$world->setBlock($this->position, $count <= 1 ? VanillaBlocks::AIR() : $this->withProperties(["turtle_egg_count" => self::COUNTS[$count - 2]]));
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItem()->setCount($this->getEggCount())];
	}
}
