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

namespace pocketmine\item\enchantment;

use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pocketmine\lang\Translatable;
use pocketmine\utils\NotCloneable;
use pocketmine\utils\NotSerializable;
use pocketmine\utils\Utils;

/**
 * Manages enchantment type data.
 */
class Enchantment{
	// ---- BetterPMMP: API 3 enchantment ids and lookups for plugins in compatibility mode ----
	/** @deprecated API 3 */
	public const PROTECTION = 0;
	/** @deprecated API 3 */
	public const FIRE_PROTECTION = 1;
	/** @deprecated API 3 */
	public const FEATHER_FALLING = 2;
	/** @deprecated API 3 */
	public const BLAST_PROTECTION = 3;
	/** @deprecated API 3 */
	public const PROJECTILE_PROTECTION = 4;
	/** @deprecated API 3 */
	public const THORNS = 5;
	/** @deprecated API 3 */
	public const RESPIRATION = 6;
	/** @deprecated API 3 */
	public const DEPTH_STRIDER = 7;
	/** @deprecated API 3 */
	public const AQUA_AFFINITY = 8;
	/** @deprecated API 3 */
	public const SHARPNESS = 9;
	/** @deprecated API 3 */
	public const SMITE = 10;
	/** @deprecated API 3 */
	public const BANE_OF_ARTHROPODS = 11;
	/** @deprecated API 3 */
	public const KNOCKBACK = 12;
	/** @deprecated API 3 */
	public const FIRE_ASPECT = 13;
	/** @deprecated API 3 */
	public const LOOTING = 14;
	/** @deprecated API 3 */
	public const EFFICIENCY = 15;
	/** @deprecated API 3 */
	public const SILK_TOUCH = 16;
	/** @deprecated API 3 */
	public const UNBREAKING = 17;
	/** @deprecated API 3 */
	public const FORTUNE = 18;
	/** @deprecated API 3 */
	public const POWER = 19;
	/** @deprecated API 3 */
	public const PUNCH = 20;
	/** @deprecated API 3 */
	public const FLAME = 21;
	/** @deprecated API 3 */
	public const INFINITY = 22;
	/** @deprecated API 3 */
	public const LUCK_OF_THE_SEA = 23;
	/** @deprecated API 3 */
	public const LURE = 24;
	/** @deprecated API 3 */
	public const FROST_WALKER = 25;
	/** @deprecated API 3 */
	public const MENDING = 26;
	/** @deprecated API 3 */
	public const BINDING = 27;
	/** @deprecated API 3 */
	public const VANISHING = 28;
	/** @deprecated API 3 */
	public const IMPALING = 29;
	/** @deprecated API 3 */
	public const RIPTIDE = 30;
	/** @deprecated API 3 */
	public const LOYALTY = 31;
	/** @deprecated API 3 */
	public const CHANNELING = 32;
	/** @deprecated API 3 */
	public const MULTISHOT = 33;
	/** @deprecated API 3 */
	public const PIERCING = 34;
	/** @deprecated API 3 */
	public const QUICK_CHARGE = 35;
	/** @deprecated API 3 */
	public const SOUL_SPEED = 36;
	/** @deprecated API 3 */
	public const SWIFT_SNEAK = 37;

	/** @deprecated API 3: Enchantment::getEnchantment(Enchantment::SHARPNESS) */
	public static function getEnchantment(int $id) : ?Enchantment{
		return \pocketmine\data\bedrock\EnchantmentIdMap::getInstance()->fromId($id);
	}

	/** @deprecated API 3: Enchantment::getEnchantmentByName("sharpness") */
	public static function getEnchantmentByName(string $name) : ?Enchantment{
		return StringToEnchantmentParser::getInstance()->parse($name);
	}

	/** @deprecated API 3: the numeric id of this enchantment */
	public function getId() : int{
		return \pocketmine\data\bedrock\EnchantmentIdMap::getInstance()->toId($this);
	}

	use NotCloneable;
	use NotSerializable;

