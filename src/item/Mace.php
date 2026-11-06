<?php

/*
 * BetterPMMP - mace with the vanilla smash attack.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\enchantment\ExtraEnchantments;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function max;
use function min;

/**
 * Smash attack: hitting a mob after falling more than 1.5 blocks adds
 * 4 damage per block for the first 3 blocks, 2 per block for the next 5 and
 * 1 per block after that (Density: +0.5 per block and level), cancels the
 * attacker's fall damage, knocks back mobs around the target and, with
 * Wind Burst, launches the attacker back up.
 */
class Mace extends SimpleDurableItem{

	public const MODIFIER_SMASH = 1001;
	public const MIN_FALL = 1.5;

	public static function smashBonus(float $fallDistance, int $density = 0) : float{
		if($fallDistance <= self::MIN_FALL){
			return 0.0;
		}
		$first = min($fallDistance, 3.0);
		$second = min(max($fallDistance - 3.0, 0.0), 5.0);
		$rest = max($fallDistance - 8.0, 0.0);
		return 4 * $first + 2 * $second + $rest + 0.5 * $density * $fallDistance;
	}

	/** Applies the smash bonus to an attack about to happen; returns whether it is a smash. */
	public function prepareSmash(Player $attacker, EntityDamageByEntityEvent $ev, float $fallDistance) : bool{
		if($fallDistance <= self::MIN_FALL || $attacker->isFlying() || $attacker->isGliding()){
			return false;
		}
		$bonus = self::smashBonus($fallDistance, $this->getEnchantmentLevel(ExtraEnchantments::get("density")));
		$ev->setModifier($bonus, self::MODIFIER_SMASH);
		return true;
	}

	/** After a successful smash: no fall damage, area knockback, sounds, wind burst. */
	public function finishSmash(Player $attacker, Entity $target, float $fallDistance) : void{
		$attacker->resetFallDistance();
		$world = $attacker->getWorld();
		$world->addSound($target->getPosition(), new VanillaSound($fallDistance > 5 ? LevelSoundEvent::MACE_HEAVY_SMASH_GROUND : LevelSoundEvent::MACE_SMASH_GROUND));
		$center = $target->getPosition();
		foreach($world->getNearbyEntities($target->getBoundingBox()->expandedCopy(3.5, 3.5, 3.5), $target) as $entity){
			if($entity === $attacker || !$entity instanceof Living || $entity->getPosition()->distanceSquared($center) > 3.5 * 3.5){
				continue;
			}
			$d = $entity->getPosition()->subtractVector($center);
			$len = max(0.1, $d->length());
			$strength = (3.5 - $len) * 0.7 * ($fallDistance > 5 ? 2 : 1);
			$entity->setMotion($entity->getMotion()->addVector(new Vector3($d->x / $len * $strength * 0.5, 0.6 * $strength * 0.3, $d->z / $len * $strength * 0.5)));
		}
		$windBurst = $this->getEnchantmentLevel(ExtraEnchantments::get("wind_burst"));
		if($windBurst > 0){
			// vanilla: about 7 blocks up for level I, +3.5 per further level
			$attacker->setMotion(new Vector3(0, 1.2 + 0.35 * ($windBurst - 1), 0));
			$world->addSound($attacker->getPosition(), new VanillaSound(LevelSoundEvent::WIND_CHARGE_BURST));
		}else{
			$motion = $attacker->getMotion();
			$attacker->setMotion(new Vector3($motion->x, 0.01, $motion->z));
		}
	}
}
