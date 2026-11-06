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
use pocketmine\item\Bucket;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function mt_rand;

class Cow extends Animal{
	public static function getNetworkTypeId() : string{ return EntityIds::COW; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.4, 0.9); }
	public function getName() : string{ return "Cow"; }
	public function getDrops() : array{
		$drops = [VanillaItems::RAW_BEEF()->setCount(mt_rand(1, 3))];
		$leather = mt_rand(0, 2);
		if($leather > 0){ $drops[] = VanillaItems::LEATHER()->setCount($leather); }
		return $drops;
	}
	public function getXpDropAmount() : int{ return mt_rand(1, 3); }
	public function getPickedItem() : ?Item{ return VanillaItems::COW_SPAWN_EGG(); }

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if(!$this->isBaby() && $held instanceof Bucket){
			$milk = VanillaItems::MILK_BUCKET();
			if(!$player->hasFiniteResources()){
				$this->addMilkBucket($player, $milk);
			}elseif($held->getCount() > 1){
				$held->setCount($held->getCount() - 1);
				$player->getInventory()->setItemInHand($held);
				$this->addMilkBucket($player, $milk);
			}else{
				$player->getInventory()->setItemInHand($milk);
			}
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}

	private function addMilkBucket(Player $player, Item $milk) : void{
		foreach($player->getInventory()->addItem($milk) as $leftover){
			$this->getWorld()->dropItem($player->getPosition(), $leftover);
		}
	}
}
