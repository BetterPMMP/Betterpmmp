<?php

/*
 * This file is part of BetterPMMP.
 * PocketMine-MP is licensed under LGPL-3.0-or-later.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use function max;

/**
 * First-pass native Bedrock spear item. It has tier durability, enchantment
 * support and melee combat behavior. Charged lunge/throw mechanics are handled
 * separately from this item registry implementation.
 */
final class Spear extends TieredTool implements Releasable{
	private const KINETIC_DAMAGE_MIN_RELATIVE_SPEED = 4.6;
	private const KINETIC_KNOCKBACK_MIN_SPEED = 5.1;

	public function getAttackPoints() : int{
		// Bedrock jab damage (one more than the Java values, like Bedrock swords)
		return match($this->tier){
			ToolTier::WOOD, ToolTier::GOLD => 2,
			ToolTier::STONE, ToolTier::COPPER => 3,
			ToolTier::IRON => 4,
			ToolTier::DIAMOND => 5,
			ToolTier::NETHERITE => 6
		};
	}

	/**
	 * Applies the Bedrock kinetic-weapon damage and knockback rules using the
	 * 26.60 item component thresholds (speeds are measured in blocks per second).
	 */
	public function applyKineticAttack(EntityDamageByEntityEvent $event, float $relativeSpeed, float $speed) : void{
		if($relativeSpeed >= self::KINETIC_DAMAGE_MIN_RELATIVE_SPEED){
			// kinetic damage grows with the speed: multiplier x relative speed (blocks per second)
			$event->setBaseDamage(max($event->getBaseDamage(), $this->getKineticDamage($relativeSpeed)));
		}

		if($speed >= self::KINETIC_KNOCKBACK_MIN_SPEED){
			$event->setKnockBack(max($event->getKnockBack(), 0.7));
		}
	}

	public function getKineticDamage(float $relativeSpeed) : float{
		return $this->getKineticDamageMultiplier() * $relativeSpeed;
	}

	/** BetterPMMP: holding "use" charges the spear (see Player::tickSpearCharge) */
	public function canStartUsingItem(\pocketmine\player\Player $player) : bool{
		return true;
	}

	public function getKineticMinSpeed() : float{
		return self::KINETIC_DAMAGE_MIN_RELATIVE_SPEED;
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
