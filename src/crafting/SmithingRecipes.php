<?php

/*
 * BetterPMMP - smithing table recipes: netherite upgrades and armour trims.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\crafting;

use pocketmine\block\inventory\SmithingTableInventory;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Armor;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function min;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * The recipes of the official data (recipes/smithing.json and smithing_trim.json):
 * - transform: template + base item + material => new item that keeps enchantments, name and wear
 *   (the netherite upgrades),
 * - trim: armour trim template + armour + trim material => the same armour with the trim (Bedrock item tag
 *   "Trim": Material, Pattern).
 * Used for Bedrock players (recipes in the crafting data, performed by the item stack request) and Java
 * players (bridge) alike.
 */
final class SmithingRecipes{
	/** recipe network ids start here, far away from the crafting recipe ids */
	public const NET_ID_BASE = 90000;

	/** trim material item => material id in the item tag */
	public const TRIM_MATERIALS = [
		"minecraft:iron_ingot" => "iron",
		"minecraft:copper_ingot" => "copper",
		"minecraft:gold_ingot" => "gold",
		"minecraft:lapis_lazuli" => "lapis",
		"minecraft:emerald" => "emerald",
		"minecraft:diamond" => "diamond",
		"minecraft:netherite_ingot" => "netherite",
		"minecraft:redstone" => "redstone",
		"minecraft:amethyst_shard" => "amethyst",
		"minecraft:quartz" => "quartz",
		"minecraft:resin_brick" => "resin",
	];

	/** @var list<array{string, string, string, string}>|null [template, base, addition, output] */
	private static ?array $transforms = null;

	/** @return list<array{string, string, string, string}> */
	public static function transforms() : array{
		if(self::$transforms === null){
			self::$transforms = [];
			$data = json_decode((string) @file_get_contents(Path::join(BedrockDataFiles::RECIPES, "smithing.json")), true);
			foreach(is_array($data) ? $data : [] as $recipe){
				$template = $recipe["template"]["name"] ?? null;
				$input = $recipe["input"]["name"] ?? null;
				$addition = $recipe["addition"]["name"] ?? null;
				$output = $recipe["output"] ?? null;
				if(is_array($output)){
					$output = $output["name"] ?? null;
				}
				if(is_string($template) && is_string($input) && is_string($addition) && is_string($output)){
					self::$transforms[] = [$template, $input, $addition, $output];
				}
			}
		}
		return self::$transforms;
	}

	public static function nameOf(Item $item) : string{
		if($item->isNull()){
			return "";
		}
		try{
			return GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
		}catch(\Throwable){
			return "";
		}
	}

	private static function create(string $name) : ?Item{
		try{
			return GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($name));
		}catch(\Throwable){
			return null;
		}
	}

	public static function isTrimTemplate(string $name) : bool{
		return str_ends_with($name, "_armor_trim_smithing_template");
	}

	/**
	 * The result of the three inputs, or null.
	 * @return array{Item, int}|null result and the recipe network id
	 */
	public static function result(Item $template, Item $base, Item $addition) : ?array{
		if($template->isNull() || $base->isNull() || $addition->isNull()){
			return null;
		}
		$templateName = self::nameOf($template);
		$baseName = self::nameOf($base);
		$additionName = self::nameOf($addition);
		foreach(self::transforms() as $index => [$t, $i, $a, $o]){
			if($t === $templateName && $i === $baseName && $a === $additionName){
				$result = self::create($o);
				if($result === null){
					return null;
				}
				//enchantments, name, repair cost and trims stay
				$result->setNamedTag(clone $base->getNamedTag());
				if($result instanceof Durable && $base instanceof Durable){
					$result->setDamage(min($base->getDamage(), $result->getMaxDurability() - 1));
				}
				return [$result->setCount(1), self::NET_ID_BASE + $index];
			}
		}
		$material = self::TRIM_MATERIALS[$additionName] ?? null;
		if(self::isTrimTemplate($templateName) && $material !== null && $base instanceof Armor){
			$pattern = substr($templateName, strlen("minecraft:"), -strlen("_armor_trim_smithing_template"));
			$result = (clone $base)->setCount(1);
			$tag = $result->getNamedTag();
			$old = $tag->getCompoundTag("Trim");
			if($old !== null && $old->getString("Material", "") === $material && $old->getString("Pattern", "") === $pattern){
				return null; //vanilla: the same trim again does nothing
			}
			$tag->setTag("Trim", CompoundTag::create()->setString("Material", $material)->setString("Pattern", $pattern));
			$result->setNamedTag($tag);
			return [$result, self::NET_ID_BASE + count(self::transforms())];
		}
		return null;
	}

	/** Result for a smithing table (slots: 0 base, 1 material, 2 template). */
	public static function resultFor(SmithingTableInventory $table) : ?Item{
		return self::result($table->getItem(2), $table->getItem(0), $table->getItem(1))[0] ?? null;
	}

	public static function isSmithingNetId(int $id) : bool{
		return $id >= self::NET_ID_BASE && $id <= self::NET_ID_BASE + count(self::transforms());
	}
}
