<?php

/*
 * BetterPMMP - villager professions and their trade tables.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\trade;

use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Random;
use function array_keys;
use function count;
use function str_ends_with;
use function substr;

/**
 * Bedrock villager professions (villager_v2 variants), their job site blocks
 * and trade pools per level, modelled on the vanilla trade tables. On each
 * new level a villager unlocks two random trades of that level's pool.
 */
final class VillagerTrades{

	public const UNEMPLOYED = 0;
	public const FARMER = 1;
	public const FISHERMAN = 2;
	public const SHEPHERD = 3;
	public const FLETCHER = 4;
	public const LIBRARIAN = 5;
	public const CARTOGRAPHER = 6;
	public const CLERIC = 7;
	public const ARMORER = 8;
	public const WEAPONSMITH = 9;
	public const TOOLSMITH = 10;
	public const BUTCHER = 11;
	public const LEATHERWORKER = 12;
	public const MASON = 13;
	public const NITWIT = 14;

	/** Experience needed for each level (Novice, Apprentice, Journeyman, Expert, Master). */
	public const LEVEL_XP = [0, 10, 70, 150, 250];
	public const LEVEL_NAMES = ["Novice", "Apprentice", "Journeyman", "Expert", "Master"];

	/** Job site block (Bedrock name) => profession. */
	public const JOB_SITES = [
		"minecraft:composter" => self::FARMER,
		"minecraft:barrel" => self::FISHERMAN,
		"minecraft:loom" => self::SHEPHERD,
		"minecraft:fletching_table" => self::FLETCHER,
		"minecraft:lectern" => self::LIBRARIAN,
		"minecraft:cartography_table" => self::CARTOGRAPHER,
		"minecraft:brewing_stand" => self::CLERIC,
		"minecraft:blast_furnace" => self::ARMORER,
		"minecraft:grindstone" => self::WEAPONSMITH,
		"minecraft:smithing_table" => self::TOOLSMITH,
		"minecraft:smoker" => self::BUTCHER,
		"minecraft:cauldron" => self::LEATHERWORKER,
		"minecraft:stonecutter_block" => self::MASON,
	];

	public const NAMES = [
		self::UNEMPLOYED => "Villager", self::FARMER => "Farmer", self::FISHERMAN => "Fisherman", self::SHEPHERD => "Shepherd",
		self::FLETCHER => "Fletcher", self::LIBRARIAN => "Librarian", self::CARTOGRAPHER => "Cartographer", self::CLERIC => "Cleric",
		self::ARMORER => "Armorer", self::WEAPONSMITH => "Weaponsmith", self::TOOLSMITH => "Toolsmith", self::BUTCHER => "Butcher",
		self::LEATHERWORKER => "Leatherworker", self::MASON => "Mason", self::NITWIT => "Nitwit",
	];

