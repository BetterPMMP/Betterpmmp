<?php

/*
 * BetterPMMP - grindstone inventory and vanilla grindstone rules.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\inventory;

use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\TemporaryInventory;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\world\Position;
use function count;
use function intdiv;
use function max;
use function mt_rand;

class GrindstoneInventory extends SimpleInventory implements BlockInventory, TemporaryInventory{
	use BlockInventoryTrait;

	public const SLOT_INPUT = 0;
	public const SLOT_ADDITIONAL = 1;

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(2);
	}

	/**
	 * Vanilla grindstone: removes all enchantments except curses (giving back
	 * some experience), turns enchanted books into books, and combines two
	 * equal tools/armor pieces (sum of durability + 5 %).
	 *
	 * @return array{Item, int}|null [result, experience] or null if nothing to do
	 */
	public static function calculate(Item $top, Item $bottom) : ?array{
		if($top->isNull() && $bottom->isNull()){
			return null;
		}
		if(!$top->isNull() && !$bottom->isNull() && ($top->getTypeId() !== $bottom->getTypeId() || !$top instanceof Durable || $top->getCount() !== 1 || $bottom->getCount() !== 1)){
			return null;
		}
		$source = $top->isNull() ? $bottom : $top;
		$result = clone $source;
		$result->setCount(1);
		$xpBase = 0;
		foreach([$top, $bottom] as $item){
			foreach($item->getEnchantments() as $instance){
				if($instance->getType() !== VanillaEnchantments::VANISHING()){
					$xpBase += $instance->getType()->getMinEnchantingPower($instance->getLevel());
				}
			}
		}
		$kept = [];
		foreach($source->getEnchantments() as $instance){
			if($instance->getType() === VanillaEnchantments::VANISHING()){
				$kept[] = $instance;
			}
		}
		$result->removeEnchantments();
		foreach($kept as $instance){
			$result->addEnchantment($instance);
		}
		if($source->getTypeId() === ItemTypeIds::ENCHANTED_BOOK && count($kept) === 0){
			$result = VanillaItems::BOOK();
		}
		if(!$top->isNull() && !$bottom->isNull() && $result instanceof Durable && $top instanceof Durable && $bottom instanceof Durable){
			$durability = ($top->getMaxDurability() - $top->getDamage()) + ($bottom->getMaxDurability() - $bottom->getDamage()) + intdiv($top->getMaxDurability() * 5, 100);
			$result->setDamage(max(0, $result->getMaxDurability() - $durability));
		}
		if($result->getNamedTag()->getTag("RepairCost") !== null){
			$result->getNamedTag()->removeTag("RepairCost");
		}
		if($xpBase === 0 && $top->isNull() !== $bottom->isNull()){
			return null; // a single unenchanted item: nothing to grind
		}
		$half = intdiv($xpBase + 1, 2);
		$xp = $xpBase > 0 ? $half + mt_rand(0, $half) : 0;
		return [$result, $xp];
	}
}
