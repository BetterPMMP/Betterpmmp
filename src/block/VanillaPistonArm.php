<?php

/*
 * BetterPMMP - piston head collision block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;

/** The invisible collision block in front of an extended piston; the head itself is drawn by the PistonArm tile. */
class VanillaPistonArm extends GenericBedrockBlock{

	public function getFacing() : int{
		return VanillaPiston::facingFromBedrock((int) $this->getStateData()->getStates()["facing_direction"]->getValue());
	}

	/** @return $this */
	public function setFacing(int $facing) : self{
		return $this->setStateIndex($this->withProperties(["facing_direction" => VanillaPiston::facingToBedrock($facing)])->getStateIndex());
	}

	protected function recalculateCollisionBoxes() : array{
		$facing = $this->getFacing();
		// head plate (4px) plus the rod reaching back into the piston base
		return [AxisAlignedBB::one()->trim(Facing::opposite($facing), 12 / 16)];
	}

	public function isSolid() : bool{
		return false;
	}

	public function isTransparent() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	private function piston() : ?VanillaPiston{
		$base = $this->position->getWorld()->getBlock($this->position->getSide(Facing::opposite($this->getFacing())));
		return $base instanceof VanillaPiston && $base->getFacing() === $this->getFacing() ? $base : null;
	}

	public function onNearbyBlockChange() : void{
		// a head without its piston disappears
		if($this->piston() === null){
			$this->position->getWorld()->setBlock($this->position, VanillaBlocks::AIR());
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$piston = $this->piston();
		$result = parent::onBreak($item, $player, $returnedItems);
		if($piston !== null){
			// breaking the head breaks the piston (it drops, unless in creative)
			$this->position->getWorld()->useBreakOn($piston->getPosition(), $item, $player, true, $returnedItems);
		}
		return $result;
	}
}
