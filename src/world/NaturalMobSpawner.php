<?php

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\BoggedMob;
use pocketmine\entity\PillagerMob;
use pocketmine\entity\BatMob;
use pocketmine\entity\BlazeMob;
use pocketmine\entity\CamelMob;
use pocketmine\entity\CaveSpiderMob;
use pocketmine\entity\Chicken;
use pocketmine\entity\CodMob;
use pocketmine\entity\Cow;
use pocketmine\entity\CreeperMob;
use pocketmine\entity\DolphinMob;
use pocketmine\entity\DrownedMob;
use pocketmine\entity\EndermanMob;
use pocketmine\entity\GhastMob;
use pocketmine\entity\Entity;
use pocketmine\entity\FoxMob;
use pocketmine\entity\FrogMob;
use pocketmine\entity\GoatMob;
use pocketmine\entity\HoglinMob;
use pocketmine\entity\Location;
use pocketmine\entity\MagmaCubeMob;
use pocketmine\entity\MooshroomMob;
use pocketmine\entity\Mob;
use pocketmine\entity\HorseMob;
use pocketmine\entity\ParchedMob;
use pocketmine\entity\PiglinMob;
use pocketmine\entity\Pig;
use pocketmine\entity\PufferfishMob;
use pocketmine\entity\RabbitMob;
use pocketmine\entity\SalmonMob;
use pocketmine\entity\SkeletonMob;
use pocketmine\entity\SlimeMob;
use pocketmine\entity\Sheep;
use pocketmine\entity\SpiderMob;
use pocketmine\entity\StrayMob;
use pocketmine\entity\Squid;
use pocketmine\entity\TurtleMob;
use pocketmine\entity\TropicalFishMob;
use pocketmine\entity\Villager;
use pocketmine\entity\WolfMob;
use pocketmine\entity\WitherSkeletonMob;
use pocketmine\entity\WardenMob;
use pocketmine\entity\ZombiePigmanMob;
use pocketmine\entity\Zombie;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function count;
use function mt_rand;
use function min;
use function max;

/**
 * Low-cost first pass at natural passive mob spawning. Only samples already
 * loaded terrain, so this never forces chunk generation from the world tick.
 */
final class NaturalMobSpawner{
	/** @var array<string, true> */
	private array $villageResidentChunks = [];
	/** @var array<int, \pocketmine\world\generator\vanilla\structure\StructureManager|null> */
	private array $structureManagers = [];
	/** @var int[] world id => server tick of the next patrol attempt */
	private array $nextPatrolTick = [];

