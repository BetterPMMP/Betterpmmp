<?php

/*
 * BetterPMMP - smite, bane of arthropods, impaling.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\enchantment;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function in_array;
use function mt_rand;

/** Extra melee damage (2.5 per level) against one group of mobs, like vanilla. */
class MobTypeDamageEnchantment extends MeleeWeaponEnchantment{

	public const UNDEAD = [EntityIds::ZOMBIE, EntityIds::HUSK, EntityIds::DROWNED, EntityIds::ZOMBIE_VILLAGER, EntityIds::ZOMBIE_PIGMAN, EntityIds::ZOGLIN,
		EntityIds::SKELETON, EntityIds::STRAY, EntityIds::BOGGED, EntityIds::PARCHED, EntityIds::WITHER_SKELETON, EntityIds::WITHER, EntityIds::PHANTOM,
		EntityIds::SKELETON_HORSE, EntityIds::ZOMBIE_HORSE];
	public const ARTHROPODS = [EntityIds::SPIDER, EntityIds::CAVE_SPIDER, EntityIds::SILVERFISH, EntityIds::ENDERMITE, EntityIds::BEE];
	public const AQUATIC = [EntityIds::COD, EntityIds::SALMON, EntityIds::PUFFERFISH, EntityIds::TROPICALFISH, EntityIds::SQUID, EntityIds::GLOW_SQUID,
		EntityIds::DOLPHIN, EntityIds::GUARDIAN, EntityIds::ELDER_GUARDIAN, EntityIds::TURTLE, EntityIds::AXOLOTL, EntityIds::DROWNED];

	/**
	 * @param string[] $targets entity ids, or [] for "anything in water or rain" (impaling on Bedrock)
	 */
	public function __construct(string $name, int $rarity, int $maxLevel, \Closure $minPower, int $range, private array $targets, private bool $slows = false){
		parent::__construct($name, $rarity, 0, 0, $maxLevel, $minPower, $range);
	}

	public function isApplicableTo(Entity $victim) : bool{
		if(in_array($victim::getNetworkTypeId(), $this->targets, true)){
			return true;
		}
		// Bedrock impaling: any mob that is in water or rain
		return $this->targets === self::AQUATIC && $victim->isUnderwater();
	}

	public function getDamageBonus(int $enchantmentLevel) : float{
		return 2.5 * $enchantmentLevel;
	}

	public function onPostAttack(Entity $attacker, Entity $victim, int $enchantmentLevel) : void{
		// bane of arthropods slows the victim for 1-1.5 s per level
		if($this->slows && $victim instanceof Living){
			$victim->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::SLOWNESS(), 20 + mt_rand(0, 10 * $enchantmentLevel), 3));
		}
	}
}