	/**
	 * profession => level (0-4) => list of [buy, buyCount, sell, sellCount, maxUses, xp, buyB?, buyBCount?]
	 * "emerald" stands for emeralds; "*_enchanted" sells get a random enchantment;
	 * "enchanted_book" gets a random book.
	 */
	private const TABLES = [
		self::FARMER => [
			[["wheat", 20, "emerald", 1, 16, 2], ["potato", 26, "emerald", 1, 16, 2], ["carrot", 22, "emerald", 1, 16, 2], ["beetroot", 15, "emerald", 1, 16, 2], ["emerald", 1, "bread", 6, 16, 1]],
			[["pumpkin", 6, "emerald", 1, 12, 10], ["emerald", 1, "pumpkin_pie", 4, 12, 5], ["emerald", 1, "apple", 4, 16, 5]],
			[["melon", 4, "emerald", 1, 12, 20], ["emerald", 3, "cookie", 18, 12, 10]],
			[["emerald", 1, "cake", 1, 12, 15], ["emerald", 1, "pumpkin_pie", 4, 12, 15]],
			[["emerald", 3, "golden_carrot", 3, 12, 30], ["emerald", 4, "glistering_melon", 3, 12, 30]],
		],
		self::FISHERMAN => [
			[["string", 20, "emerald", 1, 16, 2], ["coal", 10, "emerald", 1, 16, 2], ["emerald", 1, "cooked_cod", 6, 16, 1, "raw_cod", 6], ["emerald", 3, "bucket", 1, 16, 1]],
			[["raw_cod", 15, "emerald", 1, 16, 10], ["emerald", 1, "cooked_salmon", 6, 16, 5, "raw_salmon", 6], ["emerald", 2, "campfire", 1, 12, 5]],
			[["raw_salmon", 13, "emerald", 1, 16, 20], ["emerald", 8, "fishing_rod_enchanted", 1, 3, 10]],
			[["clownfish", 6, "emerald", 1, 12, 30]],
			[["pufferfish", 4, "emerald", 1, 12, 30]],
		],
		self::SHEPHERD => [
			[["white_wool", 18, "emerald", 1, 16, 2], ["brown_wool", 18, "emerald", 1, 16, 2], ["black_wool", 18, "emerald", 1, 16, 2], ["gray_wool", 18, "emerald", 1, 16, 2], ["emerald", 2, "shears", 1, 12, 1]],
			[["white_dye", 12, "emerald", 1, 16, 10], ["gray_dye", 12, "emerald", 1, 16, 10], ["black_dye", 12, "emerald", 1, 16, 10], ["emerald", 1, "white_wool", 1, 16, 5], ["emerald", 1, "white_carpet", 4, 16, 5]],
			[["yellow_dye", 12, "emerald", 1, 16, 20], ["red_dye", 12, "emerald", 1, 16, 20], ["emerald", 3, "white_bed", 1, 12, 10], ["emerald", 3, "red_bed", 1, 12, 10]],
			[["brown_dye", 12, "emerald", 1, 16, 30], ["blue_dye", 12, "emerald", 1, 16, 30], ["emerald", 3, "white_banner", 1, 12, 15]],
			[["emerald", 2, "painting", 3, 12, 30]],
		],
		self::FLETCHER => [
			[["stick", 32, "emerald", 1, 16, 2], ["emerald", 1, "arrow", 16, 12, 1], ["emerald", 1, "flint", 10, 12, 1, "gravel", 10]],
			[["flint", 26, "emerald", 1, 12, 10], ["emerald", 2, "bow", 1, 12, 5]],
			[["string", 14, "emerald", 1, 16, 20], ["emerald", 3, "crossbow", 1, 12, 10]],
			[["feather", 24, "emerald", 1, 16, 30], ["emerald", 7, "bow_enchanted", 1, 3, 15]],
			[["tripwire_hook", 8, "emerald", 1, 12, 30], ["emerald", 2, "arrow", 5, 12, 30, "arrow", 5]],
		],
		self::LIBRARIAN => [
			[["paper", 24, "emerald", 1, 16, 2], ["emerald", 9, "bookshelf", 1, 12, 1], ["emerald", 12, "enchanted_book", 1, 12, 1, "book", 1]],
			[["book", 4, "emerald", 1, 12, 10], ["emerald", 1, "lantern", 1, 12, 5], ["emerald", 15, "enchanted_book", 1, 12, 5, "book", 1]],
			[["ink_sac", 5, "emerald", 1, 12, 20], ["emerald", 1, "glass", 4, 12, 10], ["emerald", 18, "enchanted_book", 1, 12, 10, "book", 1]],
			[["writable_book", 2, "emerald", 1, 12, 30], ["emerald", 5, "compass", 1, 12, 15], ["emerald", 4, "clock", 1, 12, 15]],
			[["emerald", 20, "name_tag", 1, 12, 30], ["emerald", 25, "enchanted_book", 1, 12, 30, "book", 1]],
		],
		self::CARTOGRAPHER => [
			[["paper", 24, "emerald", 1, 16, 2], ["emerald", 7, "compass", 1, 12, 1]],
			[["glass_pane", 11, "emerald", 1, 16, 10], ["emerald", 1, "paper", 4, 12, 5]],
			[["compass", 1, "emerald", 1, 12, 20], ["emerald", 2, "glass_pane", 8, 12, 10]],
			[["emerald", 7, "item_frame", 1, 12, 15], ["emerald", 3, "white_banner", 1, 12, 15]],
			[["emerald", 8, "item_frame", 2, 12, 30]],
		],
		self::CLERIC => [
			[["rotten_flesh", 32, "emerald", 1, 16, 2], ["emerald", 1, "redstone", 2, 12, 1]],
			[["gold_ingot", 3, "emerald", 1, 12, 10], ["emerald", 1, "lapis_lazuli", 1, 12, 5]],
			[["rabbit_foot", 2, "emerald", 1, 12, 20], ["emerald", 4, "glowstone", 1, 12, 10]],
			[["glass_bottle", 9, "emerald", 1, 12, 30], ["emerald", 5, "ender_pearl", 1, 12, 15]],
			[["nether_wart", 22, "emerald", 1, 12, 30], ["emerald", 3, "experience_bottle", 1, 12, 30]],
		],
		self::ARMORER => [
			[["coal", 15, "emerald", 1, 16, 2], ["emerald", 5, "iron_helmet", 1, 12, 1], ["emerald", 9, "iron_chestplate", 1, 12, 1], ["emerald", 7, "iron_leggings", 1, 12, 1], ["emerald", 4, "iron_boots", 1, 12, 1]],
			[["iron_ingot", 4, "emerald", 1, 12, 10], ["emerald", 36, "bell", 1, 12, 5], ["emerald", 3, "chainmail_boots", 1, 12, 5], ["emerald", 1, "chainmail_leggings", 1, 12, 5]],
			[["lava_bucket", 1, "emerald", 1, 12, 20], ["diamond", 1, "emerald", 1, 12, 20], ["emerald", 1, "chainmail_helmet", 1, 12, 10], ["emerald", 4, "chainmail_chestplate", 1, 12, 10], ["emerald", 5, "shield", 1, 12, 10]],
			[["emerald", 19, "diamond_leggings_enchanted", 1, 3, 15], ["emerald", 13, "diamond_boots_enchanted", 1, 3, 15]],
			[["emerald", 13, "diamond_helmet_enchanted", 1, 3, 30], ["emerald", 21, "diamond_chestplate_enchanted", 1, 3, 30]],
		],
		self::WEAPONSMITH => [
			[["coal", 15, "emerald", 1, 16, 2], ["emerald", 3, "iron_axe", 1, 12, 1], ["emerald", 7, "iron_sword_enchanted", 1, 3, 1]],
			[["iron_ingot", 4, "emerald", 1, 12, 10], ["emerald", 36, "bell", 1, 12, 5]],
			[["flint", 24, "emerald", 1, 12, 20]],
			[["diamond", 1, "emerald", 1, 12, 30], ["emerald", 17, "diamond_axe_enchanted", 1, 3, 15]],
			[["emerald", 13, "diamond_sword_enchanted", 1, 3, 30]],
		],
		self::TOOLSMITH => [
			[["coal", 15, "emerald", 1, 16, 2], ["emerald", 1, "stone_axe", 1, 12, 1], ["emerald", 1, "stone_shovel", 1, 12, 1], ["emerald", 1, "stone_pickaxe", 1, 12, 1], ["emerald", 1, "stone_hoe", 1, 12, 1]],
			[["iron_ingot", 4, "emerald", 1, 12, 10], ["emerald", 36, "bell", 1, 12, 5]],
			[["flint", 30, "emerald", 1, 12, 20], ["emerald", 7, "iron_axe_enchanted", 1, 3, 10], ["emerald", 4, "iron_shovel_enchanted", 1, 3, 10], ["emerald", 7, "iron_pickaxe_enchanted", 1, 3, 10], ["emerald", 4, "diamond_hoe", 1, 3, 10]],
			[["diamond", 1, "emerald", 1, 12, 30], ["emerald", 12, "diamond_axe_enchanted", 1, 3, 15], ["emerald", 5, "diamond_shovel_enchanted", 1, 3, 15]],
			[["emerald", 13, "diamond_pickaxe_enchanted", 1, 3, 30]],
		],
		self::BUTCHER => [
			[["raw_chicken", 14, "emerald", 1, 16, 2], ["raw_porkchop", 7, "emerald", 1, 16, 2], ["raw_rabbit", 4, "emerald", 1, 16, 2], ["emerald", 1, "rabbit_stew", 1, 12, 1]],
			[["coal", 15, "emerald", 1, 16, 2], ["emerald", 1, "cooked_porkchop", 5, 16, 5], ["emerald", 1, "cooked_chicken", 8, 16, 5]],
			[["raw_mutton", 7, "emerald", 1, 16, 20], ["raw_beef", 10, "emerald", 1, 16, 20]],
			[["dried_kelp_block", 10, "emerald", 1, 12, 30]],
			[["sweet_berries", 10, "emerald", 1, 12, 30]],
		],
		self::LEATHERWORKER => [
			[["leather", 6, "emerald", 1, 16, 2], ["emerald", 3, "leather_pants", 1, 12, 1], ["emerald", 7, "leather_tunic", 1, 12, 1]],
			[["flint", 26, "emerald", 1, 12, 10], ["emerald", 5, "leather_cap", 1, 12, 5], ["emerald", 4, "leather_boots", 1, 12, 5]],
			[["rabbit_hide", 9, "emerald", 1, 12, 20], ["emerald", 7, "leather_tunic", 1, 12, 10]],
			[["scute", 4, "emerald", 1, 12, 30], ["emerald", 6, "leather_pants", 1, 12, 15]],
			[["emerald", 5, "leather_cap", 1, 12, 30]],
		],
		self::MASON => [
			[["clay_ball", 10, "emerald", 1, 16, 2], ["emerald", 1, "brick", 10, 16, 1]],
			[["stone", 20, "emerald", 1, 16, 10], ["emerald", 1, "chiseled_stone_bricks", 4, 16, 5]],
			[["granite", 16, "emerald", 1, 16, 20], ["andesite", 16, "emerald", 1, 16, 20], ["diorite", 16, "emerald", 1, 16, 20], ["emerald", 1, "polished_andesite", 4, 16, 10], ["emerald", 1, "polished_diorite", 4, 16, 10], ["emerald", 1, "polished_granite", 4, 16, 10]],
			[["nether_quartz", 12, "emerald", 1, 12, 30], ["emerald", 1, "white_stained_clay", 1, 12, 15], ["emerald", 1, "orange_glazed_terracotta", 1, 12, 15]],
			[["emerald", 1, "quartz_pillar", 1, 12, 30], ["emerald", 1, "quartz_block", 1, 12, 30]],
		],
	];

