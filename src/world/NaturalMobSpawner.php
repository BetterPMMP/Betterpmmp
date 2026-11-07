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
use function in_array;
use function intdiv;
use function mt_rand;
use function min;
use function max;

/**
 * Low-cost first pass at natural passive mob spawning. Only samples already
 * loaded terrain, so this never forces chunk generation from the world tick.
 */
final class NaturalMobSpawner{
	/** @var int[] world id => server tick of the next patrol attempt */
	private array $nextPatrolTick = [];

	public function tick(World $world) : void{
		if(!\pocketmine\BetterPMMPConfig::mobSpawningEnabled($world)){
			return;
		}
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
		if(\pocketmine\BetterPMMPConfig::spawns($world, "village-residents")){
			$this->ensureVillageResidentsNearPlayers($world, $eligiblePlayers);
		}
		if(\pocketmine\BetterPMMPConfig::spawns($world, "pillagers")){
			$this->tickPillagers($world, $eligiblePlayers);
			$this->ensureOutpostGuards($world, $eligiblePlayers);
		}
		if($world->getDimensionId() === DimensionIds::OVERWORLD && \pocketmine\BetterPMMPConfig::spawns($world, "wandering-trader")){
			$this->tickWanderingTrader($world, $eligiblePlayers);
		}
		if(\pocketmine\BetterPMMPConfig::spawns($world, "wardens")){
			\pocketmine\world\SculkShriekers::tick($world, $eligiblePlayers);
		}
		if($world->getDimensionId() === DimensionIds::OVERWORLD || $world->getDimensionId() === DimensionIds::NETHER){
			$this->spawnOverworldCategories($world, $eligiblePlayers);
		}
		if($world->getDimensionId() === DimensionIds::OVERWORLD && !\pocketmine\BetterPMMPConfig::spawns($world, "water")){
			return;
		}

		$mobCount = 0;
		$overworld = $world->getDimensionId() === DimensionIds::OVERWORLD || $world->getDimensionId() === DimensionIds::NETHER;
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
				continue; // handled by spawnOverworldCategories (biome tables + fortresses)
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
		if($entity instanceof \pocketmine\entity\GenericHostileMob || $entity instanceof Zombie
			|| ($entity instanceof \pocketmine\entity\GenericHostileFlyingMob && !$entity instanceof \pocketmine\entity\EnderDragonMob && !$entity instanceof \pocketmine\entity\WitherMob)){
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
		$nether = $world->getDimensionId() === DimensionIds::NETHER;
		$caps = [
			VanillaSpawnTables::CATEGORY_MONSTER => \pocketmine\BetterPMMPConfig::int("mob-spawning", "max-monsters-per-player", 70),
			VanillaSpawnTables::CATEGORY_CREATURE => \pocketmine\BetterPMMPConfig::int("mob-spawning", "max-animals-per-player", 10),
			VanillaSpawnTables::CATEGORY_AMBIENT => \pocketmine\BetterPMMPConfig::int("mob-spawning", "max-ambient-per-player", 15),
		];
		$switches = [VanillaSpawnTables::CATEGORY_MONSTER => "monsters", VanillaSpawnTables::CATEGORY_CREATURE => "animals", VanillaSpawnTables::CATEGORY_AMBIENT => "ambient"];
		foreach($players as $player){
			foreach($caps as $category => $cap){
				if($nether && $category !== VanillaSpawnTables::CATEGORY_MONSTER){
					continue;
				}
				if(!\pocketmine\BetterPMMPConfig::spawns($world, $switches[$category])){
					continue;
				}
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
		$nether = $world->getDimensionId() === DimensionIds::NETHER;
		if($nether){
			$y = mt_rand(max($world->getMinY() + 1, 5), 122);
		}else{
			$top = $world->getHighestBlockAt($x, $z);
			if($top === null){
				return 0;
			}
			$y = $category === VanillaSpawnTables::CATEGORY_CREATURE ? $top + 1 : mt_rand($world->getMinY() + 1, $top + 1);
		}
		$y = $this->findFloor($world, $x, $y, $z, 16);
		if($y === null){
			return 0;
		}
		$biome = $world->getBiomeId($x, $y, $z);
		$entries = match(true){
			$nether && $this->inFortress($world, $x, $y, $z) => VanillaSpawnTables::fortressMonsters(),
			$nether => VanillaSpawnTables::netherMonsters($biome),
			$category === VanillaSpawnTables::CATEGORY_MONSTER => VanillaSpawnTables::monsters($biome),
			$category === VanillaSpawnTables::CATEGORY_CREATURE => VanillaSpawnTables::creatures($biome),
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

	/** @var array<int, array{int, int}> world id => [server tick of the next attempt, chance in %] */
	private array $traderState = [];

	/**
	 * Vanilla WanderingTraderSpawner: every 20 minutes the chance of a wandering
	 * trader (with two trader llamas) near a random player goes 25 -> 50 -> 75 %
	 * until one spawns. Only one trader at a time.
	 * @param Player[] $players
	 */
	private function tickWanderingTrader(World $world, array $players) : void{
		$tick = $world->getServer()->getTick();
		[$next, $chance] = $this->traderState[$world->getId()] ??= [$tick + 24000, 25];
		if($tick < $next){
			return;
		}
		foreach($world->getEntities() as $entity){
			if($entity instanceof \pocketmine\entity\WanderingTraderMob && $entity->isAlive()){
				$this->traderState[$world->getId()] = [$tick + 24000, $chance];
				return;
			}
		}
		if(mt_rand(1, 100) > $chance){
			$this->traderState[$world->getId()] = [$tick + 24000, min(75, $chance + 25)];
			return;
		}
		$player = $players[mt_rand(0, count($players) - 1)];
		$center = $player->getPosition();
		for($attempt = 0; $attempt < 10; ++$attempt){
			$location = $this->surfaceSpawnPoint($world, $center->getFloorX() + mt_rand(-48, 48), $center->getFloorZ() + mt_rand(-48, 48));
			if($location === null){
				continue;
			}
			(new \pocketmine\entity\WanderingTraderMob($location))->spawnToAll();
			for($i = 0; $i < 2; ++$i){
				$llamaSpot = $this->surfaceSpawnPoint($world, $location->getFloorX() + mt_rand(-4, 4), $location->getFloorZ() + mt_rand(-4, 4));
				if($llamaSpot !== null){
					(new \pocketmine\entity\TraderLlamaMob($llamaSpot))->spawnToAll();
				}
			}
			$this->traderState[$world->getId()] = [$tick + 24000, 25];
			return;
		}
	}

	/** Whether a position lies inside a nether fortress of a BetterPMMP vanilla Nether. */
	private function inFortress(World $world, int $x, int $y, int $z) : bool{
		$manager = \pocketmine\world\generator\vanilla\structure\WorldStructures::get($world);
		if($manager === null){
			return false;
		}
		foreach($manager->startsIn($x >> 4, $z >> 4) as $start){
			if($start->type === "fortress" && $start->containsColumn($x, $z) && $y >= $start->minY - 1 && $y <= $start->maxY + 1){
				return true;
			}
		}
		return false;
	}

	/** @var array<string, true> outposts that already got their guards */
	private array $guardedOutposts = [];

	/**
	 * Vanilla outposts are guarded from the start: when a player first comes
	 * near one, 4-6 pillagers spawn around the tower.
	 * @param Player[] $players
	 */
	private function ensureOutpostGuards(World $world, array $players) : void{
		$manager = $this->structuresFor($world);
		if($manager === null){
			return;
		}
		foreach($players as $player){
			$cx = $player->getPosition()->getFloorX() >> 4;
			$cz = $player->getPosition()->getFloorZ() >> 4;
			for($dx = -3; $dx <= 3; ++$dx){
				for($dz = -3; $dz <= 3; ++$dz){
					foreach($manager->startsIn($cx + $dx, $cz + $dz) as $start){
						if($start->type !== "pillager_outpost"){
							continue;
						}
						$key = $world->getId() . ":" . $start->chunkX . ":" . $start->chunkZ;
						if(isset($this->guardedOutposts[$key])){
							continue;
						}
						$mx = intdiv($start->minX + $start->maxX, 2);
						$mz = intdiv($start->minZ + $start->maxZ, 2);
						if(!$world->isChunkLoaded($mx >> 4, $mz >> 4)){
							continue;
						}
						$this->guardedOutposts[$key] = true;
						$count = mt_rand(4, 6);
						for($i = 0, $tries = 0; $i < $count && $tries < 40; ++$tries){
							$location = $this->surfaceSpawnPoint($world, $mx + mt_rand(-10, 10), $mz + mt_rand(-10, 10));
							if($location !== null){
								$this->spawnNaturalMob(PillagerMob::class, $location, $i === 0);
								++$i;
							}
						}
					}
				}
			}
		}
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
				if($world->getDimensionId() === DimensionIds::NETHER){
					// the Nether has no sky: monsters spawn up to block light 11
					if($blockLight > 11){
						return false;
					}
					if($class === \pocketmine\entity\GhastMob::class){
						for($dy = 0; $dy < 4; ++$dy){
							foreach([[0, 0], [1, 0], [-1, 0], [0, 1], [0, -1]] as [$ox, $oz]){
								if($world->getBlockAt($x + $ox * 2, $y + $dy, $z + $oz * 2)->isSolid()){
									return false;
								}
							}
						}
					}
					return true;
				}
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

	/** @var array<string, true> villages that already got their residents */
	private array $populatedVillages = [];

	/**
	 * Vanilla villages are inhabited from the start: when a player comes within
	 * about 48 blocks of a generated village, it gets villagers spread over
	 * its streets (one per few buildings, 3-10). Villagers already there are
	 * counted, so restarts do not add more.
	 * @param Player[] $players
	 */
	private function ensureVillageResidentsNearPlayers(World $world, array $players) : void{
		$manager = $this->structuresFor($world);
		if($manager === null){
			return;
		}
		foreach($players as $player){
			$cx = $player->getPosition()->getFloorX() >> 4;
			$cz = $player->getPosition()->getFloorZ() >> 4;
			for($dx = -3; $dx <= 3; ++$dx){
				for($dz = -3; $dz <= 3; ++$dz){
					foreach($manager->startsIn($cx + $dx, $cz + $dz) as $start){
						if($start->type !== "village"){
							continue;
						}
						$key = $world->getId() . ":" . $start->chunkX . ":" . $start->chunkZ;
						if(isset($this->populatedVillages[$key])){
							continue;
						}
						$mx = intdiv($start->minX + $start->maxX, 2);
						$mz = intdiv($start->minZ + $start->maxZ, 2);
						// wait until the village centre and its surroundings are loaded
						if(!$world->isChunkLoaded($mx >> 4, $mz >> 4) || !$world->isChunkPopulated($mx >> 4, $mz >> 4)){
							continue;
						}
						$this->populatedVillages[$key] = true;
						$wanted = max(3, min(10, intdiv(count($start->pieces), 3)));
						$existing = 0;
						foreach($world->getEntities() as $entity){
							$p = $entity->getPosition();
							if($entity instanceof Villager && $p->x >= $start->minX - 8 && $p->x <= $start->maxX + 8 && $p->z >= $start->minZ - 8 && $p->z <= $start->maxZ + 8){
								++$existing;
							}
						}
						for($attempt = 0, $spawned = $existing; $attempt < 80 && $spawned < $wanted; ++$attempt){
							$sx = mt_rand($start->minX, $start->maxX);
							$sz = mt_rand($start->minZ, $start->maxZ);
							if(!$world->isChunkLoaded($sx >> 4, $sz >> 4) || !$world->isChunkPopulated($sx >> 4, $sz >> 4)){
								continue;
							}
							$location = $this->surfaceSpawnPoint($world, $sx, $sz);
							if($location === null){
								continue;
							}
							// streets and open ground only, not roofs or fields
							$floor = $world->getBlockAt($location->getFloorX(), $location->getFloorY() - 1, $location->getFloorZ())->getTypeId();
							if(!in_array($floor, [BlockTypeIds::GRASS, BlockTypeIds::GRASS_PATH, BlockTypeIds::DIRT, BlockTypeIds::GRAVEL, BlockTypeIds::SAND, BlockTypeIds::RED_SAND, BlockTypeIds::PODZOL, BlockTypeIds::SNOW, BlockTypeIds::COBBLESTONE], true)){
								continue;
							}
							(new Villager($location))->spawnToAll();
							++$spawned;
						}
					}
				}
			}
		}
	}

	/**
	 * Structure locations of a world made with the BetterPMMP vanilla generator
	 * (the same deterministic layout the generator used).
	 */
	private function structuresFor(World $world) : ?\pocketmine\world\generator\vanilla\structure\StructureManager{
		if($world->getDimensionId() !== DimensionIds::OVERWORLD){
			return null;
		}
		return \pocketmine\world\generator\vanilla\structure\WorldStructures::get($world);
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
				$this->spawnNaturalMob(PillagerMob::class, $location, $i === 0);
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
	private function spawnNaturalMob(string $class, Location $location, bool $captain = false) : void{
		$mob = new $class($location);
		if($mob instanceof Entity){
			if($captain && $mob instanceof PillagerMob){
				$mob->setCaptain(); // vanilla: the patrol leader / outpost captain carries the ominous banner
			}
			if($mob instanceof Mob || $mob instanceof Squid){
				$mob->markNaturalSpawned();
			}
			$mob->spawnToAll();
		}
	}
}
