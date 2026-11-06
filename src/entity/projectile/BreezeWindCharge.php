<?php

/*
 * BetterPMMP - the breeze's wind charge.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/** Shot by breezes: a bit bigger burst and stronger knockback than the player's. */
class BreezeWindCharge extends WindCharge{

	protected float $burstRadius = 3.0;
	protected float $knockback = 1.3;

	public static function getNetworkTypeId() : string{ return EntityIds::BREEZE_WIND_CHARGE_PROJECTILE; }
	protected function burstParticle() : int{ return LevelEvent::PARTICLE_BREEZE_WIND_EXPLOSION; }
	protected function burstSound() : string{ return LevelSoundEvent::BREEZE_WIND_CHARGE_BURST; }
}