	public static function isTradingProfession(int $profession) : bool{
		return isset(self::TABLES[$profession]);
	}

	/**
	 * Picks up to two trades of the given level for a villager.
	 * @return TradeOffer[]
	 */
	public static function rollLevel(int $profession, int $level, Random $random) : array{
		$pool = self::TABLES[$profession][$level] ?? [];
		$offers = [];
		$indexes = array_keys($pool);
		for($i = count($indexes) - 1; $i > 0; --$i){
			$j = $random->nextBoundedInt($i + 1);
			[$indexes[$i], $indexes[$j]] = [$indexes[$j], $indexes[$i]];
		}
		foreach($indexes as $index){
			if(count($offers) >= 2){
				break;
			}
			$offer = self::makeOffer($pool[$index], $level, $random);
			if($offer !== null){
				$offers[] = $offer;
			}
		}
		return $offers;
	}

	/** @param array<int, int|string> $row */
	private static function makeOffer(array $row, int $level, Random $random) : ?TradeOffer{
		[$buyName, $buyCount, $sellName, $sellCount, $maxUses, $xp] = $row;
		$buyA = self::item((string) $buyName, (int) $buyCount, $random);
		$sell = self::item((string) $sellName, (int) $sellCount, $random);
		$buyB = isset($row[6]) ? self::item((string) $row[6], (int) ($row[7] ?? 1), $random) : null;
		if($buyA === null || $sell === null || (isset($row[6]) && $buyB === null)){
			return null; //item not implemented in the core
		}
		//enchanted books cost more for higher enchantment levels, like vanilla
		if($sellName === "enchanted_book"){
			foreach($sell->getEnchantments() as $enchantment){
				$buyA->setCount(min(64, 2 + 3 * $enchantment->getLevel() + $random->nextBoundedInt(5 + $enchantment->getLevel() * 10)));
			}
		}
		return new TradeOffer($buyA, $buyB, $sell, (int) $maxUses, (int) $xp, $level);
	}

