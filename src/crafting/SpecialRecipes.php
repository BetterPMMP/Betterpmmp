<?php

/*
 * BetterPMMP - crafting recipes without a fixed ingredient list, for players whose client does not
 * compute them itself (Java Edition players through the bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\crafting;

use pocketmine\block\MobHead;
use pocketmine\block\utils\DyeColor;
use pocketmine\color\Color;
use pocketmine\item\Armor;
use pocketmine\item\Dye;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\FireworkRocket;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\FireworkStar;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\item\WritableBook;
use pocketmine\item\WrittenBook;
use function array_map;
use function count;

/**
 * Firework rockets, firework stars (with fade colours), dyeing leather armour, copying maps and written
 * books - the vanilla rules. Every result comes with a one-off shapeless recipe of exactly the items in the
 * grid, so the normal crafting transaction (and CraftItemEvent) checks and performs it.
 */
final class SpecialRecipes{

	/**
	 * @param Item[] $grid the non-empty grid slots
	 * @return array{CraftingRecipe, Item, list<int>}|null recipe, result, grid slots that are not used up
	 */
	public static function match(array $grid) : ?array{
		if($grid === []){
			return null;
		}
		$result = self::rocket($grid) ?? self::star($grid) ?? self::fade($grid) ?? self::dyedArmor($grid) ?? self::mapCopy($grid);
		$keep = [];
		if($result === null && ($result = self::bookCopy($grid)) !== null){
			//vanilla: the original book stays in the grid
			foreach($grid as $slot => $item){
				if($item instanceof WrittenBook){
					$keep[] = $slot;
				}
			}
		}
		if($result === null){
			return null;
		}
		$ingredients = [];
		foreach($grid as $slot => $item){
			if(!in_array($slot, $keep, true)){
				$ingredients[] = new ExactRecipeIngredient((clone $item)->setCount(1));
			}
		}
		return [new ShapelessRecipe($ingredients, [$result], ShapelessRecipeType::CRAFTING), $result, $keep];
	}

	/** @param Item[] $grid @return array<int, list<Item>> type id => items */
	private static function byType(array $grid) : array{
		$types = [];
		foreach($grid as $item){
			$types[$item->getTypeId()][] = $item;
		}
		return $types;
	}

	/** paper + 1-3 gunpowder + any firework stars => 3 rockets */
	private static function rocket(array $grid) : ?Item{
		$paper = 0;
		$gunpowder = 0;
		$explosions = [];
		foreach($grid as $item){
			if($item->getTypeId() === ItemTypeIds::PAPER){
				++$paper;
			}elseif($item->getTypeId() === ItemTypeIds::GUNPOWDER){
				++$gunpowder;
			}elseif($item instanceof FireworkStar){
				$explosions[] = $item->getExplosion();
			}else{
				return null;
			}
		}
		if($paper !== 1 || $gunpowder < 1 || $gunpowder > 3){
			return null;
		}
		return VanillaItems::FIREWORK_ROCKET()->setFlightTimeMultiplier($gunpowder)->setExplosions($explosions)->setCount(3);
	}

	/** gunpowder + dyes + at most one shape item + diamond (trail) + glowstone dust (twinkle) => star */
	private static function star(array $grid) : ?Item{
		$gunpowder = 0;
		$colors = [];
		$type = FireworkRocketType::SMALL_BALL;
		$shapes = 0;
		$trail = false;
		$twinkle = false;
		foreach($grid as $item){
			$id = $item->getTypeId();
			if($id === ItemTypeIds::GUNPOWDER){
				++$gunpowder;
			}elseif($item instanceof Dye){
				$colors[] = $item->getColor();
			}elseif($id === ItemTypeIds::FIRE_CHARGE){
				$type = FireworkRocketType::LARGE_BALL;
				++$shapes;
			}elseif($id === ItemTypeIds::GOLD_NUGGET){
				$type = FireworkRocketType::STAR;
				++$shapes;
			}elseif($id === ItemTypeIds::FEATHER){
				$type = FireworkRocketType::BURST;
				++$shapes;
			}elseif($item instanceof ItemBlock && $item->getBlock() instanceof MobHead){
				$type = FireworkRocketType::CREEPER;
				++$shapes;
			}elseif($id === ItemTypeIds::DIAMOND && !$trail){
				$trail = true;
			}elseif($id === ItemTypeIds::GLOWSTONE_DUST && !$twinkle){
				$twinkle = true;
			}else{
				return null;
			}
		}
		if($gunpowder !== 1 || $colors === [] || $shapes > 1){
			return null;
		}
		return VanillaItems::FIREWORK_STAR()->setExplosion(new FireworkRocketExplosion($type, $colors, [], $twinkle, $trail));
	}

	/** firework star + dyes => star with fade colours */
	private static function fade(array $grid) : ?Item{
		$star = null;
		$fade = [];
		foreach($grid as $item){
			if($item instanceof FireworkStar && $star === null){
				$star = $item;
			}elseif($item instanceof Dye){
				$fade[] = $item->getColor();
			}else{
				return null;
			}
		}
		if($star === null || $fade === []){
			return null;
		}
		$old = $star->getExplosion();
		return (clone $star)->setCount(1)->setExplosion(new FireworkRocketExplosion($old->getType(), $old->getColors(), $fade, $old->willTwinkle(), $old->getTrail()));
	}

	/** leather armour + dyes => coloured like vanilla: the average of the old colour and the dyes */
	private static function dyedArmor(array $grid) : ?Item{
		$armor = null;
		$colors = [];
		foreach($grid as $item){
			if($item instanceof Armor && $armor === null && in_array($item->getTypeId(), [ItemTypeIds::LEATHER_CAP, ItemTypeIds::LEATHER_TUNIC, ItemTypeIds::LEATHER_PANTS, ItemTypeIds::LEATHER_BOOTS], true)){
				$armor = $item;
			}elseif($item instanceof Dye){
				$colors[] = $item->getColor()->getRgbValue();
			}else{
				return null;
			}
		}
		if($armor === null || $colors === []){
			return null;
		}
		if(($old = $armor->getCustomColor()) !== null){
			$colors[] = $old;
		}
		return (clone $armor)->setCount(1)->setCustomColor(Color::mix(...$colors));
	}

	/** filled map + empty maps => that many more copies */
	private static function mapCopy(array $grid) : ?Item{
		$map = null;
		$empty = 0;
		foreach($grid as $item){
			$name = self::bedrockName($item);
			if($name === "minecraft:filled_map" && $map === null){
				$map = $item;
			}elseif($name === "minecraft:empty_map" || $name === "minecraft:map"){
				++$empty;
			}else{
				return null;
			}
		}
		return $map === null || $empty === 0 ? null : (clone $map)->setCount($empty + 1);
	}

	/** written book (original or copy) + books and quills => copies (a copy of a copy cannot be copied) */
	private static function bookCopy(array $grid) : ?Item{
		$book = null;
		$quills = 0;
		foreach($grid as $item){
			if($item instanceof WrittenBook && $book === null){
				$book = $item;
			}elseif($item instanceof WritableBook){
				++$quills;
			}else{
				return null;
			}
		}
		if($book === null || $quills === 0 || $book->getGeneration() >= WrittenBook::GENERATION_COPY_OF_COPY){
			return null;
		}
		return (clone $book)->setGeneration($book->getGeneration() + 1)->setCount($quills);
	}

	private static function bedrockName(Item $item) : string{
		try{
			return \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
		}catch(\Throwable){
			return "";
		}
	}
}
