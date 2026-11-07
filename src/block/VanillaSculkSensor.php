<?php

/*
 * BetterPMMP - sculk sensor and calibrated sculk sensor.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\DirectionalRedstoneSource;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\VanillaSound;
use pocketmine\world\World;

/**
 * Phases like vanilla: inactive (0) -> active (1, 30 ticks, gives the signal
 * of the vibration it picked up) -> cooldown (2, 10 ticks) -> inactive. Range
 * 8 blocks, calibrated sensors 16. The signal goes to every side and strongly
 * powers the block below. Kept in memory: Bedrock has no state for the strength.
 */
class VanillaSculkSensor extends GenericBedrockBlock implements DirectionalRedstoneSource{

	/** @var array<int, array<int, int>> world id => block hash => signal */
	private static array $power = [];

	private function key() : int{
		return World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	public function getRange() : int{
		return $this->getBedrockName() === "minecraft:calibrated_sculk_sensor" ? 16 : 8;
	}

	private function phase() : int{
		$tag = $this->getStateData()->getStates()["sculk_sensor_phase"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function isIdle() : bool{
		return $this->phase() === 0;
	}

	public function getLightLevel() : int{
		return $this->phase() === 1 ? 1 : 0;
	}

	private function setPhase(int $phase) : void{
		$this->position->getWorld()->setBlock($this->position, $this->withProperties(["sculk_sensor_phase" => $phase]));
	}

	public function activate(int $strength) : void{
		$world = $this->position->getWorld();
		self::$power[$world->getId()][$this->key()] = $strength;
		$this->setPhase(1);
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::POWER_ON_SCULK_SENSOR));
		$world->notifyRedstoneChange($this->position);
		$world->scheduleDelayedBlockUpdate($this->position, 30);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$phase = $this->phase();
		if($phase === 1){
			unset(self::$power[$world->getId()][$this->key()]);
			$this->setPhase(2);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::POWER_OFF_SCULK_SENSOR));
			$world->notifyRedstoneChange($this->position);
			$world->scheduleDelayedBlockUpdate($this->position, 10);
		}elseif($phase === 2){
			$this->setPhase(0);
		}
	}

	private function signal() : int{
		return $this->position->isValid() ? (self::$power[$this->position->getWorld()->getId()][$this->key()] ?? 0) : 0;
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->signal();
	}

	public function getStrongPowerTowards(int $towards) : int{
		return $towards === Facing::DOWN ? $this->signal() : 0;
	}
}
