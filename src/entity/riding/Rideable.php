<?php

/*
 * BetterPMMP - something that can be ridden.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\entity\Entity;
use pocketmine\math\Vector3;

interface Rideable{

	public function getMaxRiders() : int;

	/** Seat position relative to the vehicle's position. */
	public function getSeatPosition(int $seat) : Vector3;

	/** Whether the driver steers this vehicle (client-side movement is accepted). */
	public function isControlledByDriver() : bool;

	public function onRiderMount(Entity $rider, int $seat) : void;

	public function onRiderDismount(Entity $rider) : void;

	/**
	 * Steering input of the driver (from PlayerAuthInput): forward/strafe in
	 * -1..1 and the driver's yaw. Used when the client does not move the
	 * vehicle itself.
	 */
	public function setDriverInput(float $forward, float $strafe, float $yaw, bool $jumping) : void;
}
