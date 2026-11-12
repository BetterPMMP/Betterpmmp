<?php

/*
 * BetterPMMP - bundles.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\item\GenericBedrockItem;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use function intdiv;
use function max;
use function mt_rand;
use function str_ends_with;

/**
 * The contents of one bundle. Bedrock shows a bundle's contents as a dynamic
 * container (ContainerUIIds::DYNAMIC) identified by the "bundle_id" of the
 * item; the contents themselves are stored in the item NBT, so they survive
 * being moved, dropped or put in a chest.
 *
 * A bundle holds 64 "weight": an item weighs 64 / its max stack size (a
 * stack of 64 fills it, 16 ender pearls fill it, 1 sword fills it), a bundle
 * inside a bundle weighs 4 plus its contents.
 */
class BundleInventory extends SimpleInventory{

	public const SIZE = 64;
	public const CAPACITY = 64;
	public const TAG_BUNDLE_ID = "bundle_id";
	public const TAG_CONTENTS = "storage_item_component_content";

	public function __construct(private int $bundleId){
		parent::__construct(self::SIZE);
	}

	public function getBundleId() : int{
		return $this->bundleId;
	}

	public static function isBundle(Item $item) : bool{
		return $item instanceof GenericBedrockItem && str_ends_with($item->getBedrockName(), "bundle");
	}

	public static function getId(Item $item) : ?int{
		$tag = $item->getNamedTag()->getTag(self::TAG_BUNDLE_ID);
		return $tag === null ? null : (int) $tag->getValue();
	}

	/** Gives a bundle item its id (keeps it if it already has one). */
	public static function withId(Item $item) : Item{
		if(self::getId($item) !== null){
			return $item;
		}
		$nbt = $item->getNamedTag();
		$nbt->setInt(self::TAG_BUNDLE_ID, mt_rand(1, 0x7fffffff));
		return (clone $item)->setNamedTag($nbt);
	}

	/** @return array<int, Item> */
	public static function readContents(Item $bundle) : array{
		$list = $bundle->getNamedTag()->getListTag(self::TAG_CONTENTS);
		$contents = [];
		if($list !== null){
			foreach($list as $slot => $tag){
				if($tag instanceof CompoundTag){
					try{
						$item = Item::nbtDeserialize($tag);
					}catch(\Throwable){
						continue;
					}
					if(!$item->isNull() && $slot < self::SIZE){
						$contents[$slot] = $item;
					}
				}
			}
		}
		return $contents;
	}

	public static function writeContents(Item $bundle, Inventory $inventory) : Item{
		$last = -1;
		foreach($inventory->getContents() as $slot => $item){
			$last = max($last, $slot);
		}
		$list = [];
		for($slot = 0; $slot <= $last; ++$slot){
			$item = $inventory->getItem($slot);
			// empty slots inside the bundle are stored as an empty item, like Bedrock does
			$list[] = $item->isNull() ? CompoundTag::create()->setString("Name", "")->setByte("Count", 0)->setShort("Damage", 0) : $item->nbtSerialize();
		}
		$nbt = $bundle->getNamedTag();
		if($list === []){
			$nbt->removeTag(self::TAG_CONTENTS);
		}else{
			$nbt->setTag(self::TAG_CONTENTS, new ListTag($list));
		}
		return (clone $bundle)->setNamedTag($nbt);
	}

	public static function itemWeight(Item $item) : int{
		$weight = intdiv(self::CAPACITY, max(1, $item->getMaxStackSize())) * $item->getCount();
		if(self::isBundle($item)){
			$weight = 4 * $item->getCount();
			foreach(self::readContents($item) as $inner){
				$weight += self::itemWeight($inner);
			}
		}
		return $weight;
	}

	public static function weightOf(Inventory $inventory) : int{
		$weight = 0;
		foreach($inventory->getContents() as $item){
			$weight += self::itemWeight($item);
		}
		return $weight;
	}
}
