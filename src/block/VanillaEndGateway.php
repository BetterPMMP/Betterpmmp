<?php

/*
 * BetterPMMP - vanilla end gateway.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\math\Vector3;
use pocketmine\world\EndDragonFight;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function floor;
use function sqrt;

/**
 * End gateways created by the dragon fight teleport to the outer islands,
 * about 1000 blocks out in the gateway's direction. An exit gateway with a
 * small end stone platform is built there, leading back to the main island.
 */
class VanillaEndGateway extends GenericBedrockBlock{

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if($entity instanceof EndCrystal || $entity->hasPortalCooldown() || !EndDragonFight::isEndWorld($this->position->getWorld())){
			return true;
		}
		$entity->setPortalCooldown(40);
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		$length = sqrt($x * $x + $z * $z);
		if($length < 1){
			return true;
		}
		$dirX = $x / $length;
		$dirZ = $z / $length;
		if($length < 400){
			// main island -> outer islands
			$tx = (int) floor($dirX * 1024);
			$tz = (int) floor($dirZ * 1024);
			self::teleportOuter($world, $entity, $tx, $tz);
		}else{
			// exit gateway -> back to the ring around the main island
			$index = 0;
			$best = PHP_FLOAT_MAX;
			for($i = 0; $i < 20; ++$i){
				[$gx, $gz] = EndDragonFight::gatewayPosition($i);
				$d = ($gx / 96 - $dirX) ** 2 + ($gz / 96 - $dirZ) ** 2;
				if($d < $best){
					$best = $d;
					$index = $i;
				}
			}
			[$gx, $gz] = EndDragonFight::gatewayPosition($index);
			$entity->teleport(new Location($gx + 0.5 - $dirX * 3, 75, $gz + 0.5 - $dirZ * 3, $world, $entity->getLocation()->getYaw(), $entity->getLocation()->getPitch()));
		}
		return true;
	}

	private static function teleportOuter(World $world, Entity $entity, int $tx, int $tz) : void{
		$world->requestChunkPopulation($tx >> Chunk::COORD_BIT_SIZE, $tz >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			static function() use ($world, $entity, $tx, $tz) : void{
				if(!$world->isLoaded() || $entity->isClosed()){
					return;
				}
				// highest end stone near the target, like vanilla's findExitPosition
				$best = null;
				for($dx = -8; $dx <= 8; ++$dx){
					for($dz = -8; $dz <= 8; ++$dz){
						$x = $tx + $dx;
						$z = $tz + $dz;
						if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
							continue;
						}
						$y = $world->getHighestBlockAt($x, $z);
						if($y !== null && $world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::END_STONE && ($best === null || $y > $best[1])){
							$best = [$x, $y, $z];
						}
					}
				}
				if($best === null){
					// no island there: vanilla builds a small end stone platform
					$best = [$tx, 70, $tz];
					for($dx = -2; $dx <= 2; ++$dx){
						for($dz = -2; $dz <= 2; ++$dz){
							$world->setBlockAt($tx + $dx, 70, $tz + $dz, VanillaBlocks::END_STONE());
						}
					}
				}
				[$x, $y, $z] = $best;
				// exit gateway back to the main island, hovering above the landing spot
				$gy = $y + 10;
				$gateway = ExtraVanillaBlocks::get("minecraft:end_gateway");
				if($gateway !== null && $world->getBlockAt($x, $gy, $z)->getTypeId() !== $gateway->getTypeId()){
					$bedrock = VanillaBlocks::BEDROCK();
					foreach([-1, 1] as $dy){
						$world->setBlockAt($x, $gy + $dy, $z, $bedrock);
					}
					$world->setBlockAt($x, $gy, $z, $gateway);
				}
				$entity->teleport(new Location($x + 1.5, $y + 1, $z + 0.5, $world, $entity->getLocation()->getYaw(), $entity->getLocation()->getPitch()));
			},
			static function() : void{}
		);
	}
}
