<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\entity\effect;

use pocketmine\color\Color;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\lang\Translatable;
use pocketmine\utils\NotCloneable;
use pocketmine\utils\NotSerializable;

class Effect{
	// ---- BetterPMMP: API 3 effect ids and lookups for plugins in compatibility mode ----
	/** @deprecated API 3 */
	public const SPEED = 1;
	/** @deprecated API 3 */
	public const SLOWNESS = 2;
	/** @deprecated API 3 */
	public const HASTE = 3;
	/** @deprecated API 3 */
	public const MINING_FATIGUE = 4;
	/** @deprecated API 3 */
	public const STRENGTH = 5;
	/** @deprecated API 3 */
	public const INSTANT_HEALTH = 6;
	/** @deprecated API 3 */
	public const INSTANT_DAMAGE = 7;
	/** @deprecated API 3 */
	public const JUMP_BOOST = 8;
	/** @deprecated API 3 */
	public const NAUSEA = 9;
	/** @deprecated API 3 */
	public const REGENERATION = 10;
	/** @deprecated API 3 */
	public const RESISTANCE = 11;
	/** @deprecated API 3 */
	public const FIRE_RESISTANCE = 12;
	/** @deprecated API 3 */
	public const WATER_BREATHING = 13;
	/** @deprecated API 3 */
	public const INVISIBILITY = 14;
	/** @deprecated API 3 */
	public const BLINDNESS = 15;
	/** @deprecated API 3 */
	public const NIGHT_VISION = 16;
	/** @deprecated API 3 */
	public const HUNGER = 17;
	/** @deprecated API 3 */
	public const WEAKNESS = 18;
	/** @deprecated API 3 */
	public const POISON = 19;
	/** @deprecated API 3 */
	public const WITHER = 20;
	/** @deprecated API 3 */
	public const HEALTH_BOOST = 21;
	/** @deprecated API 3 */
	public const ABSORPTION = 22;
	/** @deprecated API 3 */
	public const SATURATION = 23;
	/** @deprecated API 3 */
	public const LEVITATION = 24;
	/** @deprecated API 3 */
	public const FATAL_POISON = 25;
	/** @deprecated API 3 */
	public const CONDUIT_POWER = 26;
	/** @deprecated API 3 */
	public const SLOW_FALLING = 27;
	/** @deprecated API 3 */
	public const BAD_OMEN = 28;
	/** @deprecated API 3 */
	public const VILLAGE_HERO = 29;
	/** @deprecated API 3 */
	public const DARKNESS = 30;

	/** @deprecated API 3: Effect::getEffect(Effect::SPEED) */
	public static function getEffect(int $id) : ?Effect{
		return \pocketmine\data\bedrock\EffectIdMap::getInstance()->fromId($id);
	}

	/** @deprecated API 3: Effect::getEffectByName("speed") */
	public static function getEffectByName(string $name) : ?Effect{
		return StringToEffectParser::getInstance()->parse($name);
	}

	/** @deprecated API 3: the numeric id of this effect */
	public function getId() : int{
		return \pocketmine\data\bedrock\EffectIdMap::getInstance()->toId($this);
	}

	use NotCloneable;
	use NotSerializable;

	/**
	 * @param Translatable|string $name       Translation key used for effect name
	 * @param Color               $color      Color of bubbles given by this effect
	 * @param bool                $bad        Whether the effect is harmful
	 * @param bool                $hasBubbles Whether the effect has potion bubbles. Some do not (e.g. Instant Damage has its own particles instead of bubbles)
	 */
	public function __construct(
		protected Translatable|string $name,
		protected Color $color,
		protected bool $bad = false,
		private int $defaultDuration = 600,
		protected bool $hasBubbles = true
	){}

	/**
	 * Returns the translation key used to translate this effect's name.
	 */
	public function getName() : Translatable|string{
		return $this->name;
	}

	/**
	 * Returns a Color object representing this effect's particle colour.
	 */
	public function getColor() : Color{
		return $this->color;
	}

	/**
	 * Returns whether this effect is harmful.
	 * TODO: implement inverse effect results for undead mobs
	 */
	public function isBad() : bool{
		return $this->bad;
	}

	/**
	 * Returns the default duration (in ticks) this effect will apply for if a duration is not specified.
	 */
	public function getDefaultDuration() : int{
		return $this->defaultDuration;
	}

	/**
	 * Returns whether this effect will give the subject potion bubbles.
	 */
	public function hasBubbles() : bool{
		return $this->hasBubbles;
	}

	/**
	 * Returns whether the effect will do something on the current tick.
	 * @deprecated Implement {@link getApplyInterval()} instead
	 */
	public function canTick(EffectInstance $instance) : bool{
		$interval = $this->getApplyInterval($instance);
		return $interval > 0 && $instance->getDuration() % $interval === 0;
	}

	/**
	 * Returns after how many ticks {@link applyEffect()} will be called.
	 * If this returns 0, the effect will not be ticked.
	 * If it returns 1, it will be ticked every tick.
	 */
	public function getApplyInterval(EffectInstance $instance) : int{
		return 0;
	}

	/**
	 * Applies effect results to an entity. This will not be called unless canTick() returns true.
	 */
	public function applyEffect(Living $entity, EffectInstance $instance, float $potency = 1.0, ?Entity $source = null) : void{

	}

	/**
	 * Applies effects to the entity when the effect is first added.
	 */
	public function add(Living $entity, EffectInstance $instance) : void{

	}

	/**
	 * Removes the effect from the entity, resetting any changed values back to their original defaults.
	 */
	public function remove(Living $entity, EffectInstance $instance) : void{

	}
}
