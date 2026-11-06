<?php

/*
 * BetterPMMP - vanilla-like structure loot tables.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\utils\Random;
use function array_keys;
use function count;

/**
 * Chest contents for generated structures, modelled on vanilla loot tables:
 * each table has pools with a roll range and weighted entries. Items are
 * written as container tile NBT ("Items" list with slots), ready for
 * {@link BlockWriter::setBlock()}.
 */
final class LootTables{

	/**
	 * pool: [minRolls, maxRolls, entries[]]; entry: [item name, min, max, weight]
	 * Item names use the StringToItemParser aliases; items the core does not
	 * implement yet (saddle, horse armor, maps, crossbow, lead) are skipped. "enchanted_book" gets a
	 * random enchantment, "*_enchanted" items get one enchantment.
	 * @var array<string, list<array{int, int, list<array{string, int, int, int}>}>>
	 */
	private const TABLES = [
		"simple_dungeon" => [
			[1, 3, [["saddle", 1, 1, 20], ["golden_apple", 1, 1, 15], ["enchanted_golden_apple", 1, 1, 2], ["record_otherside", 1, 1, 2],
				["record_13", 1, 1, 15], ["record_cat", 1, 1, 15], ["name_tag", 1, 1, 20], ["golden_horse_armor", 1, 1, 10],
				["iron_horse_armor", 1, 1, 15], ["diamond_horse_armor", 1, 1, 5], ["enchanted_book", 1, 1, 10]]],
			[1, 4, [["iron_ingot", 1, 4, 10], ["gold_ingot", 1, 4, 5], ["bread", 1, 1, 20], ["wheat", 1, 4, 20], ["bucket", 1, 1, 10],
				["redstone", 1, 4, 15], ["coal", 1, 4, 15], ["melon_seeds", 2, 4, 10], ["pumpkin_seeds", 2, 4, 10], ["beetroot_seeds", 2, 4, 10]]],
			[3, 3, [["bone", 1, 8, 10], ["gunpowder", 1, 8, 10], ["rotten_flesh", 1, 8, 10], ["string", 1, 8, 10]]],
		],
		"abandoned_mineshaft" => [
			[1, 1, [["golden_apple", 1, 1, 20], ["enchanted_golden_apple", 1, 1, 1], ["name_tag", 1, 1, 30], ["enchanted_book", 1, 1, 10],
				["iron_pickaxe", 1, 1, 5], ["air", 1, 1, 5]]],
			[2, 4, [["iron_ingot", 1, 5, 10], ["gold_ingot", 1, 3, 5], ["redstone", 4, 9, 5], ["lapis_lazuli", 4, 9, 5], ["diamond", 1, 2, 3],
				["coal", 3, 8, 10], ["bread", 1, 3, 15], ["glow_berries", 3, 6, 15], ["melon_seeds", 2, 4, 10], ["pumpkin_seeds", 2, 4, 10], ["beetroot_seeds", 2, 4, 10]]],
			[3, 3, [["rail", 4, 8, 20], ["powered_rail", 1, 4, 5], ["detector_rail", 1, 4, 5], ["activator_rail", 1, 4, 5], ["torch", 1, 16, 15]]],
		],
		"desert_pyramid" => [
			[2, 4, [["diamond", 1, 3, 5], ["iron_ingot", 1, 5, 15], ["gold_ingot", 2, 7, 15], ["emerald", 1, 3, 15], ["bone", 4, 6, 25],
				["spider_eye", 1, 3, 25], ["rotten_flesh", 3, 7, 25], ["saddle", 1, 1, 20], ["iron_horse_armor", 1, 1, 15], ["golden_horse_armor", 1, 1, 10],
				["diamond_horse_armor", 1, 1, 5], ["enchanted_book", 1, 1, 20], ["golden_apple", 1, 1, 20], ["enchanted_golden_apple", 1, 1, 2]]],
			[4, 4, [["bone", 1, 8, 10], ["gunpowder", 1, 8, 10], ["rotten_flesh", 1, 8, 10], ["string", 1, 8, 10], ["sand", 1, 8, 10]]],
		],
		"jungle_temple" => [
			[2, 6, [["diamond", 1, 3, 3], ["iron_ingot", 1, 5, 10], ["gold_ingot", 2, 7, 15], ["emerald", 1, 3, 2], ["bone", 4, 6, 20],
				["rotten_flesh", 3, 7, 16], ["saddle", 1, 1, 3], ["iron_horse_armor", 1, 1, 1], ["golden_horse_armor", 1, 1, 1],
				["diamond_horse_armor", 1, 1, 1], ["enchanted_book", 1, 1, 1], ["bamboo", 1, 3, 15]]],
		],
		"jungle_temple_dispenser" => [
			[1, 2, [["arrow", 2, 7, 30]]],
		],
		"stronghold_corridor" => [
			[2, 3, [["ender_pearl", 1, 1, 10], ["diamond", 1, 3, 3], ["iron_ingot", 1, 5, 10], ["gold_ingot", 1, 3, 5], ["redstone", 4, 9, 5],
				["bread", 1, 3, 15], ["apple", 1, 3, 15], ["iron_pickaxe", 1, 1, 5], ["iron_sword", 1, 1, 5], ["iron_chestplate", 1, 1, 5],
				["iron_helmet", 1, 1, 5], ["iron_leggings", 1, 1, 5], ["iron_boots", 1, 1, 5], ["golden_apple", 1, 1, 1], ["saddle", 1, 1, 1],
				["iron_horse_armor", 1, 1, 1], ["golden_horse_armor", 1, 1, 1], ["diamond_horse_armor", 1, 1, 1], ["record_otherside", 1, 1, 1], ["enchanted_book", 1, 1, 1]]],
		],
		"stronghold_crossing" => [
			[1, 4, [["iron_ingot", 1, 5, 10], ["gold_ingot", 1, 3, 5], ["redstone", 4, 9, 5], ["coal", 3, 8, 10], ["bread", 1, 3, 15],
				["apple", 1, 3, 15], ["iron_pickaxe", 1, 1, 1], ["enchanted_book", 1, 1, 1]]],
		],
		"stronghold_library" => [
			[2, 10, [["book", 1, 3, 20], ["paper", 2, 7, 20], ["map", 1, 1, 1], ["compass", 1, 1, 1], ["enchanted_book", 1, 1, 10]]],
		],
		"village_house" => [
			[3, 8, [["bread", 1, 4, 15], ["apple", 1, 5, 15], ["wheat", 1, 7, 15], ["emerald", 1, 1, 2], ["potato", 1, 7, 10], ["carrot", 1, 7, 10],
				["wheat_seeds", 1, 5, 10], ["oak_sapling", 1, 2, 5], ["book", 1, 1, 1], ["feather", 1, 1, 1], ["egg", 1, 2, 2]]],
		],
		"village_weaponsmith" => [
			[3, 8, [["diamond", 1, 3, 3], ["iron_ingot", 1, 5, 10], ["gold_ingot", 1, 3, 5], ["bread", 1, 3, 15], ["apple", 1, 3, 15],
				["iron_pickaxe", 1, 1, 5], ["iron_sword", 1, 1, 5], ["iron_chestplate", 1, 1, 5], ["iron_helmet", 1, 1, 5], ["iron_leggings", 1, 1, 5],
				["iron_boots", 1, 1, 5], ["obsidian", 3, 7, 5], ["oak_sapling", 3, 7, 5], ["saddle", 1, 1, 3], ["iron_horse_armor", 1, 1, 1],
				["golden_horse_armor", 1, 1, 1], ["diamond_horse_armor", 1, 1, 1]]],
		],
		"village_toolsmith" => [
			[3, 8, [["diamond", 1, 3, 1], ["iron_ingot", 1, 5, 5], ["gold_ingot", 1, 3, 1], ["bread", 1, 3, 15], ["iron_pickaxe", 1, 1, 5],
				["coal", 1, 3, 1], ["stick", 1, 3, 20], ["iron_shovel", 1, 1, 5]]],
		],
		"village_armorer" => [
			[1, 5, [["iron_ingot", 1, 3, 2], ["bread", 1, 4, 4], ["iron_helmet", 1, 1, 1], ["emerald", 1, 1, 1]]],
		],
		"village_cartographer" => [
			[1, 5, [["map", 1, 3, 10], ["paper", 1, 5, 15], ["compass", 1, 1, 5], ["bread", 1, 4, 15], ["stick", 1, 2, 5]]],
		],
		"village_butcher" => [
			[1, 5, [["emerald", 1, 1, 1], ["raw_porkchop", 1, 3, 6], ["wheat", 1, 3, 6], ["raw_beef", 1, 3, 6], ["raw_mutton", 1, 3, 6], ["coal", 1, 3, 3]]],
		],
		"village_fletcher" => [
			[1, 5, [["emerald", 1, 1, 1], ["arrow", 1, 3, 2], ["feather", 1, 3, 6], ["egg", 1, 3, 2], ["flint", 1, 3, 6], ["stick", 1, 3, 6]]],
		],
		"village_fisher" => [
			[1, 5, [["emerald", 1, 1, 1], ["raw_cod", 1, 3, 2], ["raw_salmon", 1, 3, 1], ["water_bucket", 1, 3, 1], ["barrel", 1, 3, 1],
				["wheat_seeds", 1, 3, 3], ["coal", 1, 3, 2]]],
		],
		"village_shepherd" => [
			[1, 5, [["white_wool", 1, 8, 6], ["black_wool", 1, 3, 3], ["gray_wool", 1, 3, 2], ["brown_wool", 1, 3, 2], ["light_gray_wool", 1, 3, 2],
				["emerald", 1, 1, 1], ["shears", 1, 1, 1], ["wheat", 1, 6, 6]]],
		],
		"village_tannery" => [
			[1, 5, [["leather", 1, 3, 1], ["leather_tunic", 1, 1, 2], ["leather_boots", 1, 1, 2], ["leather_cap", 1, 1, 2], ["bread", 1, 4, 5],
				["leather_pants", 1, 1, 2], ["saddle", 1, 1, 1], ["emerald", 1, 4, 1]]],
		],
		"village_mason" => [
			[1, 5, [["clay_ball", 1, 3, 1], ["flower_pot", 1, 1, 1], ["stone", 1, 1, 2], ["stone_bricks", 1, 1, 2], ["bread", 1, 4, 4],
				["yellow_dye", 1, 1, 1], ["smooth_stone", 1, 1, 1], ["emerald", 1, 1, 1]]],
		],
		"village_temple" => [
			[3, 8, [["redstone", 1, 4, 2], ["bread", 1, 4, 7], ["rotten_flesh", 1, 4, 7], ["lapis_lazuli", 1, 4, 1], ["gold_ingot", 1, 4, 1], ["emerald", 1, 4, 1]]],
		],
		"igloo_chest" => [
			[2, 8, [["apple", 1, 3, 15], ["coal", 1, 4, 15], ["gold_nugget", 1, 3, 10], ["stone_axe", 1, 1, 2], ["rotten_flesh", 1, 1, 10],
				["emerald", 1, 1, 1], ["wheat", 2, 3, 10]]],
			[1, 1, [["golden_apple", 1, 1, 1]]],
		],
		"shipwreck_supply" => [
			[3, 10, [["paper", 1, 12, 8], ["potato", 2, 6, 7], ["poisonous_potato", 2, 6, 7], ["carrot", 4, 8, 7], ["wheat", 8, 21, 7],
				["coal", 2, 8, 6], ["rotten_flesh", 5, 24, 5], ["bamboo", 1, 3, 2], ["gunpowder", 1, 5, 3], ["tnt", 1, 2, 1],
				["leather_cap", 1, 1, 3], ["leather_tunic", 1, 1, 3], ["leather_pants", 1, 1, 3], ["leather_boots", 1, 1, 3]]],
		],
		"shipwreck_treasure" => [
			[3, 6, [["iron_ingot", 1, 5, 90], ["gold_ingot", 1, 5, 10], ["emerald", 1, 5, 40], ["diamond", 1, 1, 5], ["experience_bottle", 1, 1, 5]]],
			[2, 5, [["iron_nugget", 1, 10, 50], ["gold_nugget", 1, 10, 10], ["lapis_lazuli", 1, 10, 20]]],
		],
		"shipwreck_map" => [
			[1, 1, [["compass", 1, 1, 1]]], //treasure maps are not implemented in the core
			[3, 3, [["compass", 1, 1, 1], ["map", 1, 1, 1], ["clock", 1, 1, 1], ["paper", 1, 10, 20], ["feather", 1, 5, 10], ["book", 1, 5, 5]]],
		],
		"buried_treasure" => [
			[1, 1, [["heart_of_the_sea", 1, 1, 1]]],
			[5, 8, [["iron_ingot", 1, 4, 20], ["gold_ingot", 1, 4, 10], ["tnt", 1, 2, 5]]],
			[1, 3, [["emerald", 4, 8, 5], ["diamond", 1, 2, 5], ["prismarine_crystals", 1, 5, 5]]],
			[0, 1, [["leather_tunic", 1, 1, 1], ["iron_sword", 1, 1, 1]]],
			[2, 2, [["cooked_cod", 2, 4, 1], ["cooked_salmon", 2, 4, 1]]],
		],
		"underwater_ruin" => [
			[2, 8, [["coal", 1, 4, 10], ["stone_axe", 1, 1, 2], ["rotten_flesh", 1, 1, 5], ["emerald", 1, 1, 1], ["wheat", 2, 3, 10],
				["gold_nugget", 1, 3, 5], ["leather_tunic", 1, 1, 1], ["golden_helmet", 1, 1, 1], ["fishing_rod", 1, 1, 5], ["enchanted_book", 1, 1, 1]]],
		],
		"pillager_outpost" => [
			[0, 1, [["crossbow", 1, 1, 1]]],
			[2, 3, [["wheat", 3, 5, 7], ["potato", 2, 5, 5], ["carrot", 3, 5, 5]]],
			[1, 3, [["dark_oak_log", 2, 3, 1]]],
			[2, 3, [["experience_bottle", 1, 1, 7], ["string", 1, 6, 4], ["arrow", 2, 7, 4], ["tripwire_hook", 1, 3, 3], ["iron_ingot", 1, 3, 3], ["enchanted_book", 1, 1, 1]]],
		],
		"ruined_portal" => [
			[4, 8, [["obsidian", 1, 2, 40], ["flint", 1, 4, 40], ["iron_nugget", 9, 18, 40], ["flint_and_steel", 1, 1, 40], ["fire_charge", 1, 1, 40],
				["golden_apple", 1, 1, 15], ["gold_nugget", 4, 24, 15], ["golden_sword", 1, 1, 15], ["golden_axe", 1, 1, 15], ["golden_hoe", 1, 1, 15],
				["golden_shovel", 1, 1, 15], ["golden_pickaxe", 1, 1, 15], ["golden_boots", 1, 1, 15], ["golden_chestplate", 1, 1, 15],
				["golden_helmet", 1, 1, 15], ["golden_leggings", 1, 1, 15], ["glistering_melon", 4, 12, 5], ["golden_horse_armor", 1, 1, 5],
				["light_weighted_pressure_plate", 1, 1, 5], ["golden_carrot", 4, 12, 5], ["clock", 1, 1, 5], ["gold_ingot", 2, 8, 5], ["bell", 1, 1, 1],
				["enchanted_golden_apple", 1, 1, 1], ["gold_block", 1, 2, 1]]],
		],
		"nether_bridge" => [
			[2, 4, [["diamond", 1, 3, 5], ["iron_ingot", 1, 5, 5], ["gold_ingot", 1, 3, 15], ["golden_sword", 1, 1, 5], ["golden_chestplate", 1, 1, 5],
				["flint_and_steel", 1, 1, 5], ["nether_wart", 3, 7, 5], ["saddle", 1, 1, 10], ["golden_horse_armor", 1, 1, 8], ["iron_horse_armor", 1, 1, 5],
				["diamond_horse_armor", 1, 1, 3], ["obsidian", 2, 4, 2]]],
		],
		"bastion_treasure" => [
			[3, 3, [["netherite_ingot", 1, 1, 15], ["ancient_debris", 1, 1, 10], ["netherite_scrap", 1, 1, 8], ["ancient_debris", 2, 2, 4],
				["diamond_sword_enchanted", 1, 1, 6], ["diamond_chestplate_enchanted", 1, 1, 6], ["diamond_helmet_enchanted", 1, 1, 6],
				["diamond_leggings_enchanted", 1, 1, 6], ["diamond_boots_enchanted", 1, 1, 6], ["diamond", 2, 6, 6], ["enchanted_golden_apple", 1, 1, 2]]],
			[3, 4, [["spectral_arrow", 12, 25, 1], ["gold_block", 2, 5, 1], ["iron_block", 2, 5, 1], ["gold_ingot", 3, 9, 1], ["iron_ingot", 3, 9, 1],
				["crying_obsidian", 3, 5, 1], ["quartz", 8, 23, 1], ["gilded_blackstone", 5, 15, 1], ["magma_cream", 3, 8, 1]]],
		],
		"bastion_other" => [
			[1, 1, [["diamond_pickaxe_enchanted", 1, 1, 6], ["diamond_shovel", 1, 1, 6], ["crossbow", 1, 1, 6], ["ancient_debris", 1, 1, 12],
				["netherite_scrap", 1, 1, 4], ["spectral_arrow", 10, 22, 10], ["piglin_banner_pattern", 1, 1, 9], ["record_pigstep", 1, 1, 5],
				["golden_carrot", 6, 17, 12], ["golden_apple", 1, 1, 9], ["enchanted_book", 1, 1, 10]]],
			[2, 2, [["iron_sword", 1, 1, 2], ["iron_block", 1, 1, 2], ["golden_boots_enchanted", 1, 1, 1], ["golden_axe_enchanted", 1, 1, 1],
				["gold_block", 1, 1, 2], ["crossbow", 1, 1, 1], ["gold_ingot", 1, 6, 2], ["iron_ingot", 1, 6, 2], ["golden_sword", 1, 1, 2],
				["golden_chestplate", 1, 1, 2], ["golden_helmet", 1, 1, 2], ["golden_leggings", 1, 1, 2], ["golden_boots", 1, 1, 2], ["crying_obsidian", 1, 5, 2]]],
			[3, 4, [["gilded_blackstone", 1, 5, 2], ["chain", 2, 10, 2], ["magma_cream", 2, 6, 2], ["bone_block", 3, 6, 2], ["iron_nugget", 2, 8, 1],
				["obsidian", 4, 6, 1], ["gold_nugget", 2, 8, 1], ["string", 4, 6, 1], ["arrow", 5, 17, 2], ["cooked_porkchop", 1, 1, 1]]],
		],
		"end_city_treasure" => [
			[2, 6, [["diamond", 2, 7, 5], ["iron_ingot", 4, 8, 10], ["gold_ingot", 2, 7, 15], ["emerald", 2, 6, 2], ["beetroot_seeds", 1, 10, 5],
				["saddle", 1, 1, 3], ["iron_horse_armor", 1, 1, 1], ["golden_horse_armor", 1, 1, 1], ["diamond_horse_armor", 1, 1, 1],
				["diamond_sword_enchanted", 1, 1, 3], ["diamond_boots_enchanted", 1, 1, 3], ["diamond_chestplate_enchanted", 1, 1, 3],
				["diamond_leggings_enchanted", 1, 1, 3], ["diamond_helmet_enchanted", 1, 1, 3], ["diamond_pickaxe_enchanted", 1, 1, 3],
				["diamond_shovel_enchanted", 1, 1, 3], ["iron_sword_enchanted", 1, 1, 3], ["iron_boots_enchanted", 1, 1, 3],
				["iron_chestplate_enchanted", 1, 1, 3], ["iron_leggings_enchanted", 1, 1, 3], ["iron_helmet_enchanted", 1, 1, 3],
				["iron_pickaxe_enchanted", 1, 1, 3], ["iron_shovel_enchanted", 1, 1, 3]]],
		],
		"ancient_city" => [
			[5, 10, [["enchanted_golden_apple", 1, 2, 1], ["record_otherside", 1, 1, 1], ["compass", 1, 1, 2], ["name_tag", 1, 1, 2],
				["diamond_horse_armor", 1, 1, 2], ["lead", 1, 1, 2], ["diamond_hoe_enchanted", 1, 1, 2], ["record_13", 1, 1, 2],
				["record_cat", 1, 1, 2], ["diamond_leggings_enchanted", 1, 1, 2], ["enchanted_book", 1, 1, 3], ["sculk", 4, 10, 3],
				["candle", 1, 4, 3], ["amethyst_shard", 1, 15, 3], ["experience_bottle", 1, 3, 3], ["glow_berries", 1, 15, 3],
				["iron_leggings_enchanted", 1, 1, 3], ["echo_shard", 1, 3, 4], ["disc_fragment_5", 1, 3, 4], ["book", 3, 10, 5],
				["bone", 1, 15, 5], ["soul_torch", 1, 15, 5], ["coal", 6, 15, 7], ["snowball", 1, 3, 1]]],
		],
		"woodland_mansion" => [
			[1, 3, [["lead", 1, 1, 20], ["golden_apple", 1, 1, 15], ["enchanted_golden_apple", 1, 1, 2], ["record_13", 1, 1, 15],
				["record_cat", 1, 1, 15], ["name_tag", 1, 1, 20], ["chainmail_chestplate", 1, 1, 10], ["diamond_hoe", 1, 1, 15],
				["diamond_chestplate", 1, 1, 5], ["enchanted_book", 1, 1, 10]]],
			[1, 4, [["iron_ingot", 1, 4, 10], ["gold_ingot", 1, 4, 5], ["bread", 1, 1, 20], ["wheat", 1, 4, 20], ["bucket", 1, 1, 10],
				["redstone", 1, 4, 15], ["coal", 1, 4, 15], ["melon_seeds", 2, 4, 10], ["pumpkin_seeds", 2, 4, 10], ["beetroot_seeds", 2, 4, 10]]],
			[3, 3, [["bone", 1, 8, 10], ["gunpowder", 1, 8, 10], ["rotten_flesh", 1, 8, 10], ["string", 1, 8, 10]]],
		],
		"trial_chambers_corridor" => [
			[1, 3, [["iron_axe", 1, 1, 1], ["honeycomb", 1, 8, 1], ["stone_axe", 1, 1, 2], ["stone_pickaxe", 1, 1, 2], ["ender_pearl", 1, 2, 2],
				["bamboo_hanging_sign", 1, 4, 2], ["bamboo_planks", 3, 6, 2], ["scaffolding", 2, 10, 2], ["torch", 3, 6, 2], ["tuff", 8, 20, 3]]],
		],
		"trial_chambers_supply" => [
			[3, 5, [["arrow", 4, 14, 2], ["baked_potato", 2, 4, 2], ["glow_berries", 2, 10, 2], ["acacia_planks", 3, 6, 1], ["moss_block", 2, 5, 1],
				["bread", 1, 3, 1], ["tuff", 5, 10, 1], ["torch", 3, 6, 1], ["ladder", 5, 10, 1], ["stone_pickaxe", 1, 1, 1], ["milk_bucket", 1, 1, 1]]],
		],
		"trail_ruins" => [
			[2, 5, [["emerald", 1, 2, 2], ["wheat", 2, 3, 2], ["wooden_hoe", 1, 1, 2], ["clay_ball", 1, 3, 2], ["brick", 1, 3, 2],
				["yellow_dye", 1, 1, 2], ["blue_dye", 1, 1, 2], ["light_blue_dye", 1, 1, 2], ["candle", 1, 1, 2], ["beetroot_seeds", 1, 1, 2]]],
		],
	];

