<?php

/*
 * BetterPMMP - Bedrock effects the core does not ship.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\effect;

use pocketmine\color\Color;

/**
 * Slow Falling, Bad Omen, Hero of the Village, Trial Omen, Wind Charged,
 * Weaving, Oozing, Infested and Raid Omen with their Bedrock ids. Their
 * behaviour lives where vanilla has it (fall damage, raids, trial spawners,
 * death and hurt effects).
 */
final class ExtraEffects{

	/** @var array<string, Effect>|null */
	private static ?array $all = null;

	/** @return array<string, Effect> name => effect */
	public static function all() : array{
		return self::$all ??= [
			"slow_falling" => new Effect("%potion.slowFalling", new Color(0xf7, 0xf8, 0xe0)),
			"bad_omen" => new Effect("%effect.badOmen", new Color(0x0b, 0x61, 0x38), true, 120000, false),
			"village_hero" => new Effect("%effect.villageHero", new Color(0x44, 0xff, 0x44), false, 48000, false),
			"trial_omen" => new Effect("%effect.trialOmen", new Color(0x16, 0xa6, 0xa6), true, 18000, false),
			"wind_charged" => new Effect("%effect.windCharged", new Color(0xbd, 0xc9, 0xff), true),
			"weaving" => new Effect("%effect.weaving", new Color(0x78, 0x69, 0x5a), true),
			"oozing" => new Effect("%effect.oozing", new Color(0x99, 0xff, 0xa3), true),
			"infested" => new Effect("%effect.infested", new Color(0x8c, 0x9b, 0x8c), true),
			"raid_omen" => new Effect("%effect.raidOmen", new Color(0xde, 0x40, 0x58), true, 600, false),
		];
	}

	public static function get(string $name) : Effect{
		return self::all()[$name] ?? throw new \InvalidArgumentException("Unknown effect $name");
	}

	/** @return array<string, int> name => Bedrock effect id */
	public static function ids() : array{
		return ["slow_falling" => 27, "bad_omen" => 28, "village_hero" => 29, "trial_omen" => 31, "wind_charged" => 32,
			"weaving" => 33, "oozing" => 34, "infested" => 35, "raid_omen" => 36];
	}

	public static function has(\pocketmine\entity\Living $entity, string $name) : bool{
		return $entity->getEffects()->has(self::get($name));
	}

	public static function level(\pocketmine\entity\Living $entity, string $name) : int{
		$instance = $entity->getEffects()->get(self::get($name));
		return $instance === null ? 0 : $instance->getEffectLevel();
	}
}