	public function tick(World $world) : void{
		$eligiblePlayers = [];
		foreach($world->getPlayers() as $player){
			// Creative players still keep a world active for natural spawning; spectators do not.
			if($player->isConnected() && $player->isAlive() && !$player->isSpectator()){
				$eligiblePlayers[] = $player;
			}
		}
		if($eligiblePlayers === []){
			return;
		}
		$this->ensureVillageResidentsNearPlayers($world, $eligiblePlayers);
		$this->tickPillagers($world, $eligiblePlayers);
		if($world->getDimensionId() === DimensionIds::OVERWORLD){
			$this->spawnOverworldCategories($world, $eligiblePlayers);
		}

		$mobCount = 0;
		$overworld = $world->getDimensionId() === DimensionIds::OVERWORLD;
		foreach($world->getEntities() as $entity){
			if($overworld && self::categoryOf($entity) !== null){
				continue; // land mobs have their own vanilla caps
			}
			if(($entity instanceof Mob && $entity->isNaturalSpawned()) || $entity instanceof Squid){
				++$mobCount;
			}
		}
		// Keep mob growth bounded while allowing larger survival worlds more life.
		if($mobCount >= min(120, max(32, count($eligiblePlayers) * 32))){
			return;
		}

		$player = $eligiblePlayers[mt_rand(0, count($eligiblePlayers) - 1)];
		for($attempt = 0; $attempt < 8; ++$attempt){
			$x = $player->getPosition()->getFloorX() + mt_rand(-32, 32);
			$z = $player->getPosition()->getFloorZ() + mt_rand(-32, 32);
			$dx = $x + 0.5 - $player->getPosition()->x;
			$dz = $z + 0.5 - $player->getPosition()->z;
			if(($dx * $dx) + ($dz * $dz) < 12 * 12){
				continue;
			}

			$chunkX = $x >> 4;
			$chunkZ = $z >> 4;
			if(!$world->isChunkLoaded($chunkX, $chunkZ)){
				continue;
			}

			if($world->getDimensionId() === DimensionIds::NETHER){
				for($floorY = min($world->getMaxY() - 3, 110); $floorY >= max($world->getMinY() + 1, 1); --$floorY){
					$floor = $world->getBlockAt($x, $floorY, $z);
					if(!in_array($floor->getTypeId(), [BlockTypeIds::NETHERRACK, BlockTypeIds::SOUL_SAND, BlockTypeIds::SOUL_SOIL, BlockTypeIds::BASALT], true)){
						continue;
					}
					$feet = $world->getBlockAt($x, $floorY + 1, $z);
					$head = $world->getBlockAt($x, $floorY + 2, $z);
					if($feet->isSolid() || $head->isSolid() || $feet->getTypeId() === BlockTypeIds::LAVA || $head->getTypeId() === BlockTypeIds::LAVA){
						continue;
					}
					$classes = [ZombiePigmanMob::class, ZombiePigmanMob::class, MagmaCubeMob::class, WitherSkeletonMob::class, BlazeMob::class, PiglinMob::class, HoglinMob::class];
					// Ghasts need a broad open volume; only select them when the sampled column is high and clear.
					if($floorY > 70 && !$world->getBlockAt($x + 1, $floorY + 2, $z)->isSolid() && !$world->getBlockAt($x - 1, $floorY + 2, $z)->isSolid()){
						$classes[] = GhastMob::class;
					}
					$class = $classes[mt_rand(0, count($classes) - 1)];
					$this->spawnNaturalMob($class, new Location($x + 0.5, $floorY + 1.0, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0));
					return;
				}
				continue;
			}
			if($world->getDimensionId() === DimensionIds::THE_END){
				for($floorY = min($world->getMaxY() - 3, 120); $floorY >= max($world->getMinY() + 1, 1); --$floorY){
					$floor = $world->getBlockAt($x, $floorY, $z);
					if($floor->getTypeId() !== BlockTypeIds::END_STONE){
						continue;
					}
					$feet = $world->getBlockAt($x, $floorY + 1, $z);
					$head = $world->getBlockAt($x, $floorY + 2, $z);
					if(!$feet->isSolid() && !$head->isSolid()){
						$this->spawnNaturalMob(EndermanMob::class, new Location($x + 0.5, $floorY + 1.0, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0));
						return;
					}
				}
				continue;
			}

			$surfaceY = $world->getHighestBlockAt($x, $z);
			if($surfaceY === null || $surfaceY < $world->getMinY() || $surfaceY + 2 >= $world->getMaxY()){
				continue;
			}

			// Bedrock's highest-block lookup returns the top water block too. Find
			// that water surface explicitly and spawn fish inside the water column.
			$waterSurfaceY = null;
			for($scanY = $surfaceY; $scanY >= max($world->getMinY() + 2, $surfaceY - 8); --$scanY){
				if($world->getBlockAt($x, $scanY, $z)->getTypeId() !== BlockTypeIds::WATER || $world->getBlockAt($x, $scanY + 1, $z)->getTypeId() === BlockTypeIds::WATER){
					continue;
				}
				if($world->getBlockAt($x, $scanY - 1, $z)->getTypeId() === BlockTypeIds::WATER){
					$waterSurfaceY = $scanY;
					break;
				}
			}
			if($waterSurfaceY !== null){
				$biome = $world->getBiomeId($x, $waterSurfaceY, $z);
				$oceanBiomes = [BiomeIds::OCEAN, BiomeIds::DEEP_OCEAN, BiomeIds::WARM_OCEAN, BiomeIds::DEEP_WARM_OCEAN, BiomeIds::LUKEWARM_OCEAN, BiomeIds::DEEP_LUKEWARM_OCEAN, BiomeIds::COLD_OCEAN, BiomeIds::DEEP_COLD_OCEAN];
				$coldOceanBiomes = [BiomeIds::COLD_OCEAN, BiomeIds::DEEP_COLD_OCEAN, BiomeIds::FROZEN_OCEAN, BiomeIds::DEEP_FROZEN_OCEAN, BiomeIds::FROZEN_RIVER];
				$warmOceanBiomes = [BiomeIds::WARM_OCEAN, BiomeIds::DEEP_WARM_OCEAN, BiomeIds::LUKEWARM_OCEAN, BiomeIds::DEEP_LUKEWARM_OCEAN];
				$classes = [CodMob::class, CodMob::class, CodMob::class, CodMob::class, Squid::class];
				if(in_array($biome, $coldOceanBiomes, true)){
					$classes[] = SalmonMob::class;
					$classes[] = SalmonMob::class;
					$classes[] = SalmonMob::class;
				}
				if(in_array($biome, $warmOceanBiomes, true)){
					$classes[] = TropicalFishMob::class;
					$classes[] = TropicalFishMob::class;
					$classes[] = TropicalFishMob::class;
					$classes[] = PufferfishMob::class;
				}
				if(in_array($biome, $oceanBiomes, true)){
					$classes[] = DolphinMob::class;
				}
				if(in_array($biome, [...$oceanBiomes, BiomeIds::RIVER, BiomeIds::FROZEN_RIVER], true) && $waterSurfaceY - $world->getMinY() > 5 && $world->getFullLightAt($x, $waterSurfaceY - 1, $z) <= 7){
					$classes[] = DrownedMob::class;
				}
				$class = $classes[mt_rand(0, count($classes) - 1)];
				$location = new Location($x + 0.5, $waterSurfaceY - 1.5, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0);
				if(in_array($class, [CodMob::class, SalmonMob::class, TropicalFishMob::class, PufferfishMob::class], true)){
					$this->spawnFishSchool($class, $location);
				}else{
					$this->spawnNaturalMob($class, $location);
				}
				return;
			}

			// land spawning in the overworld is handled by spawnOverworldCategories()
			continue;
		}
	}

