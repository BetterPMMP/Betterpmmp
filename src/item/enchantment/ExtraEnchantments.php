<?php

/*
 * BetterPMMP - vanilla enchantments the core does not ship.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\enchantment;

use pocketmine\data\bedrock\EnchantmentIds;
use pocketmine\item\enchantment\ItemEnchantmentTags as Tags;

/**
 * Depth Strider, Smite, Bane of Arthropods, Looting, Luck of the Sea, Lure,
 * Curse of Binding, Impaling, Riptide, Loyalty, Channeling, Multishot,
 * Piercing, Quick Charge, Soul Speed, Wind Burst, Density and Breach with their Bedrock ids, rarities,
 * levels, enchanting costs, items and incompatibilities.
 */
final class ExtraEnchantments{

	/** @var array<string, Enchantment>|null */
	private static ?array $all = null;

	/** @return array<string, Enchantment> name => enchantment */
	public static function all() : array{
		if(self::$all !== null){
			return self::$all;
		}
		$e = fn(string $name, int $rarity, int $max, \Closure $power, int $range) => new Enchantment($name, $rarity, 0, 0, $max, $power, $range);
		return self::$all = [
			"depth_strider" => $e("%enchantment.waterWalker", Rarity::RARE, 3, fn(int $l) : int => $l * 10, 15),
			"smite" => new MobTypeDamageEnchantment("%enchantment.damage.undead", Rarity::UNCOMMON, 5, fn(int $l) : int => 5 + ($l - 1) * 8, 20, MobTypeDamageEnchantment::UNDEAD),
			"bane_of_arthropods" => new MobTypeDamageEnchantment("%enchantment.damage.arthropods", Rarity::UNCOMMON, 5, fn(int $l) : int => 5 + ($l - 1) * 8, 20, MobTypeDamageEnchantment::ARTHROPODS, true),
			"looting" => $e("%enchantment.lootBonus", Rarity::RARE, 3, fn(int $l) : int => 15 + ($l - 1) * 9, 50),
			"luck_of_the_sea" => $e("%enchantment.lootBonusFishing", Rarity::RARE, 3, fn(int $l) : int => 15 + ($l - 1) * 9, 50),
			"lure" => $e("%enchantment.fishingSpeed", Rarity::RARE, 3, fn(int $l) : int => 15 + ($l - 1) * 9, 50),
			"binding" => $e("%enchantment.curse.binding", Rarity::MYTHIC, 1, fn(int $l) : int => 25, 25),
			"impaling" => new MobTypeDamageEnchantment("%enchantment.tridentImpaling", Rarity::RARE, 5, fn(int $l) : int => 1 + ($l - 1) * 8, 20, MobTypeDamageEnchantment::AQUATIC),
			"riptide" => $e("%enchantment.tridentRiptide", Rarity::RARE, 3, fn(int $l) : int => 10 + $l * 7, 50),
			"loyalty" => $e("%enchantment.tridentLoyalty", Rarity::UNCOMMON, 3, fn(int $l) : int => 5 + $l * 7, 50),
			"channeling" => $e("%enchantment.tridentChanneling", Rarity::MYTHIC, 1, fn(int $l) : int => 25, 50),
			"multishot" => $e("%enchantment.crossbowMultishot", Rarity::RARE, 1, fn(int $l) : int => 20, 30),
			"piercing" => $e("%enchantment.crossbowPiercing", Rarity::COMMON, 4, fn(int $l) : int => 1 + ($l - 1) * 10, 50),
			"quick_charge" => $e("%enchantment.crossbowQuickCharge", Rarity::UNCOMMON, 3, fn(int $l) : int => 12 + ($l - 1) * 20, 50),
			"soul_speed" => $e("%enchantment.soul_speed", Rarity::MYTHIC, 3, fn(int $l) : int => $l * 10, 15),
			"wind_burst" => $e("%enchantment.heavy_weapon.windburst", Rarity::RARE, 3, fn(int $l) : int => 15 + ($l - 1) * 9, 50),
			"density" => $e("%enchantment.heavy_weapon.density", Rarity::UNCOMMON, 5, fn(int $l) : int => 5 + ($l - 1) * 8, 20),
			"breach" => $e("%enchantment.heavy_weapon.breach", Rarity::RARE, 4, fn(int $l) : int => 15 + ($l - 1) * 9, 50),
		];
	}

	public static function get(string $name) : Enchantment{
		return self::all()[$name] ?? throw new \InvalidArgumentException("Unknown enchantment $name");
	}

	/** Bedrock enchantment ids. */
	public static function ids() : array{
		return [
			"depth_strider" => EnchantmentIds::DEPTH_STRIDER, "smite" => EnchantmentIds::SMITE, "bane_of_arthropods" => EnchantmentIds::BANE_OF_ARTHROPODS,
			"looting" => EnchantmentIds::LOOTING, "luck_of_the_sea" => EnchantmentIds::LUCK_OF_THE_SEA, "lure" => EnchantmentIds::LURE,
			"binding" => EnchantmentIds::BINDING, "impaling" => EnchantmentIds::IMPALING, "riptide" => EnchantmentIds::RIPTIDE,
			"loyalty" => EnchantmentIds::LOYALTY, "channeling" => EnchantmentIds::CHANNELING, "multishot" => EnchantmentIds::MULTISHOT,
			"piercing" => EnchantmentIds::PIERCING, "quick_charge" => EnchantmentIds::QUICK_CHARGE, "soul_speed" => EnchantmentIds::SOUL_SPEED,
			"wind_burst" => 38, "density" => 39, "breach" => 40,
		];
	}

	/**
	 * Items each enchantment can go on: [enchanting table items, anvil-only items].
	 * @return array<string, array{list<string>, list<string>}>
	 */
	public static function itemTags() : array{
		return [
			"depth_strider" => [[Tags::BOOTS], []],
			"smite" => [[Tags::SWORD, Tags::AXE], ["mace"]],
			"bane_of_arthropods" => [[Tags::SWORD, Tags::AXE], ["mace"]],
			"looting" => [[Tags::SWORD], []],
			"luck_of_the_sea" => [[Tags::FISHING_ROD], []],
			"lure" => [[Tags::FISHING_ROD], []],
			"binding" => [[], [Tags::ARMOR, Tags::ELYTRA]],
			"impaling" => [[Tags::TRIDENT], []],
			"riptide" => [[Tags::TRIDENT], []],
			"loyalty" => [[Tags::TRIDENT], []],
			"channeling" => [[Tags::TRIDENT], []],
			"multishot" => [[Tags::CROSSBOW], []],
			"piercing" => [[Tags::CROSSBOW], []],
			"quick_charge" => [[Tags::CROSSBOW], []],
			"soul_speed" => [[], [Tags::BOOTS]],
			"wind_burst" => [[], ["mace"]],
			"density" => [[], ["mace"]],
			"breach" => [[], ["mace"]],
		];
	}

	/** @return array<string, list<string>> incompatibility group => enchantment names */
	public static function incompatibilities() : array{
		return [
			"betterpmmp_damage" => ["smite", "bane_of_arthropods", "density", "breach"],
			"betterpmmp_crossbow" => ["multishot", "piercing"],
			"betterpmmp_riptide_loyalty" => ["riptide", "loyalty"],
			"betterpmmp_riptide_channeling" => ["riptide", "channeling"],
			"betterpmmp_boots" => ["depth_strider"],
		];
	}
}
