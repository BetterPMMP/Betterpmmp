<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\CopperTrait;
use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\entity\CopperGolem;
use pocketmine\entity\Location;
use pocketmine\item\Axe;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

class CopperGolemStatue extends Opaque implements CopperMaterial, HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	use CopperTrait{
		onInteract as private onCopperInteract;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Axe && $this->isWaxed()){
			return $this->onCopperInteract($item, $face, $clickVector, $player, $returnedItems);
		}
		if($item instanceof Axe && !$this->isWaxed() && $this->getOxidation() === CopperOxidation::NONE){
			$world = $this->position->getWorld();
			$location = Location::fromObject($this->position->add(0.5, 1.0, 0.5), $world, 0.0, 0.0);
			$world->setBlock($this->position, VanillaBlocks::AIR());
			$golem = new CopperGolem($location);
			$golem->spawnToAll();
			$item->applyDamage(1);
			return true;
		}
		if($item instanceof Axe){
			return $this->onCopperInteract($item, $face, $clickVector, $player, $returnedItems);
		}
		$this->facing = match($this->facing){
			\pocketmine\math\Facing::NORTH => \pocketmine\math\Facing::EAST,
			\pocketmine\math\Facing::EAST => \pocketmine\math\Facing::SOUTH,
			\pocketmine\math\Facing::SOUTH => \pocketmine\math\Facing::WEST,
			default => \pocketmine\math\Facing::NORTH
		};
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}
}