	/** Vanilla mob category of an entity, or null for mobs without a land cap (water mobs, bosses, ...). */
	private static function categoryOf(Entity $entity) : ?int{
		if($entity instanceof \pocketmine\entity\BatMob){
			return VanillaSpawnTables::CATEGORY_AMBIENT;
		}
		if($entity instanceof \pocketmine\entity\GenericHostileMob || $entity instanceof Zombie){
			return VanillaSpawnTables::CATEGORY_MONSTER;
		}
		if($entity instanceof \pocketmine\entity\Animal || ($entity instanceof \pocketmine\entity\GenericAnimalMob && !$entity instanceof \pocketmine\entity\IronGolemMob && !$entity instanceof \pocketmine\entity\SnowGolemMob)){
			return VanillaSpawnTables::CATEGORY_CREATURE;
		}
		return null;
	}

	/**
	 * Vanilla-style natural spawning for monsters, animals and bats: each
	 * category has its own per-player cap, mobs come in groups picked from the
	 * biome's weighted spawn list, monsters need darkness (block light 0, sky
	 * light 7 or less), animals need daylight on grass, bats need dark caves.
	 * Mobs never spawn within 24 blocks of a player.
	 *
	 * @param Player[] $players
	 */
	private function spawnOverworldCategories(World $world, array $players) : void{
		$counts = [VanillaSpawnTables::CATEGORY_MONSTER => 0, VanillaSpawnTables::CATEGORY_CREATURE => 0, VanillaSpawnTables::CATEGORY_AMBIENT => 0];
		foreach($world->getEntities() as $entity){
			$category = self::categoryOf($entity);
			if($category === null || !$entity->isAlive()){
				continue;
			}
			foreach($players as $player){
				if($player->getPosition()->distanceSquared($entity->getPosition()) <= 128 * 128){
					++$counts[$category];
					break;
				}
			}
		}
		$peaceful = $world->getDifficulty() === World::DIFFICULTY_PEACEFUL;
		foreach($players as $player){
			foreach(VanillaSpawnTables::CAPS as $category => $cap){
				if($counts[$category] >= $cap * count($players)){
					continue;
				}
				if($category === VanillaSpawnTables::CATEGORY_MONSTER && $peaceful){
					continue;
				}
				// vanilla tries monsters every tick, animals every 400 ticks; this runs every 10 ticks
				$packs = match($category){
					VanillaSpawnTables::CATEGORY_MONSTER => 3,
					VanillaSpawnTables::CATEGORY_CREATURE => mt_rand(0, 39) === 0 ? 1 : 0,
					default => mt_rand(0, 3) === 0 ? 1 : 0,
				};
				for($i = 0; $i < $packs; ++$i){
					$counts[$category] += $this->spawnPack($world, $player, $category, $players);
				}
			}
		}
	}