	/** @var \Closure(int $level) : int $minEnchantingPower */
	private \Closure $minEnchantingPower;

	/**
	 * @phpstan-param null|(\Closure(int $level) : int) $minEnchantingPower
	 *
	 * @param int $primaryItemFlags     @deprecated
	 * @param int $secondaryItemFlags   @deprecated
	 * @param int $enchantingPowerRange Value used to calculate the maximum enchanting power (minEnchantingPower + enchantingPowerRange)
	 */
	public function __construct(
		private Translatable|string $name,
		private int $rarity,
		private int $primaryItemFlags,
		private int $secondaryItemFlags,
		private int $maxLevel,
		?\Closure $minEnchantingPower = null,
		private int $enchantingPowerRange = 50
	){
		$this->minEnchantingPower = $minEnchantingPower ?? fn(int $level) : int => 1;

		Utils::validateCallableSignature(new CallbackType(
			new ReturnType("int"),
			new ParameterType("level", "int")
		), $this->minEnchantingPower);
	}

	/**
	 * Returns a translation key for this enchantment's name.
	 */
	public function getName() : Translatable|string{
		return $this->name;
	}

	/**
	 * Returns an int constant indicating how rare this enchantment type is.
	 */
	public function getRarity() : int{
		return $this->rarity;
	}

	/**
	 * Returns a bitset indicating what item types can have this item applied from an enchanting table.
	 *
	 * @deprecated
	 * @see AvailableEnchantmentRegistry::getPrimaryItemTags()
	 */
	public function getPrimaryItemFlags() : int{
		return $this->primaryItemFlags;
	}

	/**
	 * Returns a bitset indicating what item types cannot have this item applied from an enchanting table, but can from
	 * an anvil.
	 *
	 * @deprecated
	 * @see AvailableEnchantmentRegistry::getSecondaryItemTags()
	 */
	public function getSecondaryItemFlags() : int{
		return $this->secondaryItemFlags;
	}

	/**
	 * Returns whether this enchantment can apply to the item type from an enchanting table.
	 *
	 * @deprecated
	 * @see AvailableEnchantmentRegistry
	 */
	public function hasPrimaryItemType(int $flag) : bool{
		return ($this->primaryItemFlags & $flag) !== 0;
	}

	/**
	 * Returns whether this enchantment can apply to the item type from an anvil, if it is not a primary item.
	 *
	 * @deprecated
	 * @see AvailableEnchantmentRegistry
	 */
	public function hasSecondaryItemType(int $flag) : bool{
		return ($this->secondaryItemFlags & $flag) !== 0;
	}

	/**
	 * Returns the maximum level of this enchantment that can be found on an enchantment table.
	 */
	public function getMaxLevel() : int{
		return $this->maxLevel;
	}

	/**
	 * Returns whether this enchantment can be applied to the item along with the given enchantment.
	 */
	public function isCompatibleWith(Enchantment $other) : bool{
		return IncompatibleEnchantmentRegistry::getInstance()->areCompatible($this, $other);
	}

	/**
	 * Returns the minimum enchanting power value required for the particular level of the enchantment
	 * to be available in an enchanting table.
	 *
	 * Enchanting power is a random value based on the number of bookshelves around an enchanting table
	 * and the enchantability of the item being enchanted. It is only used when determining the available
	 * enchantments for the enchantment options.
	 */
	public function getMinEnchantingPower(int $level) : int{
		return ($this->minEnchantingPower)($level);
	}

	/**
	 * Returns the maximum enchanting power value allowed for the particular level of the enchantment
	 * to be available in an enchanting table.
	 *
	 * Enchanting power is a random value based on the number of bookshelves around an enchanting table
	 * and the enchantability of the item being enchanted. It is only used when determining the available
	 * enchantments for the enchantment options.
	 */
	public function getMaxEnchantingPower(int $level) : int{
		return $this->getMinEnchantingPower($level) + $this->enchantingPowerRange;
	}
}
