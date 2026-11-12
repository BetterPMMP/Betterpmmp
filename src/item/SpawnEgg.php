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

use pocketmine\block\Block;
use pocketmine\block\MonsterSpawner;
use pocketmine\block\tile\MonsterSpawner as TileMonsterSpawner;
use pocketmine\entity\Entity;
use pocketmine\entity\VanillaMobTypes;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Utils;
use pocketmine\world\World;

abstract class SpawnEgg extends Item{

	abstract protected function createEntity(World $world, Vector3 $pos, float $yaw, float $pitch) : Entity;

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		//BetterPMMP: a spawn egg on a trial spawner sets its mob
		if($blockClicked instanceof \pocketmine\block\VanillaTrialSpawner && ($trialId = $this->spawnerEntityId()) !== null){
			$player->getWorld()->getTrialManager()->setSpawnerMob($blockClicked->getPosition(), $trialId);
			if($player->hasFiniteResources()){
				$this->pop();
			}
			return ItemUseResult::SUCCESS;
		}
		if($blockClicked instanceof MonsterSpawner && ($tile = $player->getWorld()->getTile($blockClicked->getPosition())) instanceof TileMonsterSpawner){
			$baseEggs = [
				VanillaItems::PIG_SPAWN_EGG()->getTypeId() => "minecraft:pig",
				VanillaItems::COW_SPAWN_EGG()->getTypeId() => "minecraft:cow",
				VanillaItems::CHICKEN_SPAWN_EGG()->getTypeId() => "minecraft:chicken",
				VanillaItems::SHEEP_SPAWN_EGG()->getTypeId() => "minecraft:sheep",
				VanillaItems::ZOMBIE_SPAWN_EGG()->getTypeId() => "minecraft:zombie",
				VanillaItems::VILLAGER_SPAWN_EGG()->getTypeId() => "minecraft:villager",
				VanillaItems::SQUID_SPAWN_EGG()->getTypeId() => "minecraft:squid"
			];
			$entityTypeId = $baseEggs[$this->getTypeId()] ?? null;
			if($entityTypeId === null){
				foreach(VanillaMobTypes::additional() as $definition){
					$getter = $definition["getter"];
					if(VanillaItems::$getter()->getTypeId() === $this->getTypeId()){
						$entityTypeId = $definition["id"];
						break;
					}
				}
			}
			if($entityTypeId !== null){
				$tile->setEntityTypeId($entityTypeId);
				if($player->hasFiniteResources()){
					$this->pop(); //BetterPMMP: the egg is used up like in vanilla
				}
				return ItemUseResult::SUCCESS;
			}
		}
		$entity = $this->createEntity($player->getWorld(), $blockReplace->getPosition()->add(0.5, 0, 0.5), Utils::getRandomFloat() * 360, 0);

		if($this->hasCustomName()){
			$entity->setNameTag($this->getCustomName());
		}
		$this->pop();
		$entity->spawnToAll();
		//TODO: what if the entity was marked for deletion?
		return ItemUseResult::SUCCESS;
	}

	/** Entity id this egg puts into a spawner, or null. */
	private function spawnerEntityId() : ?string{
		$baseEggs = [
			VanillaItems::PIG_SPAWN_EGG()->getTypeId() => "minecraft:pig",
			VanillaItems::COW_SPAWN_EGG()->getTypeId() => "minecraft:cow",
			VanillaItems::CHICKEN_SPAWN_EGG()->getTypeId() => "minecraft:chicken",
			VanillaItems::SHEEP_SPAWN_EGG()->getTypeId() => "minecraft:sheep",
			VanillaItems::ZOMBIE_SPAWN_EGG()->getTypeId() => "minecraft:zombie",
			VanillaItems::VILLAGER_SPAWN_EGG()->getTypeId() => "minecraft:villager",
				VanillaItems::SQUID_SPAWN_EGG()->getTypeId() => "minecraft:squid"
		];
		if(isset($baseEggs[$this->getTypeId()])){
			return $baseEggs[$this->getTypeId()];
		}
		foreach(VanillaMobTypes::additional() as $definition){
			$getter = $definition["getter"];
			if(VanillaItems::$getter()->getTypeId() === $this->getTypeId()){
				return $definition["id"];
			}
		}
		return null;
	}
}