	/**
	 * @param Player[] $players
	 * @return int number of mobs spawned
	 */
	private function spawnPack(World $world, Player $player, int $category, array $players) : int{
		$center = $player->getPosition();
		$x = $center->getFloorX() + mt_rand(-64, 64);
		$z = $center->getFloorZ() + mt_rand(-64, 64);
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return 0;
		}
		$top = $world->getHighestBlockAt($x, $z);
		if($top === null){
			return 0;
		}
		$y = $category === VanillaSpawnTables::CATEGORY_CREATURE ? $top + 1 : mt_rand($world->getMinY() + 1, $top + 1);
		$y = $this->findFloor($world, $x, $y, $z, 16);
		if($y === null){
			return 0;
		}
		$biome = $world->getBiomeId($x, $y, $z);
		$entries = match($category){
			VanillaSpawnTables::CATEGORY_MONSTER => VanillaSpawnTables::monsters($biome),
			VanillaSpawnTables::CATEGORY_CREATURE => VanillaSpawnTables::creatures($biome),
			default => VanillaSpawnTables::ambient($biome),
		};
		$entry = VanillaSpawnTables::pick($entries);
		if($entry === null){
			return 0;
		}
		[$class, , $min, $max] = $entry;
		$groupSize = mt_rand($min, $max);
		$spawned = 0;
		for($attempt = 0; $attempt < 4 * $groupSize && $spawned < $groupSize; ++$attempt){
			$sx = $x + mt_rand(-5, 5);
			$sz = $z + mt_rand(-5, 5);
			if(!$world->isChunkLoaded($sx >> 4, $sz >> 4)){
				continue;
			}
			$sy = $this->findFloor($world, $sx, $y + 1, $sz, 3);
			if($sy === null || !$this->farFromPlayers($players, $sx + 0.5, $sy, $sz + 0.5)){
				continue;
			}
			if(!$this->canSpawnHere($world, $class, $category, $sx, $sy, $sz)){
				continue;
			}
			$this->spawnNaturalMob($class, new Location($sx + 0.5, $sy, $sz + 0.5, $world, (float) mt_rand(0, 359), 0.0));
			++$spawned;
		}
		return $spawned;
	}

	/** First standable feet position at or below $y (within $depth blocks). */
	private function findFloor(World $world, int $x, int $y, int $z, int $depth) : ?int{
		$min = max($world->getMinY() + 1, $y - $depth);
		for($fy = min($y, $world->getMaxY() - 2); $fy >= $min; --$fy){
			$floor = $world->getBlockAt($x, $fy - 1, $z);
			if(!$floor->isSolid()){
				continue;
			}
			$feet = $world->getBlockAt($x, $fy, $z);
			$head = $world->getBlockAt($x, $fy + 1, $z);
			if($feet->isSolid() || $head->isSolid() || $feet instanceof \pocketmine\block\Liquid || $head instanceof \pocketmine\block\Liquid){
				return null;
			}
			return $fy;
		}
		return null;
	}

	/** @param Player[] $players */
	private function farFromPlayers(array $players, float $x, float $y, float $z) : bool{
		foreach($players as $player){
			$pos = $player->getPosition();
			if(($pos->x - $x) ** 2 + ($pos->y - $y) ** 2 + ($pos->z - $z) ** 2 < 24 * 24){
				return false;
			}
		}
		return true;
	}

	/** @param class-string $class */
	private function canSpawnHere(World $world, string $class, int $category, int $x, int $y, int $z) : bool{
		$floor = $world->getBlockAt($x, $y - 1, $z);
		$floorId = $floor->getTypeId();
		if($floorId === BlockTypeIds::BEDROCK || $floorId === BlockTypeIds::BARRIER || $floor->isTransparent()){
			return false;
		}
		$blockLight = $world->getBlockLightAt($x, $y, $z);
		$skyLight = $world->getRealBlockSkyLightAt($x, $y, $z);
		switch($category){
			case VanillaSpawnTables::CATEGORY_MONSTER:
				if($blockLight > 0 || $skyLight > mt_rand(0, 7)){
					return false;
				}
				if($class === SlimeMob::class){
					// swamps at night near sea level, or slime chunks deep underground
					$swamp = VanillaSpawnTables::isSwamp($world->getBiomeId($x, $y, $z)) && $y > 50 && $y < 70 && mt_rand(0, 1) === 0;
					$slimeChunk = $y < 40 && VanillaSpawnTables::isSlimeChunk($world->getSeed(), $x >> 4, $z >> 4) && mt_rand(0, 9) === 0;
					if(!$swamp && !$slimeChunk){
						return false;
					}
				}
				if($class === \pocketmine\entity\EndermanMob::class && $world->getBlockAt($x, $y + 2, $z)->isSolid()){
					return false; // endermen are 3 blocks tall
				}
				return true;
			case VanillaSpawnTables::CATEGORY_CREATURE:
				if(max($blockLight, $skyLight) < 9){
					return false;
				}
				return match($class){
					TurtleMob::class, CamelMob::class => in_array($floorId, [BlockTypeIds::SAND, BlockTypeIds::RED_SAND], true),
					MooshroomMob::class => $floorId === BlockTypeIds::MYCELIUM,
					GoatMob::class => in_array($floorId, [BlockTypeIds::STONE, BlockTypeIds::SNOW, BlockTypeIds::GRASS, BlockTypeIds::PACKED_ICE, BlockTypeIds::GRAVEL], true),
					\pocketmine\entity\RabbitMob::class, \pocketmine\entity\PolarBearMob::class => in_array($floorId, [BlockTypeIds::GRASS, BlockTypeIds::SAND, BlockTypeIds::SNOW, BlockTypeIds::ICE, BlockTypeIds::PACKED_ICE], true),
					FrogMob::class => in_array($floorId, [BlockTypeIds::GRASS, BlockTypeIds::MUD], true),
					\pocketmine\entity\ArmadilloMob::class => in_array($floorId, [BlockTypeIds::GRASS, BlockTypeIds::RED_SAND, BlockTypeIds::HARDENED_CLAY, BlockTypeIds::STAINED_CLAY, BlockTypeIds::DIRT], true),
					\pocketmine\entity\ParrotMob::class, \pocketmine\entity\OcelotMob::class => $floorId === BlockTypeIds::GRASS || $floor instanceof \pocketmine\block\Leaves,
					default => $floorId === BlockTypeIds::GRASS,
				};
			default:
				// bats: dark caves below sea level
				return $y < 63 && max($blockLight, $skyLight) <= mt_rand(0, 3);
		}
	}

	/** Villagers are placed when a player first loads the village chunk. */
	private function ensureVillageResidentsNearPlayers(World $world, array $players) : void{
		foreach($players as $player){
			$chunkX = $player->getPosition()->getFloorX() >> 4;
			$chunkZ = $player->getPosition()->getFloorZ() >> 4;
			$key = $world->getId() . ":" . World::chunkHash($chunkX, $chunkZ);
			if(isset($this->villageResidentChunks[$key])){
				continue;
			}
			$chunk = $world->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				continue;
			}
			$this->villageResidentChunks[$key] = true;
			$baseX = $chunkX << 4;
			$baseZ = $chunkZ << 4;
			for($localX = 0; $localX < 16; ++$localX){
				for($localZ = 0; $localZ < 16; ++$localZ){
					$surfaceY = $chunk->getHighestBlockAt($localX, $localZ);
					if($surfaceY === null){
						continue;
					}
					$markerY = null;
					for($scanY = max($world->getMinY(), $surfaceY - 8); $scanY <= min($world->getMaxY() - 1, $surfaceY + 2); ++$scanY){
						if($world->getBlockAt($baseX + $localX, $scanY, $baseZ + $localZ)->getTypeId() === BlockTypeIds::HAY_BALE){
							$markerY = $scanY;
							break;
						}
					}
					if($markerY === null){
						continue;
					}
					$baseY = $markerY - 1;
					$existing = 0;
					foreach($world->getEntities() as $entity){
						if($entity instanceof Villager && $entity->getPosition()->distanceSquared(new \pocketmine\math\Vector3($baseX + 8, $baseY + 1, $baseZ + 8)) <= 24 * 24){
							++$existing;
						}
					}
					// Residents spawn on free, walkable spots around the meeting point
					// (works for the legacy corner layout and the vanilla-style villages).
					$markerX = $baseX + $localX;
					$markerZ = $baseZ + $localZ;
					$spawned = $existing;
					for($attempt = 0; $attempt < 48 && $spawned < 6; ++$attempt){
						$sx = $markerX + mt_rand(-16, 16);
						$sz = $markerZ + mt_rand(-16, 16);
						if(!$world->isChunkLoaded($sx >> 4, $sz >> 4)){
							continue;
						}
						for($sy = $markerY + 6; $sy >= $markerY - 6; --$sy){
							if($sy <= $world->getMinY() || $sy + 1 >= $world->getMaxY()){
								continue;
							}
							$floor = $world->getBlockAt($sx, $sy - 1, $sz);
							if($floor->isSolid() && !$world->getBlockAt($sx, $sy, $sz)->isSolid() && !$world->getBlockAt($sx, $sy + 1, $sz)->isSolid()
								&& $world->getBlockAt($sx, $sy, $sz)->getTypeId() !== BlockTypeIds::WATER && $world->getBlockAt($sx, $sy, $sz)->getTypeId() !== BlockTypeIds::LAVA){
								$villager = new Villager(new Location($sx + 0.5, $sy, $sz + 0.5, $world, (float) mt_rand(0, 359), 0.0));
								$villager->spawnToAll();
								++$spawned;
								break;
							}
						}
					}
					return;
				}
			}
		}
	}

	/**
	 * Structure locations of a world made with the BetterPMMP vanilla generator
	 * (the same deterministic layout the generator used).
	 */
	private function structuresFor(World $world) : ?\pocketmine\world\generator\vanilla\structure\StructureManager{
		$id = $world->getId();
		if(!array_key_exists($id, $this->structureManagers)){
			$manager = null;
			try{
				if($world->getDimensionId() === DimensionIds::OVERWORLD && strtolower($world->getProvider()->getWorldData()->getGenerator()) === "vanilla"){
					$manager = (new \pocketmine\world\generator\vanilla\Overworld($world->getSeed(), ""))->getStructureManager();
				}
			}catch(\Throwable){
				$manager = null;
			}
			$this->structureManagers[$id] = $manager;
		}
		return $this->structureManagers[$id];
	}

	/** Ground position for a mob in a column: solid floor with two free blocks above. */
	private function surfaceSpawnPoint(World $world, int $x, int $z) : ?Location{
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return null;
		}
		$y = $world->getHighestBlockAt($x, $z);
		if($y === null || $y + 2 >= $world->getMaxY()){
			return null;
		}
		$floor = $world->getBlockAt($x, $y, $z);
		if(!$floor->isSolid() || $floor->getTypeId() === BlockTypeIds::WATER || $floor->getTypeId() === BlockTypeIds::LAVA || $floor instanceof \pocketmine\block\Leaves){
			return null;
		}
		if($world->getBlockAt($x, $y + 1, $z)->isSolid() || $world->getBlockAt($x, $y + 2, $z)->isSolid()){
			return null;
		}
		return new Location($x + 0.5, $y + 1, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0);
	}

	/**
	 * Pillagers, like vanilla: they keep spawning inside pillager outposts and,
	 * from the fifth day on, patrols of 2-5 pillagers roam near players.
	 * @param Player[] $players
	 */
	private function tickPillagers(World $world, array $players) : void{
		if($world->getDimensionId() !== DimensionIds::OVERWORLD || count($players) === 0){
			return;
		}
		$player = $players[mt_rand(0, count($players) - 1)];
		$center = $player->getPosition();

		//outposts
		$structures = $this->structuresFor($world);
		if($structures !== null && mt_rand(0, 3) === 0){
			$x = $center->getFloorX() + mt_rand(-48, 48);
			$z = $center->getFloorZ() + mt_rand(-48, 48);
			if($world->isChunkLoaded($x >> 4, $z >> 4)){
				foreach($structures->startsIn($x >> 4, $z >> 4) as $start){
					if($start->type !== "pillager_outpost" || !$start->containsColumn($x, $z)){
						continue;
					}
					$cx = ($start->minX + $start->maxX) / 2;
					$cz = ($start->minZ + $start->maxZ) / 2;
					$nearby = 0;
					foreach($world->getEntities() as $entity){
						if($entity instanceof PillagerMob && ($entity->getPosition()->x - $cx) ** 2 + ($entity->getPosition()->z - $cz) ** 2 < 48 * 48){
							++$nearby;
						}
					}
					if($nearby < 6 && ($location = $this->surfaceSpawnPoint($world, $x, $z)) !== null){
						$this->spawnNaturalMob(PillagerMob::class, $location);
					}
					break;
				}
			}
		}

		//patrols
		$id = $world->getId();
		$tick = $world->getServer()->getTick();
		$this->nextPatrolTick[$id] ??= $tick + 12000 + mt_rand(0, 1200);
		if($tick < $this->nextPatrolTick[$id]){
			return;
		}
		$this->nextPatrolTick[$id] = $tick + 12000 + mt_rand(0, 1200);
		if($world->getTime() < 5 * World::TIME_FULL || mt_rand(0, 4) !== 0){
			return; //vanilla: no patrols before day 5, then 20% per attempt
		}
		$angle = mt_rand(0, 359) / 180 * M_PI;
		$distance = mt_rand(24, 48);
		$x = (int) ($center->x + cos($angle) * $distance);
		$z = (int) ($center->z + sin($angle) * $distance);
		if($world->getBiomeId($x, $center->getFloorY(), $z) === \pocketmine\data\bedrock\BiomeIds::MUSHROOM_ISLAND){
			return;
		}
		$size = 2 + mt_rand(0, 3);
		for($i = 0; $i < $size; ++$i){
			$location = $this->surfaceSpawnPoint($world, $x + mt_rand(-3, 3), $z + mt_rand(-3, 3));
			if($location !== null){
				$this->spawnNaturalMob(PillagerMob::class, $location);
			}
		}
	}

	/** Spawns a small school instead of a lone fish. */
	private function spawnFishSchool(string $class, Location $origin) : void{
		$world = $origin->getWorld();
		$centerX = $origin->getFloorX();
		$centerY = $origin->getFloorY();
		$centerZ = $origin->getFloorZ();
		$count = mt_rand(3, 5);
		for($i = 0; $i < $count; ++$i){
			for($attempt = 0; $attempt < 10; ++$attempt){
				$x = $i === 0 ? $centerX : $centerX + mt_rand(-4, 4);
				$y = $centerY + ($i === 0 ? 0 : mt_rand(-1, 1));
				$z = $i === 0 ? $centerZ : $centerZ + mt_rand(-4, 4);
				if(!$world->isChunkLoaded($x >> 4, $z >> 4) || $world->getBlockAt($x, $y, $z)->getTypeId() !== BlockTypeIds::WATER){
					continue;
				}
				$this->spawnNaturalMob($class, new Location($x + 0.5, $y + 0.1, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0));
				break;
			}
		}
	}

	/** @param class-string<Entity> $class */
	private function spawnNaturalMob(string $class, Location $location) : void{
		$mob = new $class($location);
		if($mob instanceof Entity){
			if($mob instanceof Mob || $mob instanceof Squid){
				$mob->markNaturalSpawned();
			}
			$mob->spawnToAll();
		}
	}
}
