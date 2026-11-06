<?php

/*
 * BetterPMMP - shared vehicle behaviour.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function spl_object_id;

/**
 * For entities implementing {@link Rideable}: sends the rider links to new
 * viewers, lets riders off when the vehicle goes away, stores the driver's
 * input and accepts the driver client's own movement of the vehicle.
 */
trait RideableTrait{

	protected float $driverForward = 0.0;
	protected float $driverStrafe = 0.0;
	protected float $driverYaw = 0.0;
	protected bool $driverJumping = false;
	/** Server tick of the last movement the driving client sent for this vehicle. */
	protected int $lastClientMove = -100;

	public function spawnTo(Player $player) : void{
		parent::spawnTo($player);
		if(isset($this->hasSpawned[spl_object_id($player)])){
			RidingManager::sendLinksTo($this, $player);
		}
	}

	protected function onDispose() : void{
		RidingManager::ejectAll($this);
		parent::onDispose();
	}

	public function setDriverInput(float $forward, float $strafe, float $yaw, bool $jumping) : void{
		$this->driverForward = $forward;
		$this->driverStrafe = $strafe;
		$this->driverYaw = $yaw;
		$this->driverJumping = $jumping;
	}

	public function onRiderMount(Entity $rider, int $seat) : void{
		$this->networkPropertiesDirty = true;
	}

	public function onRiderDismount(Entity $rider) : void{
		$this->networkPropertiesDirty = true;
		$this->driverForward = $this->driverStrafe = 0.0;
	}

	/** Whether the driving client moved the vehicle recently (then the server does not simulate it). */
	protected function isClientDriven() : bool{
		return $this->isControlledByDriver() && RidingManager::getDriver($this) instanceof Player
			&& $this->getWorld()->getServer()->getTick() - $this->lastClientMove < 10;
	}

	/** Movement of the vehicle sent by the driver's client (MoveActorAbsolute). */
	public function applyClientMovement(Player $driver, Vector3 $position, float $yaw, float $pitch) : void{
		if(RidingManager::getDriver($this) !== $driver || !$this->isControlledByDriver()){
			return;
		}
		if($position->distanceSquared($this->location) > 100){
			return; // ignore teleport-like jumps
		}
		$this->lastClientMove = $this->getWorld()->getServer()->getTick();
		$this->setPositionAndRotation($position, $yaw, $pitch);
		$this->updateMovement();
	}
}
