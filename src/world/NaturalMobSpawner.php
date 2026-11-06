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

		$mobCount = 0;
		foreach($world->getEntities() as $entity){
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

			// Sample a low-light underground pocket separately from surface spawns.
			if($surfaceY - $world->getMinY() > 12 && mt_rand(1, 4) === 1){
				$caveY = mt_rand($world->getMinY() + 1, $surfaceY - 4);
				$caveFloor = $world->getBlockAt($x, $caveY, $z);
				$caveFeet = $world->getBlockAt($x, $caveY + 1, $z);
				$caveHead = $world->getBlockAt($x, $caveY + 2, $z);
				$caveLight = $world->getFullLightAt($x, $caveY + 1, $z);
				if($caveFloor->isSolid() && !$caveFeet->isSolid() && !$caveHead->isSolid() && $caveFeet->getTypeId() !== BlockTypeIds::WATER && $caveFeet->getTypeId() !== BlockTypeIds::LAVA && $caveHead->getTypeId() !== BlockTypeIds::WATER && $caveHead->getTypeId() !== BlockTypeIds::LAVA && $caveLight === 0){
					// Vanilla: cave spiders belong to monster spawners, and wardens are summoned
					// by sculk shriekers, not by the ordinary hostile-spawn table.
					$caveClasses = [Zombie::class, SkeletonMob::class, CreeperMob::class, SpiderMob::class];
					if($caveY < 63){
						$caveClasses[] = BatMob::class;
					}
					$class = $caveClasses[mt_rand(0, count($caveClasses) - 1)];
					$mob = new $class(new Location($x + 0.5, $caveY + 1.0, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0));
					if($mob instanceof Entity){
						if($mob instanceof Mob || $mob instanceof Squid){
							$mob->markNaturalSpawned();
						}
						$mob->spawnToAll();
					}
					return;
				}
			}

			$ground = $world->getBlockAt($x, $surfaceY, $z);
			$feet = $world->getBlockAt($x, $surfaceY + 1, $z);
			$head = $world->getBlockAt($x, $surfaceY + 2, $z);
			$time = $world->getTimeOfDay();
			$isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
			$biome = $world->getBiomeId($x, $surfaceY + 1, $z);
			$spawnY = $surfaceY + 1.0;
			$isFishSpawn = false;
			if($ground->getTypeId() === BlockTypeIds::WATER && !$feet->isSolid() && !$head->isSolid()){
				$oceanBiomes = [BiomeIds::OCEAN, BiomeIds::DEEP_OCEAN, BiomeIds::WARM_OCEAN, BiomeIds::DEEP_WARM_OCEAN, BiomeIds::LUKEWARM_OCEAN, BiomeIds::DEEP_LUKEWARM_OCEAN, BiomeIds::COLD_OCEAN, BiomeIds::DEEP_COLD_OCEAN];
				$coldOceanBiomes = [BiomeIds::COLD_OCEAN, BiomeIds::DEEP_COLD_OCEAN, BiomeIds::FROZEN_OCEAN, BiomeIds::DEEP_FROZEN_OCEAN, BiomeIds::FROZEN_RIVER];
				$warmOceanBiomes = [BiomeIds::WARM_OCEAN, BiomeIds::DEEP_WARM_OCEAN, BiomeIds::LUKEWARM_OCEAN, BiomeIds::DEEP_LUKEWARM_OCEAN];
				$aquaticClasses = [];
				if(in_array($biome, [...$oceanBiomes, BiomeIds::RIVER, BiomeIds::FROZEN_RIVER], true)){
					$aquaticClasses[] = CodMob::class;
					$aquaticClasses[] = CodMob::class;
					$aquaticClasses[] = CodMob::class;
					$aquaticClasses[] = CodMob::class;
					$aquaticClasses[] = Squid::class;
				}
				if(in_array($biome, $coldOceanBiomes, true)){
					$aquaticClasses[] = SalmonMob::class;
					$aquaticClasses[] = SalmonMob::class;
					$aquaticClasses[] = SalmonMob::class;
				}
				if(in_array($biome, $warmOceanBiomes, true)){
					$aquaticClasses[] = TropicalFishMob::class;
					$aquaticClasses[] = TropicalFishMob::class;
					$aquaticClasses[] = TropicalFishMob::class;
					$aquaticClasses[] = PufferfishMob::class;
				}
				if(in_array($biome, $oceanBiomes, true)){
					$aquaticClasses[] = DolphinMob::class;
				}
				$submergedOne = $surfaceY - 1 >= $world->getMinY() && $world->getBlockAt($x, $surfaceY - 1, $z)->getTypeId() === BlockTypeIds::WATER;
				$submergedTwo = $surfaceY - 2 >= $world->getMinY() && $world->getBlockAt($x, $surfaceY - 2, $z)->getTypeId() === BlockTypeIds::WATER;
				if($submergedOne && $submergedTwo && $world->getFullLightAt($x, $surfaceY - 1, $z) <= 7 && in_array($biome, [...$oceanBiomes, BiomeIds::RIVER, BiomeIds::FROZEN_RIVER], true)){
					$aquaticClasses[] = DrownedMob::class;
				}
				if($aquaticClasses === []){
					continue;
				}
				$class = $aquaticClasses[mt_rand(0, count($aquaticClasses) - 1)];
				$isFishSpawn = in_array($class, [CodMob::class, SalmonMob::class, TropicalFishMob::class, PufferfishMob::class], true);
				$spawnY = $class === DrownedMob::class ? $surfaceY - 1.0 : (float) $surfaceY;
			}elseif(!$ground->isSolid() || $feet->isSolid() || $head->isSolid() || $feet->getTypeId() === BlockTypeIds::WATER || $head->getTypeId() === BlockTypeIds::WATER){
				continue;
			}elseif($isNight){
				// Modern Bedrock-style hostile spawning requires total light level zero.
				if($world->getFullLightAt($x, $surfaceY + 1, $z) !== 0){
					continue;
				}
				$hostileClasses = [Zombie::class, SkeletonMob::class, CreeperMob::class, SpiderMob::class, EndermanMob::class];
				if(in_array($biome, [BiomeIds::SWAMPLAND, BiomeIds::SWAMPLAND_MUTATED, BiomeIds::MANGROVE_SWAMP], true)){
					$hostileClasses[] = BoggedMob::class;
					$hostileClasses[] = \pocketmine\entity\WitchMob::class;
				}
				if(in_array($biome, [BiomeIds::DESERT, BiomeIds::DESERT_HILLS, BiomeIds::DESERT_MUTATED, BiomeIds::MESA, BiomeIds::MESA_PLATEAU, BiomeIds::MESA_PLATEAU_STONE, BiomeIds::MESA_BRYCE], true)){
					$hostileClasses[] = ParchedMob::class;
				}
				if(in_array($biome, [BiomeIds::ICE_PLAINS, BiomeIds::ICE_MOUNTAINS, BiomeIds::COLD_TAIGA, BiomeIds::COLD_TAIGA_HILLS, BiomeIds::COLD_TAIGA_MUTATED, BiomeIds::FROZEN_PEAKS, BiomeIds::SNOWY_SLOPES, BiomeIds::GROVE, BiomeIds::ICE_CAVES], true)){
					$hostileClasses[] = StrayMob::class;
				}
				$class = $hostileClasses[mt_rand(0, count($hostileClasses) - 1)];
			}elseif($ground->getTypeId() === BlockTypeIds::GRASS){
				if(mt_rand(1, 3) !== 1){
					continue;
				}
				$passiveClasses = [Chicken::class, Cow::class, Pig::class, Sheep::class];
				if(in_array($biome, [BiomeIds::SWAMPLAND, BiomeIds::SWAMPLAND_MUTATED, BiomeIds::MANGROVE_SWAMP], true)){
					$passiveClasses[] = FrogMob::class;
				}
				if(in_array($biome, [BiomeIds::PLAINS, BiomeIds::SUNFLOWER_PLAINS, BiomeIds::SAVANNA, BiomeIds::SAVANNA_PLATEAU, BiomeIds::MEADOW], true)){
					$passiveClasses[] = HorseMob::class;
				}
				if(in_array($biome, [BiomeIds::PLAINS, BiomeIds::DESERT, BiomeIds::TAIGA, BiomeIds::COLD_TAIGA, BiomeIds::ICE_PLAINS, BiomeIds::SAVANNA, BiomeIds::MEADOW], true)){
					$passiveClasses[] = RabbitMob::class;
				}
				if(in_array($biome, [BiomeIds::TAIGA, BiomeIds::TAIGA_HILLS, BiomeIds::COLD_TAIGA, BiomeIds::COLD_TAIGA_HILLS, BiomeIds::MEGA_TAIGA, BiomeIds::GROVE, BiomeIds::CHERRY_GROVE], true)){
					$passiveClasses[] = FoxMob::class;
				}
				if(in_array($biome, [BiomeIds::FOREST, BiomeIds::FOREST_HILLS, BiomeIds::TAIGA, BiomeIds::TAIGA_HILLS, BiomeIds::COLD_TAIGA, BiomeIds::COLD_TAIGA_HILLS, BiomeIds::MEADOW, BiomeIds::GROVE], true)){
					$passiveClasses[] = WolfMob::class;
				}
				if(in_array($biome, [BiomeIds::EXTREME_HILLS, BiomeIds::EXTREME_HILLS_EDGE, BiomeIds::EXTREME_HILLS_PLUS_TREES, BiomeIds::MEADOW, BiomeIds::JAGGED_PEAKS, BiomeIds::FROZEN_PEAKS, BiomeIds::SNOWY_SLOPES], true)){
					$passiveClasses[] = GoatMob::class;
				}
				$class = $passiveClasses[mt_rand(0, count($passiveClasses) - 1)];
			}elseif($ground->getTypeId() === BlockTypeIds::MYCELIUM && in_array($biome, [BiomeIds::MUSHROOM_ISLAND, BiomeIds::MUSHROOM_ISLAND_SHORE], true)){
				$class = MooshroomMob::class;
			}elseif(in_array($ground->getTypeId(), [BlockTypeIds::SAND, BlockTypeIds::RED_SAND], true) && in_array($biome, [BiomeIds::DESERT, BiomeIds::DESERT_HILLS, BiomeIds::DESERT_MUTATED], true)){
				$class = CamelMob::class;
			}elseif(in_array($ground->getTypeId(), [BlockTypeIds::SAND, BlockTypeIds::GRASS], true) && in_array($biome, [BiomeIds::BEACH, BiomeIds::COLD_BEACH, BiomeIds::STONE_BEACH], true)){
				$class = TurtleMob::class;
			}else{
				continue;
			}
			$location = new Location($x + 0.5, $spawnY, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0);
			if($isFishSpawn){
				$this->spawnFishSchool($class, $location);
			}else{
				$this->spawnNaturalMob($class, $location);
			}
			return;
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
