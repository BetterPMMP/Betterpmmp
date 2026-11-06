<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\item\Item;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Initial native implementation for the Bedrock Copper Golem entity.
 * Oxidation, chest sorting and statue conversion are separate gameplay work.
 */
class CopperGolem extends Mob{
	public static function getNetworkTypeId() : string{ return EntityIds::COPPER_GOLEM; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.9, 0.6);
	}

	public function getName() : string{
		return "Copper Golem";
	}

	public function getPickedItem() : ?Item{
		return VanillaBlocks::COPPER_GOLEM_STATUE();
	}
}
