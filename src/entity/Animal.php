<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;

/** Passive animals flee from attackers and share feeding/breeding behavior. */
abstract class Animal extends Mob implements BreedableAnimal{
	use AnimalBreedingTrait;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->initBreedingState($nbt);
	}

	public function saveNBT() : CompoundTag{
		return $this->saveBreedingState(parent::saveNBT());
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			$this->tickBreedingState($tickDiff);
		}
		return $hasUpdate;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncBreedingNetworkData($properties);
	}

	protected function shouldFleeFromAttacker(EntityDamageByEntityEvent $source) : bool{
		return true;
	}

	protected function tickWandering(int $tickDiff) : void{
		if($this->isPanicking() || !$this->tickAnimalGoals($tickDiff)){
			parent::tickWandering($tickDiff);
		}
	}
}
