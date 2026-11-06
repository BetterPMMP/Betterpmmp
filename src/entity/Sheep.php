<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use function mt_rand;

class Sheep extends Animal{
	private const TAG_SHEARED = "Sheared";
	private bool $sheared = false;

	public static function getNetworkTypeId() : string{ return EntityIds::SHEEP; }
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->sheared = $nbt->getByte(self::TAG_SHEARED, 0) !== 0; }
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setByte(self::TAG_SHEARED, $this->sheared ? 1 : 0); }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.3, 0.9); }
	public function getName() : string{ return "Sheep"; }
	public function getDrops() : array{ return [VanillaItems::RAW_MUTTON()->setCount(mt_rand(1, 2))]; }
	public function getXpDropAmount() : int{ return mt_rand(1, 3); }
	public function getPickedItem() : ?Item{ return VanillaItems::SHEEP_SPAWN_EGG(); }

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if(!$this->isBaby() && !$this->sheared && $held instanceof Shears){
			$this->sheared = true;
			$this->networkPropertiesDirty = true;
			$this->getWorld()->dropItem($this->getPosition(), VanillaBlocks::WOOL()->asItem()->setCount(mt_rand(1, 3)));
			if($player->hasFiniteResources()){
				$held->applyDamage(1);
				$player->getInventory()->setItemInHand($held->isBroken() ? VanillaItems::AIR() : $held);
			}
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->sheared);
	}
}
