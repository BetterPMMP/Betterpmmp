<?php

/*
 * BetterPMMP - shield blocking.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function floor;
use function in_array;
use function sqrt;

/**
 * Vanilla shield: raised while sneaking (after 0.25 s), it blocks all melee,
 * projectile and explosion damage coming from the front half. Blocked hits of
 * 3 or more damage cost 1 + floor(damage) durability, melee attackers are
 * pushed back and an axe hit disables the shield for 5 seconds.
 */
class Shield extends SimpleDurableItem{

	public const RAISE_TICKS = 5;
	public const AXE_DISABLE_TICKS = 100;

	public function getCooldownTag() : ?string{
		return "shield";
	}

	/** The shield the player holds (main hand first, then off hand) and where. */
	public static function heldBy(Player $player) : ?Shield{
		$main = $player->getInventory()->getItemInHand();
		if($main instanceof Shield){
			return $main;
		}
		$off = $player->getOffHandInventory()->getItem(0);
		return $off instanceof Shield ? $off : null;
	}

	/** Blocks the damage if it comes from the front; returns whether it was blocked. */
	public static function tryBlock(Player $player, EntityDamageEvent $source) : bool{
		if(!in_array($source->getCause(), [EntityDamageEvent::CAUSE_ENTITY_ATTACK, EntityDamageEvent::CAUSE_PROJECTILE, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION], true)
			|| !$source instanceof EntityDamageByEntityEvent){
			return false;
		}
		$from = $source instanceof EntityDamageByChildEntityEvent ? $source->getChild() : $source->getDamager();
		if($from === null){
			return false;
		}
		$pos = $player->getPosition();
		$d = $from->getPosition()->subtractVector($pos);
		$len = sqrt($d->x * $d->x + $d->z * $d->z);
		$look = $player->getDirectionVector();
		$lookLen = sqrt($look->x * $look->x + $look->z * $look->z);
		if($len < 0.0001 || $lookLen < 0.0001 || ($d->x * $look->x + $d->z * $look->z) / ($len * $lookLen) <= 0){
			return false; // from behind
		}

		$world = $player->getWorld();
		$world->addSound($pos, new VanillaSound(LevelSoundEvent::ITEM_SHIELD_BLOCK));
		$damage = $source->getBaseDamage();
		if($damage >= 3 && $player->hasFiniteResources()){
			self::damageShield($player, 1 + (int) floor($damage));
		}
		$attacker = $source->getDamager();
		if($source->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK && $attacker instanceof Living){
			$attacker->knockBack($attacker->getPosition()->x - $pos->x, $attacker->getPosition()->z - $pos->z, 0.5);
			$weapon = $attacker instanceof \pocketmine\entity\Human ? $attacker->getInventory()->getItemInHand() : null;
			if($weapon instanceof Axe){
				$player->disableShield(self::AXE_DISABLE_TICKS);
				$world->addSound($pos, new VanillaSound(LevelSoundEvent::BREAK));
			}
		}
		return true;
	}

	private static function damageShield(Player $player, int $amount) : void{
		$main = $player->getInventory()->getItemInHand();
		if($main instanceof Shield){
			$main->applyDamage($amount);
			$player->getInventory()->setItemInHand($main);
			return;
		}
		$off = $player->getOffHandInventory()->getItem(0);
		if($off instanceof Shield){
			$off->applyDamage($amount);
			$player->getOffHandInventory()->setItem(0, $off);
		}
	}
}
