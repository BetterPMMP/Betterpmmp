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

namespace pocketmine\event\entity;

use pocketmine\entity\Entity;

/**
 * Called when an entity is removed from the world. This could be for a variety of reasons, including chunks being
 * unloaded, entity death, etc.
 *
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityDespawnEvent extends EntityEvent{

	public function __construct(Entity $entity){
		$this->entity = $entity;
	}

	// BetterPMMP: API 3 helpers
	public function getType() : int{ return self::legacyEntityId($this->entity); }
	public function isCreature() : bool{ return $this->entity instanceof \pocketmine\entity\Living; }
	public function isHuman() : bool{ return $this->entity instanceof \pocketmine\entity\Human; }
	public function isProjectile() : bool{ return $this->entity instanceof \pocketmine\entity\projectile\Projectile; }
	public function isVehicle() : bool{ return $this->entity instanceof \pocketmine\entity\riding\Rideable && !$this->entity instanceof \pocketmine\entity\Living; }
	public function isItem() : bool{ return $this->entity instanceof \pocketmine\entity\object\ItemEntity; }

	private static function legacyEntityId(\pocketmine\entity\Entity $entity) : int{
		$name = strtoupper(str_replace("minecraft:", "", $entity::getNetworkTypeId()));
		$constant = "pocketmine\\entity\\EntityIds::" . $name;
		return interface_exists("pocketmine\\entity\\EntityIds") && defined($constant) ? (int) constant($constant) : -1;
	}
}
