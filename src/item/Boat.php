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

use function array_search;

class Boat extends Item{
	private BoatType $boatType;

	public function __construct(ItemIdentifier $identifier, string $name, BoatType $boatType){
		parent::__construct($identifier, $name);
		$this->boatType = $boatType;
	}

	public function getType() : BoatType{
		return $this->boatType;
	}

	public function getFuelTime() : int{
		return 1200; //400 in PC
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function onClickAir(\pocketmine\player\Player $player, \pocketmine\math\Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$variant = array_search($this->boatType, BoatType::cases(), true);
		if(\pocketmine\entity\object\BoatEntity::placeFromItem($player, $variant === false ? 0 : $variant, false)){
			$this->pop();
			return ItemUseResult::SUCCESS;
		}
		return ItemUseResult::NONE;
	}

	public function onInteractBlock(\pocketmine\player\Player $player, \pocketmine\block\Block $blockReplace, \pocketmine\block\Block $blockClicked, int $face, \pocketmine\math\Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		return $this->onClickAir($player, $player->getDirectionVector(), $returnedItems);
	}
}
