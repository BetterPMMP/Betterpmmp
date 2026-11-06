<?php

/*
 * BetterPMMP - vanilla target block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\DirectionalRedstoneSource;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Trident;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\world\World;
use function abs;
use function ceil;
use function max;
use function min;

/**
 * Emits a signal when hit by a projectile: the closer to the centre of the hit
 * face, the stronger (1-15). Lasts 20 game ticks for arrows and tridents, 8
 * for other projectiles. Bedrock has no block state for it, so the power is
 * kept in memory.
 */
class VanillaTarget extends GenericBedrockBlock implements DirectionalRedstoneSource{

	/**
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private static array $power = [];

	private function key() : int{
		return World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	public function getOutputSignalStrength() : int{
		return $this->position->isValid() ? (self::$power[$this->position->getWorld()->getId()][$this->key()] ?? 0) : 0;
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->getOutputSignalStrength();
	}

	public function getStrongPowerTowards(int $towards) : int{
		// vanilla: the target block powers adjacent components only, it does not strongly power blocks
		return 0;
	}

	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		$hit = $hitResult->getHitVector()->subtract($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		$axis = Facing::axis($hitResult->getHitFace());
		$dx = $axis === Axis::X ? 0.0 : abs($hit->x - 0.5);
		$dy = $axis === Axis::Y ? 0.0 : abs($hit->y - 0.5);
		$dz = $axis === Axis::Z ? 0.0 : abs($hit->z - 0.5);
		$distance = max($dx, $dy, $dz);
		$strength = (int) max(1, ceil(15 * min(1.0, max(0.0, (0.5 - $distance) / 0.5))));
		$world = $this->position->getWorld();
		self::$power[$world->getId()][$this->key()] = $strength;
		$world->notifyRedstoneChange($this->position);
		$world->scheduleDelayedBlockUpdate($this->position, $projectile instanceof Arrow || $projectile instanceof Trident ? 20 : 8);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if(isset(self::$power[$world->getId()][$this->key()])){
			unset(self::$power[$world->getId()][$this->key()]);
			$world->notifyRedstoneChange($this->position);
		}
	}
}
