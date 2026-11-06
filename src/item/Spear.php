<?php

/*
 * This file is part of BetterPMMP.
 * PocketMine-MP is licensed under LGPL-3.0-or-later.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;

/**
 * First-pass native Bedrock spear item. It has tier durability, enchantment
 * support and melee combat behavior. Charged lunge/throw mechanics are handled
 * separately from this item registry implementation.
 */
final class Spear extends TieredTool{
	private const KINETIC_DAMAGE_MIN_RELATIVE_SPEED = 4.6;
	private const KINETIC_KNOCKBACK_MIN_SPEED = 5.1;

	public function getAttackPoints() : int{
		return match($this->tier){
			ToolTier::WOOD, ToolTier::GOLD => 1,
			ToolTier::STONE, ToolTier::COPPER => 2,
			ToolTier::IRON => 3,
			ToolTier::DIAMOND => 4,
			ToolTier::NETHERITE => 5
		};
	}

	/**
	 * Applies the Bedrock kinetic-weapon damage and knockback rules using the
	 * 26.60 item component thresholds (speeds are measured in blocks per second).
	 */
	public function applyKineticAttack(EntityDamageByEntityEvent $event, float $relativeSpeed, float $speed) : void{
		if($relativeSpeed >= self::KINETIC_DAMAGE_MIN_RELATIVE_SPEED){
			$event->setBaseDamage($event->getBaseDamage() * $this->getKineticDamageMultiplier());
		}

		if($speed >= self::KINETIC_KNOCKBACK_MIN_SPEED){
			$event->setKnockBack(max($event->getKnockBack(), 0.7));
		}
	}

	private function getKineticDamageMultiplier() : float{
		return match($this->tier){
			ToolTier::WOOD, ToolTier::GOLD => 0.70,
			ToolTier::STONE, ToolTier::COPPER => 0.82,
			ToolTier::IRON => 0.95,
			ToolTier::DIAMOND => 1.075,
			ToolTier::NETHERITE => 1.20
		};
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->applyDamage(1);
	}
}
