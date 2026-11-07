<?php

/*
 * BetterPMMP - ominous bottle (1.21).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\ExtraEffects;
use pocketmine\entity\Living;
use pocketmine\player\Player;
use function max;
use function min;

/**
 * Drinking it gives Bad Omen I-V for 100 minutes (level kept in the item).
 * Bad Omen turns into Raid Omen in a village and into Trial Omen at a trial
 * spawner (see {@link \pocketmine\world\raid\RaidManager}).
 */
class OminousBottle extends GenericBedrockItem implements ConsumableItem{

	private const TAG_LEVEL = "BetterPMMPOmenLevel";

	public function getOmenLevel() : int{
		return max(0, min(4, $this->getNamedTag()->getInt(self::TAG_LEVEL, 0)));
	}

	public function setOmenLevel(int $level) : self{
		$tag = $this->getNamedTag();
		$tag->setInt(self::TAG_LEVEL, max(0, min(4, $level)));
		$this->setNamedTag($tag);
		return $this;
	}

	public function getResidue() : Item{ return VanillaItems::AIR(); }
	public function getAdditionalEffects() : array{ return []; }
	public function canStartUsingItem(Player $player) : bool{ return true; }

	public function onConsume(Living $consumer) : void{
		$consumer->getEffects()->add(new EffectInstance(ExtraEffects::get("bad_omen"), 120000, $this->getOmenLevel(), false));
		$consumer->getWorld()->addSound($consumer->getPosition(), new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::APPLY_EFFECT_BAD_OMEN));
	}
}