	private static function item(string $name, int $count, Random $random) : ?Item{
		if($name === "enchanted_book"){
			$book = VanillaItems::ENCHANTED_BOOK();
			$all = VanillaEnchantments::getAll();
			$keys = array_keys($all);
			$enchantment = $all[$keys[$random->nextBoundedInt(count($keys))]];
			$book->addEnchantment(new EnchantmentInstance($enchantment, 1 + $random->nextBoundedInt($enchantment->getMaxLevel())));
			return $book;
		}
		$enchant = str_ends_with($name, "_enchanted");
		if($enchant){
			$name = substr($name, 0, -10);
		}
		try{
			$item = StringToItemParser::getInstance()->parse($name);
		}catch(\Throwable){
			return null;
		}
		if($item === null || $item->isNull()){
			return null;
		}
		$item->setCount(min($count, $item->getMaxStackSize()));
		if($enchant){
			$candidate = match(true){
				$item instanceof \pocketmine\item\Armor => VanillaEnchantments::PROTECTION(),
				$item instanceof \pocketmine\item\Sword => VanillaEnchantments::SHARPNESS(),
				$item instanceof \pocketmine\item\TieredTool => VanillaEnchantments::EFFICIENCY(),
				$item instanceof \pocketmine\item\Bow => VanillaEnchantments::POWER(),
				default => VanillaEnchantments::UNBREAKING(),
			};
			$item->addEnchantment(new EnchantmentInstance($candidate, 1 + $random->nextBoundedInt($candidate->getMaxLevel())));
		}
		return $item;
	}
}
