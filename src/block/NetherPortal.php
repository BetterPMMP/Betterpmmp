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

namespace pocketmine\block;

use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\generator\vanilla\VanillaNether;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use function round;
use function max;
use function min;
use function str_ends_with;
use function substr;

class NetherPortal extends Transparent{

	protected int $axis = Axis::X;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalAxis($this->axis);
	}

	public function getAxis() : int{
		return $this->axis;
	}

	/**
	 * @throws \InvalidArgumentException
	 * @return $this
	 */
	public function setAxis(int $axis) : self{
		if($axis !== Axis::X && $axis !== Axis::Z){
			throw new \InvalidArgumentException("Invalid axis");
		}
		$this->axis = $axis;
		return $this;
	}

	public function getLightLevel() : int{
		return 11;
	}

	public function isSolid() : bool{
		return false;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function getDrops(Item $item) : array{
		return [];
	}

	public function onEntityInside(Entity $entity) : bool{
		// BetterPMMP: a failing dimension transfer must never take the whole server down
		try{
			return $this->onEntityInsideUnsafe($entity);
		}catch(\Throwable $e){
			$world = $entity->getWorld();
			$world->getLogger()->error("Portal transfer failed: " . $e->getMessage());
			$world->getLogger()->logException($e);
			$entity->setPortalCooldown(200);
			if($entity instanceof \pocketmine\player\Player){
				$entity->sendMessage("§cDas Portal konnte die Zielwelt nicht laden (siehe Server-Konsole).");
			}
			return true;
		}
	}

	private function onEntityInsideUnsafe(Entity $entity) : bool{
		if($entity->hasPortalCooldown()){
			return true;
		}
		$source = $entity->getWorld();
		if($source->getDimensionId() === DimensionIds::THE_END){
			return true;
		}
		$sourceName = $source->getFolderName();
		$isNether = $source->getDimensionId() === DimensionIds::NETHER;
		$manager = $source->getServer()->getWorldManager();
		$target = null;
		$targetName = $sourceName . "_nether";
		if($isNether){
			$pairedOverworld = str_ends_with(strtolower($sourceName), "_nether") ? substr($sourceName, 0, -7) : "";
			if($pairedOverworld !== ""){
				$target = self::loadExistingDimensionWorld($manager, $pairedOverworld, DimensionIds::OVERWORLD);
			}
			$target ??= $manager->getDefaultWorld();
			$target ??= $manager->getWorldByDimensionId(DimensionIds::OVERWORLD, $source);
		}else{
			$target = self::loadExistingDimensionWorld($manager, $targetName, DimensionIds::NETHER);
			$target ??= $manager->getWorldByDimensionId(DimensionIds::NETHER, $source);
			if($target === null){
				foreach(["world_nether", "nether", "Nether"] as $candidate){
					$target = self::loadExistingDimensionWorld($manager, $candidate, DimensionIds::NETHER);
					if($target !== null){
						break;
					}
				}
			}
			if($target === null){
				$options = WorldCreationOptions::create()->setSeed($source->getSeed())->setGeneratorClass(VanillaNether::class);
				$manager->generateWorld($targetName, $options, false);
				$target = $manager->getWorldByName($targetName);
			}
		}
		if($target === null){
			return true;
		}

		$scale = $isNether ? 8.0 : 0.125;
		$x = (int) round($entity->getPosition()->x * $scale);
		$z = (int) round($entity->getPosition()->z * $scale);
		$entity->setPortalCooldown(80);
		$yaw = $entity->getLocation()->yaw;
		$pitch = $entity->getLocation()->pitch;
		$target->requestChunkPopulation($x >> 4, $z >> 4, null)->onCompletion(
			static function() use ($entity, $source, $target, $x, $z, $yaw, $pitch) : void{
				try{
					if($entity->isClosed() || $entity->getWorld() !== $source || !$target->isLoaded()){
						return;
					}
					// A scaled coordinate may land in a lava pool or a large cave. Search
					// nearby columns too, otherwise the portal silently consumes its cooldown
					// without transferring the player.
					$minY = $target->getMinY();
					$maxY = $target->getMaxY();
					$destination = null;
					for($radius = 0; $radius <= 8 && $destination === null; ++$radius){
						for($dx = -$radius; $dx <= $radius && $destination === null; ++$dx){
							for($dz = -$radius; $dz <= $radius && $destination === null; ++$dz){
								if($radius > 0 && abs($dx) !== $radius && abs($dz) !== $radius){
									continue;
								}
								$columnX = $x + $dx;
								$columnZ = $z + $dz;
								if(!$target->isChunkLoaded($columnX >> 4, $columnZ >> 4)){
									continue;
								}
								for($floorY = min($maxY - 3, 118); $floorY >= $minY + 1; --$floorY){
									$floor = $target->getBlockAt($columnX, $floorY, $columnZ);
									$feet = $target->getBlockAt($columnX, $floorY + 1, $columnZ);
									$head = $target->getBlockAt($columnX, $floorY + 2, $columnZ);
									if($floor->isSolid() && $floor->getTypeId() !== BlockTypeIds::BEDROCK && $floor->getTypeId() !== BlockTypeIds::LAVA && !$feet->isSolid() && !$head->isSolid() && $feet->getTypeId() !== BlockTypeIds::LAVA && $head->getTypeId() !== BlockTypeIds::LAVA){
										$destination = [$columnX + 0.5, $floorY + 1.0, $columnZ + 0.5];
										break;
									}
								}
							}
						}
					}
					if($destination === null){
						$entity->setPortalCooldown(0);
						return;
					}
					$searchRadius = $target->getDimensionId() === DimensionIds::NETHER ? 16 : 128;
					$existingPortal = self::findExistingPortalNear($target, (int) floor($destination[0]), (int) floor($destination[1]), (int) floor($destination[2]), $searchRadius);
					if($existingPortal !== null){
						// Reuse a player-built portal in the target world. Only create a
						// new frame when no loaded portal is in the normal link radius.
						[$portalX, $portalY, $portalZ, $axis] = $existingPortal;
						$destination = $axis === Axis::X
							? [$portalX + 0.5, (float) $portalY, $portalZ + 2.5]
							: [$portalX + 2.5, (float) $portalY, $portalZ + 0.5];
					}else{
						$portal = self::buildReturnPortal($target, $destination[0], $destination[1] - 1.0, $destination[2]);
						if($portal !== null){
							// Arrive beside the portal, not inside it; otherwise the portal
							// cooldown expiring would immediately send the player back.
							$destination = [$portal[0] + 0.5, $portal[1] + 1.0, $portal[2] + 2.0];
						}
					}
					$entity->teleport(new Location($destination[0], $destination[1], $destination[2], $target, $yaw, $pitch));
				}catch(\Throwable $e){
					$target->getLogger()->error("Nether portal transfer failed: " . $e->getMessage());
					$target->getLogger()->logException($e);
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

	/** Creates a small, active portal at the destination and returns its inner origin. */
	private static function buildReturnPortal(World $world, float $x, float $groundY, float $z) : ?array{
		$centerX = (int) floor($x);
		$centerZ = (int) floor($z);
		$chunkBaseX = ($centerX >> 4) << 4;
		$chunkBaseZ = ($centerZ >> 4) << 4;
		$portalX = max($chunkBaseX + 1, min($chunkBaseX + 12, $centerX));
		$portalZ = max($chunkBaseZ + 1, min($chunkBaseZ + 12, $centerZ));
		$baseY = (int) floor($groundY) + 1;
		if($baseY < $world->getMinY() || $baseY + 4 >= $world->getMaxY()){
			return null;
		}
		if(!$world->isChunkLoaded($chunkBaseX >> 4, $chunkBaseZ >> 4)){
			return null;
		}

		// The interior is only two blocks wide, but three blocks tall. Clear
		// vegetation and other non-solid blocks so the portal is walkable.
		for($dx = 0; $dx < 2; ++$dx){
			for($dy = 1; $dy <= 3; ++$dy){
				$world->setBlockAt($portalX + $dx, $baseY + $dy, $portalZ, VanillaBlocks::AIR());
			}
		}
		for($dx = -1; $dx <= 2; ++$dx){
			$world->setBlockAt($portalX + $dx, $baseY, $portalZ, VanillaBlocks::OBSIDIAN());
			$world->setBlockAt($portalX + $dx, $baseY + 4, $portalZ, VanillaBlocks::OBSIDIAN());
		}
		for($dy = 1; $dy <= 3; ++$dy){
			$world->setBlockAt($portalX - 1, $baseY + $dy, $portalZ, VanillaBlocks::OBSIDIAN());
			$world->setBlockAt($portalX + 2, $baseY + $dy, $portalZ, VanillaBlocks::OBSIDIAN());
			$portalBlock = clone VanillaBlocks::NETHER_PORTAL();
			$portalBlock->setAxis(Axis::X);
			$world->setBlockAt($portalX, $baseY + $dy, $portalZ, $portalBlock);
			$world->setBlockAt($portalX + 1, $baseY + $dy, $portalZ, $portalBlock);
		}
		$world->setBlockAt($portalX, $baseY, $portalZ + 2, VanillaBlocks::OBSIDIAN());
		$world->setBlockAt($portalX + 1, $baseY, $portalZ + 2, VanillaBlocks::OBSIDIAN());
		return [$portalX, $baseY, $portalZ];
	}

	private static function loadExistingDimensionWorld(WorldManager $manager, string $name, int $dimensionId) : ?World{
		$world = $manager->getWorldByName($name);
		if($world === null && $manager->isWorldGenerated($name)){
			$manager->loadWorld($name);
			$world = $manager->getWorldByName($name);
		}
		return $world !== null && $world->getDimensionId() === $dimensionId ? $world : null;
	}

	/** @return array{int, int, int, int}|null */
	private static function findExistingPortalNear(World $world, int $centerX, int $centerY, int $centerZ, int $radius) : ?array{
		$centerChunkX = $centerX >> 4;
		$centerChunkZ = $centerZ >> 4;
		$chunkRadius = intdiv($radius + 15, 16);
		$minY = max($world->getMinY(), $centerY - 32);
		$maxY = min($world->getMaxY() - 1, $centerY + 32);
		$portalId = BlockTypeIds::NETHER_PORTAL;
		$closest = null;
		$closestDistance = PHP_INT_MAX;
		for($chunkX = $centerChunkX - $chunkRadius; $chunkX <= $centerChunkX + $chunkRadius; ++$chunkX){
			for($chunkZ = $centerChunkZ - $chunkRadius; $chunkZ <= $centerChunkZ + $chunkRadius; ++$chunkZ){
				if(!$world->isChunkLoaded($chunkX, $chunkZ) && max(abs($chunkX - $centerChunkX), abs($chunkZ - $centerChunkZ)) <= 2 && $world->isChunkGenerated($chunkX, $chunkZ)){
					$world->loadChunk($chunkX, $chunkZ);
				}
				if(!$world->isChunkLoaded($chunkX, $chunkZ)){
					continue;
				}
				$fromX = max($centerX - $radius, $chunkX << 4);
				$toX = min($centerX + $radius, ($chunkX << 4) + 15);
				$fromZ = max($centerZ - $radius, $chunkZ << 4);
				$toZ = min($centerZ + $radius, ($chunkZ << 4) + 15);
				for($x = $fromX; $x <= $toX; ++$x){
					for($z = $fromZ; $z <= $toZ; ++$z){
						for($y = $minY; $y <= $maxY; ++$y){
							$block = $world->getBlockAt($x, $y, $z);
							if($block->getTypeId() !== $portalId){
								continue;
							}
							$distance = (($x - $centerX) ** 2) + (($y - $centerY) ** 2) + (($z - $centerZ) ** 2);
							if($distance < $closestDistance){
								$closestDistance = $distance;
								$closest = [$x, $y, $z, $block->getAxis()];
							}
						}
					}
				}
			}
		}
		return $closest;
	}
}
