<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\SupportType;
use pocketmine\entity\Entity;
use pocketmine\entity\EnderDragonMob;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal as EndCrystalEntity;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\generator\the_end\TheEnd;
use pocketmine\world\WorldManager;

class EndPortal extends Transparent{
	/** @var array<int, array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}> */
	private static array $returnLocations = [];

	public function isSolid() : bool{ return false; }
	public function hasEntityCollision() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{ return []; }

	public function getSupportType(int $facing) : SupportType{ return SupportType::NONE; }

	public function getLightLevel() : int{ return 15; }

	public function onEntityInside(Entity $entity) : bool{
		if($entity->hasPortalCooldown()){
			return true;
		}
		$source = $entity->getWorld();
		$manager = $source->getServer()->getWorldManager();
		$isEnd = $source->getDimensionId() === DimensionIds::THE_END;
		$sourceName = $source->getFolderName();
		$returnKey = $entity->getId();
		$returnLocation = self::$returnLocations[$returnKey] ?? null;
		$target = null;
		if($isEnd){
			$preferredOverworld = $returnLocation["world"] ?? ($manager->getDefaultWorld()?->getFolderName() ?? "world");
			$target = self::loadExistingDimensionWorld($manager, $preferredOverworld, DimensionIds::OVERWORLD);
			$target ??= $manager->getDefaultWorld();
			$target ??= $manager->getWorldByDimensionId(DimensionIds::OVERWORLD, $source);
		}else{
			// Always prefer the End belonging to this Overworld. An unrelated
			// End dimension must never hijack the portal destination.
			$pairedName = $sourceName . "_the_end";
			foreach([$pairedName, $sourceName . "_end", "the_end", "world_the_end", "end", "TheEnd"] as $candidate){
				$target = self::loadExistingDimensionWorld($manager, $candidate, DimensionIds::THE_END);
				if($target !== null){
					break;
				}
			}
			if($target === null){
				// Avoid collisions with an existing incompatible world folder.
				$targetName = $pairedName;
				if($manager->isWorldGenerated($targetName)){
					// Never overwrite an incompatible existing world. Pick a fresh
					// dimension folder and keep the standard _end suffix so World
					// detects the correct dimension on subsequent server starts.
					$baseName = $sourceName . "_betterpmmp";
					$number = 1;
					do{
						$targetName = $baseName . ($number === 1 ? "" : "_" . $number) . "_end";
						++$number;
					}while($manager->isWorldGenerated($targetName));
				}
				$options = WorldCreationOptions::create()
					->setSeed($source->getSeed())
					->setGeneratorClass(TheEnd::class)
					->setSpawnPosition(new Vector3(0, 65, 0));
				if(!$manager->isWorldGenerated($targetName)){
					$manager->generateWorld($targetName, $options, false);
				}
				$target = self::loadExistingDimensionWorld($manager, $targetName, DimensionIds::THE_END);
			}
		}
		if($target === null){
			$source->getLogger()->warning("End-Portal konnte für Welt '" . $sourceName . "' keine geladene oder neu erstellte Zielwelt finden.");
			return true;
		}
		$targetName = $target->getFolderName();
		if(!$isEnd){
			$sourceLocation = $entity->getLocation();
			self::$returnLocations[$returnKey] = [
				"world" => $sourceName,
				"x" => $sourceLocation->x,
				"y" => $sourceLocation->y,
				"z" => $sourceLocation->z,
				"yaw" => $sourceLocation->yaw,
				"pitch" => $sourceLocation->pitch
			];
			$returnLocation = self::$returnLocations[$returnKey];
		}
		if($isEnd && $returnLocation !== null){
			$x = (int) floor($returnLocation["x"]);
			$y = (int) floor($returnLocation["y"]);
			$z = (int) floor($returnLocation["z"]);
		}elseif($isEnd){
			$spawn = $target->getSpawnLocation();
			$x = $spawn->getFloorX();
			$y = $spawn->getFloorY();
			$z = $spawn->getFloorZ();
		}else{
			$x = 0;
			$y = 65;
			$z = 0;
		}
		$entity->setPortalCooldown(80);
		$location = $entity->getLocation();
		$yaw = $location->yaw;
		$pitch = $location->pitch;
		$target->requestChunkPopulation($x >> 4, $z >> 4, null)->onCompletion(
			static function() use ($entity, $source, $target, $x, $y, $z, $yaw, $pitch, $isEnd, $returnKey, $returnLocation) : void{
				if($entity->isClosed() || $entity->getWorld() !== $source || !$target->isLoaded()){
					return;
				}
				// The generated End already contains its island and pillars. Older End
				// worlds get only the arrival chunk repaired; queuing 169 chunks here
				// kept the destination request behind a long generation backlog.
				if(!$isEnd && $target->getBlockAt(0, 63, 0)->getTypeId() !== VanillaBlocks::END_STONE()->getTypeId()){
					TheEnd::upgradeArenaChunk($target, 0, 0);
				}
				// An arrival pad is mandatory even when an older or custom End
				// generator left the origin empty. Never spawn over the void.
				if(!$isEnd){
					$endStone = VanillaBlocks::END_STONE();
					for($padX = -2; $padX <= 2; ++$padX){
						for($padZ = -2; $padZ <= 2; ++$padZ){
							if(!$target->getBlockAt($padX, 64, $padZ)->isSolid()){
								$target->setBlockAt($padX, 64, $padZ, $endStone);
							}
						}
					}
				}
				$destination = null;
				if($isEnd && $returnLocation !== null){
					$destination = self::findSafeReturnLocation($target, $returnLocation["x"], $returnLocation["y"], $returnLocation["z"], $returnLocation["yaw"], $returnLocation["pitch"]);
				}
				$teleported = $entity->teleport($destination ?? new Location($x + 0.5, $y, $z + 0.5, $target, $yaw, $pitch));
				if(!$teleported){
					$source->getLogger()->warning("End-Portal-Transfer von '" . $source->getFolderName() . "' nach '" . $target->getFolderName() . "' wurde durch ein Teleport-Ereignis abgelehnt.");
					$entity->setPortalCooldown(0);
					return;
				}
				if($isEnd){
					unset(self::$returnLocations[$returnKey]);
				}
				if($source->getDimensionId() !== DimensionIds::THE_END){
					$exitPortalId = VanillaBlocks::END_PORTAL()->getTypeId();
					for($portalX = -1; $portalX <= 1; ++$portalX){
						for($portalZ = -1; $portalZ <= 1; ++$portalZ){
							if($target->getBlockAt($portalX, 65, $portalZ)->getTypeId() === $exitPortalId){
								return;
							}
						}
					}
					foreach(TheEnd::getPillars() as [$pillarX, $pillarZ, $height, $_caged]){
						$target->requestChunkPopulation($pillarX >> 4, $pillarZ >> 4, null)->onCompletion(
							static function() use ($target, $pillarX, $pillarZ, $height) : void{
								if(!$target->isLoaded()){
									return;
								}
								foreach($target->getEntities() as $existing){
									if($existing instanceof EndCrystalEntity && $existing->getPosition()->distanceSquared(new Vector3($pillarX + 0.5, 66 + $height, $pillarZ + 0.5)) < 4){
										return;
										}
								}
								$crystal = new EndCrystalEntity(new Location($pillarX + 0.5, 66 + $height, $pillarZ + 0.5, $target, 0.0, 0.0));
								$crystal->setShowBase(true);
								$crystal->setBeamTarget(new Vector3(0, 82, 0));
								$crystal->spawnToAll();
							},
							static function() : void{}
						);
					}
					// Server has no global task scheduler API. Spawn after the player's
					// dimension-change packet was queued by teleport(), in this callback.
					$dragonExists = false;
					foreach($target->getEntities() as $existing){
						if($existing instanceof EnderDragonMob && !$existing->isClosed() && $existing->isAlive()){
							// Bossbar is synchronized by dragon ticks, not during teleport.
							$dragonExists = true;
							break;
						}
					}
					if(!$dragonExists){
						$dragon = new EnderDragonMob(new Location(0.5, 82.0, 0.5, $target, 0.0, 0.0));
						$dragon->spawnToAll();
					}
				}
			},
			static function() use ($entity) : void{
				if(!$entity->isClosed()){
					$entity->setPortalCooldown(0);
				}
			}
		);
		return true;
	}

