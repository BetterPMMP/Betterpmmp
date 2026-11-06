<?php

/*
 * BetterPMMP - what a pet owner fights, for tamed wolves.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\player\Player;

/** Vanilla OwnerHurtByTargetGoal / OwnerHurtTargetGoal: wolves attack what their owner hits or is hit by. */
final class PetTargets{

	/** @var array<int, array{Living, int}> owner entity id => [target, server tick] */
	private static array $targets = [];

	public static function record(Player $owner, Entity $target) : void{
		if($target instanceof Living && $target !== $owner && !($target instanceof TameablePet && $target->isOwnedBy($owner))){
			self::$targets[$owner->getId()] = [$target, $owner->getServer()->getTick()];
		}
	}

	public static function get(Player $owner) : ?Living{
		$entry = self::$targets[$owner->getId()] ?? null;
		if($entry === null){
			return null;
		}
		[$target, $tick] = $entry;
		if($target->isClosed() || !$target->isAlive() || $owner->getServer()->getTick() - $tick > 400){
			unset(self::$targets[$owner->getId()]);
			return null;
		}
		return $target;
	}
}