	public static function has(string $table) : bool{
		return isset(self::TABLES[$table]);
	}

	/** @return string[] */
	public static function names() : array{
		return array_keys(self::TABLES);
	}

	/**
	 * Rolls a table into a list of items.
	 * @return Item[]
	 */
	public static function roll(string $table, Random $random) : array{
		$items = [];
		$parser = StringToItemParser::getInstance();
		foreach(self::TABLES[$table] ?? [] as [$minRolls, $maxRolls, $entries]){
			$total = 0;
			foreach($entries as [, , , $weight]){
				$total += $weight;
			}
			$rolls = $minRolls + $random->nextBoundedInt($maxRolls - $minRolls + 1);
			for($i = 0; $i < $rolls; ++$i){
				$pick = $random->nextBoundedInt(max(1, $total));
				foreach($entries as [$name, $min, $max, $weight]){
					if(($pick -= $weight) >= 0){
						continue;
					}
					$item = self::makeItem($parser, $name, $random);
					if($item !== null && !$item->isNull()){
						$item->setCount(min($item->getMaxStackSize(), $min + $random->nextBoundedInt($max - $min + 1)));
						$items[] = $item;
					}
					break;
				}
			}
		}
		return $items;
	}

	private static function makeItem(StringToItemParser $parser, string $name, Random $random) : ?Item{
		if($name === "air"){
			return null;
		}
		if($name === "enchanted_book"){
			$book = VanillaItems::ENCHANTED_BOOK();
			$all = VanillaEnchantments::getAll();
			$enchantment = $all[array_keys($all)[$random->nextBoundedInt(count($all))]];
			$book->addEnchantment(new EnchantmentInstance($enchantment, 1 + $random->nextBoundedInt($enchantment->getMaxLevel())));
			return $book;
		}
		$enchant = false;
		if(str_ends_with($name, "_enchanted")){
			$enchant = true;
			$name = substr($name, 0, -10);
		}
		try{
			$item = $parser->parse($name);
		}catch(\Throwable){
			return null;
		}
		if($item !== null && $enchant){
			$candidate = match(true){
				$item instanceof \pocketmine\item\Armor => VanillaEnchantments::PROTECTION(),
				$item instanceof \pocketmine\item\Sword => VanillaEnchantments::SHARPNESS(),
				$item instanceof \pocketmine\item\TieredTool => VanillaEnchantments::EFFICIENCY(),
				default => VanillaEnchantments::UNBREAKING(),
			};
			$item->addEnchantment(new EnchantmentInstance($candidate, 1 + $random->nextBoundedInt($candidate->getMaxLevel())));
			if($random->nextBoolean()){
				$item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1 + $random->nextBoundedInt(3)));
			}
		}
		return $item;
	}

	/**
	 * Container tile NBT (27 slots) filled from a loot table, items scattered
	 * over random slots like vanilla.
	 */
	public static function chestNbt(string $table, Random $random, int $slots = 27) : CompoundTag{
		$slotList = range(0, $slots - 1);
		//deterministic shuffle using the structure random
		for($i = $slots - 1; $i > 0; --$i){
			$j = $random->nextBoundedInt($i + 1);
			[$slotList[$i], $slotList[$j]] = [$slotList[$j], $slotList[$i]];
		}
		$list = new ListTag();
		foreach(self::roll($table, $random) as $index => $item){
			if($index >= $slots){
				break;
			}
			$list->push($item->nbtSerialize($slotList[$index]));
		}
		return CompoundTag::create()->setTag("Items", $list);
	}

	/** Monster spawner tile NBT for the given entity id (e.g. "minecraft:zombie"). */
	public static function spawnerNbt(string $entityId) : CompoundTag{
		return CompoundTag::create()->setString("EntityIdentifier", $entityId);
	}
}