	/** Returns beside the exact End portal used by this player, without creating another portal. */
	private static function findSafeReturnLocation(\pocketmine\world\World $world, float $portalX, float $portalY, float $portalZ, float $yaw, float $pitch) : ?Location{
		$centerX = (int) floor($portalX);
		$centerZ = (int) floor($portalZ);
		$centerY = (int) floor($portalY);
		$portalId = VanillaBlocks::END_PORTAL()->getTypeId();
		$frameId = VanillaBlocks::END_PORTAL_FRAME()->getTypeId();
		for($radius = 1; $radius <= 5; ++$radius){
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if($radius > 1 && abs($dx) !== $radius && abs($dz) !== $radius){
						continue;
					}
					$x = $centerX + $dx;
					$z = $centerZ + $dz;
					if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
						continue;
					}
					for($floorY = max($world->getMinY(), $centerY - 2); $floorY <= min($world->getMaxY() - 3, $centerY + 6); ++$floorY){
						$floor = $world->getBlockAt($x, $floorY, $z);
						$feet = $world->getBlockAt($x, $floorY + 1, $z);
						$head = $world->getBlockAt($x, $floorY + 2, $z);
						if(!$floor->isSolid() || $floor->getTypeId() === $frameId || $feet->isSolid() || $head->isSolid() || $feet->getTypeId() === $portalId || $head->getTypeId() === $portalId){
							continue;
						}
						return new Location($x + 0.5, $floorY + 1.0, $z + 0.5, $world, $yaw, $pitch);
					}
				}
			}
		}
		return null;
	}

	private static function loadExistingDimensionWorld(WorldManager $manager, string $name, int $dimensionId) : ?\pocketmine\world\World{
		$world = $manager->getWorldByName($name);
		if($world === null && $manager->isWorldGenerated($name)){
			$manager->loadWorld($name);
			$world = $manager->getWorldByName($name);
		}
		return $world !== null && $world->getDimensionId() === $dimensionId ? $world : null;
	}
}
