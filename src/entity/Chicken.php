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
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

class Chicken extends Animal{
	public static function getNetworkTypeId() : string{ return EntityIds::CHICKEN; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.7, 0.4); }
	public function getName() : string{ return "Chicken"; }
	public function getDrops() : array{
		$drops = [VanillaItems::RAW_CHICKEN()->setCount(mt_rand(1, 2))];
		$feathers = mt_rand(0, 2);
		if($feathers > 0){ $drops[] = VanillaItems::FEATHER()->setCount($feathers); }
		return $drops;
	}
	public function getXpDropAmount() : int{ return mt_rand(1, 3); }
	public function getPickedItem() : ?Item{ return VanillaItems::CHICKEN_SPAWN_EGG(); }
}
