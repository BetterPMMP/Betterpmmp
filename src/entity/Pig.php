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

class Pig extends Animal implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "pig"; }
	public static function getNetworkTypeId() : string{ return EntityIds::PIG; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.9, 0.9); }
	public function getName() : string{ return "Pig"; }
	public function getDrops() : array{ return [VanillaItems::RAW_PORKCHOP()->setCount(mt_rand(1, 3))]; }
	public function getXpDropAmount() : int{ return mt_rand(1, 3); }
	public function getPickedItem() : ?Item{ return VanillaItems::PIG_SPAWN_EGG(); }
}
