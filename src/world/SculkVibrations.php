<?php

/*
 * BetterPMMP - vibrations and sculk sensors.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\GenericBedrockBlock;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaSculkSensor;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use function floor;
use function max;
use function sqrt;

/**
 * Vanilla vibrations: walking (not sneaking), breaking and placing blocks,
 * hits and landing projectiles make vibrations. A sculk sensor within 8
 * blocks (16 for calibrated ones) picks the closest one up and gives a
 * redstone signal of 15 minus the distance scaled to its range.
 */
final class SculkVibrations{

	/** @var array<int, true>|null */
	private static ?array $sensorStates = null;
	/** @var array<int, Vector3> player id => position at the last step check */
	private static array $lastSteps = [];

	private static function isSensorState(int $id) : bool{
		if(self::$sensorStates === null){
			self::$sensorStates = [];
			foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $state){
				if($state instanceof VanillaSculkSensor){
					self::$sensorStates[$stateId] = true;
				}
			}
		}
		return isset(self::$sensorStates[$id]);
	}

	/** Footsteps: every 10 ticks a player who walked on the ground without sneaking vibrates. */
	public static function tick(World $world) : void{
		if($world->getServer()->getTick() % 10 !== 0){
			return;
		}
		foreach($world->getPlayers() as $player){
			$pos = $player->getPosition();
			$last = self::$lastSteps[$player->getId()] ?? null;
			self::$lastSteps[$player->getId()] = $pos->asVector3();
			if($last === null || $player->isSneaking() || $player->isSpectator() || !$player->isOnGround() || $last->distanceSquared($pos) < 0.25){
				continue;
			}
			self::emit($world, $pos);
		}
	}

	public static function forget(Player $player) : void{
		unset(self::$lastSteps[$player->getId()]);
	}

	/** A vibration at a position: the nearest idle sensor in range activates. */
	public static function emit(World $world, Vector3 $at) : void{
		$best = null;
		$bestDistance = 0.0;
		$minCx = (int) floor($at->x - 16) >> Chunk::COORD_BIT_SIZE;
		$maxCx = (int) floor($at->x + 16) >> Chunk::COORD_BIT_SIZE;
		$minCz = (int) floor($at->z - 16) >> Chunk::COORD_BIT_SIZE;
		$maxCz = (int) floor($at->z + 16) >> Chunk::COORD_BIT_SIZE;
		for($cx = $minCx; $cx <= $maxCx; ++$cx){
			for($cz = $minCz; $cz <= $maxCz; ++$cz){
				if(!$world->isChunkLoaded($cx, $cz) || ($chunk = $world->getChunk($cx, $cz)) === null){
					continue;
				}
				foreach($chunk->getSubChunks() as $sy => $sub){
					if(($sy << 4) > $at->y + 16 || ($sy << 4) + 15 < $at->y - 16){
						continue;
					}
					$has = false;
					foreach($sub->getBlockLayers() as $layer){
						foreach($layer->getPalette() as $id){
							if(self::isSensorState($id)){
								$has = true;
								break 2;
							}
						}
					}
					if(!$has){
						continue;
					}
					for($bx = 0; $bx < 16; ++$bx){
						for($by = 0; $by < 16; ++$by){
							for($bz = 0; $bz < 16; ++$bz){
								if(!self::isSensorState($sub->getBlockStateId($bx, $by, $bz))){
									continue;
								}
								$pos = new Vector3(($cx << 4) + $bx, ($sy << 4) + $by, ($cz << 4) + $bz);
								$block = $world->getBlock($pos);
								if(!$block instanceof VanillaSculkSensor || !$block->isIdle()){
									continue;
								}
								$d = sqrt($pos->add(0.5, 0.5, 0.5)->distanceSquared($at));
								if($d <= $block->getRange() && ($best === null || $d < $bestDistance)){
									$best = $block;
									$bestDistance = $d;
								}
							}
						}
					}
				}
			}
		}
		$best?->activate(max(1, 15 - (int) floor(15 * $bestDistance / $best->getRange())));
	}
}
