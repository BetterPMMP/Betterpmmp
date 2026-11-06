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

namespace pocketmine\block;

use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

class EndPortalFrame extends Opaque implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	protected bool $eye = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->eye);
	}

	public function hasEye() : bool{ return $this->eye; }

	/** @return $this */
	public function setEye(bool $eye) : self{
		$this->eye = $eye;
		return $this;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->eye || $player === null || $item->getTypeId() !== ItemTypeIds::ENDER_EYE){
			return false;
		}
		$this->eye = true;
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this);
		// Eyes are not consumed in Creative mode, matching vanilla gameplay.
		if(!$player->isCreative()){
			$item->pop();
		}
		$this->tryActivatePortal();
		return true;
	}

	private function tryActivatePortal() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($centerX = $x - 2; $centerX <= $x + 2; ++$centerX){
			for($centerZ = $z - 2; $centerZ <= $z + 2; ++$centerZ){
				$complete = true;
				for($dx = -2; $dx <= 2 && $complete; ++$dx){
					for($dz = -2; $dz <= 2; ++$dz){
						if(abs($dx) !== 2 && abs($dz) !== 2){
							continue;
						}
						if(abs($dx) === 2 && abs($dz) === 2){
							continue;
						}
						$frame = $world->getBlockAt($centerX + $dx, $y, $centerZ + $dz);
						// Bedrock clients can report frame facing differently across protocol
						// revisions. Portal activation depends on the 12 eyed frames being in
						// the ring; rejecting them by the translated facing leaves a complete
						// vanilla frame inert on some clients.
						if(!$frame instanceof self || !$frame->hasEye()){
							$complete = false;
							break;
						}
					}
				}
				if(!$complete){
					continue;
				}
				for($dx = -1; $dx <= 1; ++$dx){
					for($dz = -1; $dz <= 1; ++$dz){
						$world->setBlockAt($centerX + $dx, $y, $centerZ + $dz, VanillaBlocks::END_PORTAL());
					}
				}
				return;
			}
		}
	}

	public function getLightLevel() : int{
		return 1;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 3 / 16)];
	}
}
