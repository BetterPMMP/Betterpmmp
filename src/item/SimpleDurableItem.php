<?php

/*
 * BetterPMMP - a durable item with vanilla durability and attack damage.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Entity;

class SimpleDurableItem extends Durable{

	/** @param string[] $enchantmentTags */
	public function __construct(ItemIdentifier $identifier, string $name, private int $maxDurability, private int $attackPoints = 1, private int $maxStackSize = 1, array $enchantmentTags = []){
		parent::__construct($identifier, $name, $enchantmentTags);
	}

	public function getMaxDurability() : int{
		return $this->maxDurability;
	}

	public function getAttackPoints() : int{
		return $this->attackPoints;
	}

	public function getMaxStackSize() : int{
		return $this->maxStackSize;
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->attackPoints > 1 && $this->applyDamage(1);
	}
}
