<?php

/*
 * BetterPMMP - inventory clicks of Java Edition players (Java bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\block\inventory\AnvilInventory;
use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\inventory\CartographyTableInventory;
use pocketmine\block\inventory\EnchantInventory;
use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\block\inventory\CraftingTableInventory;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\inventory\SmithingTableInventory;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\item\Durable;
use pocketmine\crafting\CraftingGrid;
use pocketmine\crafting\CraftingRecipe;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\TradeInventory;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use function count;
use function implode;
use function in_array;
use function mb_substr;
use function explode;
use function intdiv;
use function max;
use function min;
use const PHP_INT_MAX;
use function spl_object_id;

/**
 * ViaBedrock (the Bedrock part of ViaProxy) cannot translate inventory clicks
 * yet. The BetterPMMP-Bridge plugin for ViaProxy forwards each Java click
 * ("window;slot;button;action") instead, and this class performs it the way a
 * vanilla Java server does: pick up, place, split, swap with the hotbar or the
 * off hand, shift-click, drop, collect (double click) and drag. Every click is
 * an ordinary {@link InventoryTransaction}, so plugins (chest menus, shops,
 * anti-cheat) see it like any other inventory change. Afterwards the involved
 * inventories are sent again so the Java client shows the real result.
 */
final class JavaInventoryClick{

	/** Java player inventory screen (window 0): slot layout */
	private const INV_ARMOR_START = 5;
	private const INV_MAIN_START = 9;
	private const INV_HOTBAR_START = 36;
	private const INV_OFFHAND = 45;
	private const OUTSIDE = -999;
	private const CREATED_OUTPUT_SLOT = 50;
	/** Java anvil, grindstone and cartography screens: 0 and 1 inputs, 2 result */
	private const STATION_RESULT = 2;
	/** Java smithing screen: 0 template, 1 base item, 2 material, 3 result */
	private const SMITHING_RESULT = 3;
	/** Java brewing stand: 0-2 bottles, 3 ingredient, 4 fuel => BetterPMMP slot */
	private const BREWING_JAVA_TO_SLOT = [
		BrewingStandInventory::SLOT_BOTTLE_LEFT, BrewingStandInventory::SLOT_BOTTLE_MIDDLE, BrewingStandInventory::SLOT_BOTTLE_RIGHT,
		BrewingStandInventory::SLOT_INGREDIENT, BrewingStandInventory::SLOT_FUEL
	];

	/** @var array<int, array{int, list<int>}> player id => [drag type, slots] */
	private static array $drags = [];

	/** @var array<string, array{Inventory, int, Item, Item}> pending slot changes: key => [inventory, slot, original, new] */
	private array $changes = [];
	/** @var Item[] */
	private array $drops = [];

	private function __construct(
		private readonly Player $player,
		private readonly InventoryManager $manager,
		private readonly int $windowId
	){}

	/** Handles a "window;slot;button;action" message from the bridge. */
	public static function handle(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 4){
			return;
		}
		[$window, $slot, $button, $action] = $parts;
		$click = new self($player, $manager, (int) $window);
		$click->perform((int) $slot, (int) $button, $action);
	}

	private function perform(int $slot, int $button, string $action) : void{
		if($slot === 0 && $this->grid() !== null && ($action === "PICKUP" || $action === "QUICK_MOVE")){
			$this->craftResult($action === "QUICK_MOVE");
			$this->commit();
			return;
		}
		if($slot === self::SMITHING_RESULT && $this->container() instanceof SmithingTableInventory){
			if($action === "PICKUP" || $action === "QUICK_MOVE"){
				$this->smithResult($action === "QUICK_MOVE");
			}
			$this->commit();
			return;
		}
		if($slot === self::LOOM_RESULT && $this->container() instanceof \pocketmine\block\inventory\LoomInventory){
			if($action === "PICKUP" || $action === "QUICK_MOVE"){
				$this->loomResult($action === "QUICK_MOVE");
			}
			$this->commit();
			return;
		}
		if($slot === self::STONECUTTER_RESULT && $this->container() instanceof \pocketmine\block\inventory\StonecutterInventory){
			if($action === "PICKUP" || $action === "QUICK_MOVE"){
				$this->stonecutResult($action === "QUICK_MOVE");
			}
			$this->commit();
			return;
		}
		if($slot === self::STATION_RESULT && self::hasResultSlot($this->container())){
			if($action === "PICKUP" || $action === "QUICK_MOVE"){
				$this->stationResult($action === "QUICK_MOVE");
			}
			$this->commit();
			return;
		}
		match($action){
			"PICKUP" => $this->pickup($slot, $button),
			"QUICK_MOVE" => $this->quickMove($slot),
			"SWAP" => $this->swap($slot, $button),
			"THROW" => $this->throw($slot, $button),
			"PICKUP_ALL" => $this->pickupAll($slot),
			"QUICK_CRAFT" => $this->drag($slot, $button),
			default => null,
		};
		$this->commit();
	}

	// ---------------------------------------------------------------- slots

	/**
	 * Inventory and slot behind a Java slot number of the current window.
	 * @return array{Inventory, int}|null
	 */
	private function resolve(int $javaSlot) : ?array{
		$inventory = $this->player->getInventory();
		if($this->windowId === 0){
			return match(true){
				$javaSlot >= 1 && $javaSlot <= 4 => [$this->player->getCraftingGrid(), $javaSlot - 1],
				$javaSlot >= self::INV_ARMOR_START && $javaSlot < self::INV_MAIN_START => [$this->player->getArmorInventory(), $javaSlot - self::INV_ARMOR_START],
				$javaSlot >= self::INV_MAIN_START && $javaSlot < self::INV_HOTBAR_START => [$inventory, $javaSlot],
				$javaSlot >= self::INV_HOTBAR_START && $javaSlot < self::INV_OFFHAND => [$inventory, $javaSlot - self::INV_HOTBAR_START],
				$javaSlot === self::INV_OFFHAND => [$this->player->getOffHandInventory(), 0],
				default => null,
			};
		}
		$container = $this->container();
		if($container === null){
			return null;
		}
		if($container instanceof CraftingTableInventory){
			// Java crafting screen: 0 result, 1-9 grid, 10-36 inventory, 37-45 hotbar
			return match(true){
				$javaSlot >= 1 && $javaSlot <= 9 => [$container, $javaSlot - 1],
				$javaSlot >= 10 && $javaSlot < 37 => [$inventory, $javaSlot - 10 + 9],
				$javaSlot >= 37 && $javaSlot < 46 => [$inventory, $javaSlot - 37],
				default => null,
			};
		}
		if($container instanceof SmithingTableInventory){
			// Java smithing screen: 0 template, 1 base, 2 material, 3 result, 4-30 inventory, 31-39 hotbar
			return match(true){
				$javaSlot === 0 => [$container, 2],
				$javaSlot === 1 => [$container, 0],
				$javaSlot === 2 => [$container, 1],
				$javaSlot >= 4 && $javaSlot < 31 => [$inventory, $javaSlot - 4 + 9],
				$javaSlot >= 31 && $javaSlot < 40 => [$inventory, $javaSlot - 31],
				default => null,
			};
		}
		if($container instanceof \pocketmine\block\inventory\LoomInventory){
			// Java loom: 0 banner, 1 dye, 2 pattern, 3 result, 4-30 inventory, 31-39 hotbar
			return match(true){
				$javaSlot >= 0 && $javaSlot <= 2 => [$container, $javaSlot],
				$javaSlot >= 4 && $javaSlot < 31 => [$inventory, $javaSlot - 4 + 9],
				$javaSlot >= 31 && $javaSlot < 40 => [$inventory, $javaSlot - 31],
				default => null,
			};
		}
		if($container instanceof \pocketmine\block\inventory\StonecutterInventory){
			// Java stonecutter: 0 input, 1 result, 2-28 inventory, 29-37 hotbar
			return match(true){
				$javaSlot === 0 => [$container, 0],
				$javaSlot >= 2 && $javaSlot < 29 => [$inventory, $javaSlot - 2 + 9],
				$javaSlot >= 29 && $javaSlot < 38 => [$inventory, $javaSlot - 29],
				default => null,
			};
		}
		if(self::hasResultSlot($container)){
			// Java anvil, grindstone, cartography table: 0-1 inputs, 2 result, 3-29 inventory, 30-38 hotbar
			return match(true){
				$javaSlot === 0 || $javaSlot === 1 => [$container, $javaSlot],
				$javaSlot >= 3 && $javaSlot < 30 => [$inventory, $javaSlot - 3 + 9],
				$javaSlot >= 30 && $javaSlot < 39 => [$inventory, $javaSlot - 30],
				default => null,
			};
		}
		$size = $container->getSize();
		if($container instanceof BrewingStandInventory && $javaSlot >= 0 && $javaSlot < $size){
			return [$container, self::BREWING_JAVA_TO_SLOT[$javaSlot]];
		}
		return match(true){
			$javaSlot >= 0 && $javaSlot < $size => [$container, $javaSlot],
			$javaSlot >= $size && $javaSlot < $size + 27 => [$inventory, $javaSlot - $size + 9],
			$javaSlot >= $size + 27 && $javaSlot < $size + 36 => [$inventory, $javaSlot - $size - 27],
			default => null,
		};
	}

	private function container() : ?Inventory{
		$inventory = $this->manager->getInventoryByWindowId($this->windowId);
		return $inventory !== null && $this->player->getCurrentWindow() === $inventory ? $inventory : null;
	}

	/** @return list<int> Java slot numbers of the current window, in Java's order */
	private function windowSlots() : array{
		$container = $this->container();
		$count = $this->windowId === 0 ? 46 : $this->containerSlotCount($container) + 36;
		$slots = [];
		for($i = 0; $i < $count; ++$i){
			$slots[] = $i;
		}
		return $slots;
	}

	/** Number of Java slots in front of the player inventory in the current window. */
	private function containerSlotCount(?Inventory $container) : int{
		return match(true){
			$container === null => 0,
			$container instanceof CraftingTableInventory => 10,
			$container instanceof SmithingTableInventory => 4,
			$container instanceof \pocketmine\block\inventory\StonecutterInventory => 2,
			$container instanceof \pocketmine\block\inventory\LoomInventory => 4,
			self::hasResultSlot($container) => 3,
			default => $container->getSize(),
		};
	}

	/** Slots nothing may be put into: the result of a furnace. */
	private static function isOutputOnly(Inventory $inventory, int $slot) : bool{
		return $inventory instanceof FurnaceInventory && $slot === FurnaceInventory::SLOT_RESULT;
	}

	/**
	 * Where a shift-click from the player inventory goes in a furnace, brewing stand or smithing table, like in
	 * vanilla; null = this window has no such rules.
	 * @return list<array{Inventory, int}>|null
	 */
	private function stationTargets(Inventory $container, Item $item) : ?array{
		if($container instanceof FurnaceInventory){
			if($this->player->getServer()->getCraftingManager()->getFurnaceRecipeManager($container->getFurnaceType())->match($item) !== null){
				return [[$container, FurnaceInventory::SLOT_INPUT]];
			}
			return $item->getFuelTime() > 0 ? [[$container, FurnaceInventory::SLOT_FUEL]] : [];
		}
		if($container instanceof BrewingStandInventory){
			$id = $item->getTypeId();
			if($id === \pocketmine\item\ItemTypeIds::BLAZE_POWDER){
				return [[$container, BrewingStandInventory::SLOT_FUEL], [$container, BrewingStandInventory::SLOT_INGREDIENT]];
			}
			if($id === \pocketmine\item\ItemTypeIds::POTION || $id === \pocketmine\item\ItemTypeIds::SPLASH_POTION || $id === \pocketmine\item\ItemTypeIds::GLASS_BOTTLE || $item->getMaxStackSize() === 1){
				return [[$container, BrewingStandInventory::SLOT_BOTTLE_LEFT], [$container, BrewingStandInventory::SLOT_BOTTLE_MIDDLE], [$container, BrewingStandInventory::SLOT_BOTTLE_RIGHT]];
			}
			return [[$container, BrewingStandInventory::SLOT_INGREDIENT]];
		}
		if($container instanceof AnvilInventory || $container instanceof GrindstoneInventory){
			return [[$container, 0], [$container, 1]];
		}
		if($container instanceof CartographyTableInventory){
			$name = \pocketmine\crafting\SmithingRecipes::nameOf($item);
			return $name === "minecraft:filled_map" ? [[$container, 0]] : (in_array($name, ["minecraft:paper", "minecraft:empty_map", "minecraft:map", "minecraft:glass_pane"], true) ? [[$container, 1]] : []);
		}
		if($container instanceof \pocketmine\block\inventory\StonecutterInventory){
			return [[$container, 0]];
		}
		if($container instanceof \pocketmine\block\inventory\LoomInventory){
			return match(true){
				$item instanceof \pocketmine\item\Banner => [[$container, 0]],
				$item instanceof \pocketmine\item\Dye => [[$container, 1]],
				\pocketmine\inventory\transaction\LoomTransaction::patternOfItem($item) !== null => [[$container, 2]],
				default => [],
			};
		}
		if($container instanceof \pocketmine\block\inventory\BeaconInventory){
			return \pocketmine\block\Beacon::isPayment($item) ? [[$container, 0]] : [];
		}
		if($container instanceof TradeInventory){
			return []; // vanilla: shift-click moves between the inventory rows and the hotbar, not into the payment slots
		}
		if($container instanceof EnchantInventory){
			return $item->getTypeId() === \pocketmine\item\ItemTypeIds::LAPIS_LAZULI ? [[$container, EnchantInventory::SLOT_LAPIS]] : [[$container, EnchantInventory::SLOT_INPUT]];
		}
		if($container instanceof SmithingTableInventory){
			$name = \pocketmine\crafting\SmithingRecipes::nameOf($item);
			return match(true){
				str_ends_with($name, "_smithing_template") => [[$container, 2]],
				isset(\pocketmine\crafting\SmithingRecipes::TRIM_MATERIALS[$name]) => [[$container, 1]],
				isset(self::netheriteUpgrades()[$item->getTypeId()]) || $item instanceof \pocketmine\item\Armor => [[$container, 0]],
				default => [],
			};
		}
		return null;
	}

	private function get(Inventory $inventory, int $slot) : Item{
		$key = spl_object_id($inventory) . ":" . $slot;
		return clone ($this->changes[$key][3] ?? $inventory->getItem($slot));
	}

	private function set(Inventory $inventory, int $slot, Item $item) : void{
		$key = spl_object_id($inventory) . ":" . $slot;
		$original = $this->changes[$key][2] ?? $inventory->getItem($slot);
		$this->changes[$key] = [$inventory, $slot, $original, $item->isNull() ? VanillaItems::AIR() : clone $item];
	}

	private function cursor() : Item{
		return $this->get($this->player->getCursorInventory(), 0);
	}

	private function setCursor(Item $item) : void{
		$this->set($this->player->getCursorInventory(), 0, $item);
	}

	private static function maxStack(Item $item, Inventory $inventory) : int{
		return min($item->getMaxStackSize(), $inventory->getMaxStackSize());
	}

	// -------------------------------------------------------------- actions

	/** Left click (button 0) or right click (button 1). */
	private function pickup(int $javaSlot, int $button) : void{
		$cursor = $this->cursor();
		if($javaSlot === self::OUTSIDE){
			if(!$cursor->isNull()){
				$drop = $cursor->pop($button === 0 ? $cursor->getCount() : 1);
				$this->drops[] = $drop;
				$this->setCursor($cursor);
			}
			return;
		}
		$target = $this->resolve($javaSlot);
		if($target === null){
			return;
		}
		[$inventory, $slot] = $target;
		$item = $this->get($inventory, $slot);

		if($cursor->isNull()){
			if($item->isNull()){
				return;
			}
			$take = $button === 0 ? $item->getCount() : (int) (($item->getCount() + 1) / 2);
			$this->setCursor($item->pop($take));
			$this->set($inventory, $slot, $item);
			return;
		}
		if(self::isOutputOnly($inventory, $slot)){
			// vanilla: a result slot only gives - the whole stack, if the cursor can take it
			if(!$item->isNull() && $item->canStackWith($cursor) && $cursor->getCount() + $item->getCount() <= $cursor->getMaxStackSize()){
				$this->setCursor($cursor->setCount($cursor->getCount() + $item->getCount()));
				$this->set($inventory, $slot, VanillaItems::AIR());
			}
			return;
		}
		if($item->isNull() || $item->canStackWith($cursor)){
			$space = self::maxStack($cursor, $inventory) - ($item->isNull() ? 0 : $item->getCount());
			$amount = min($space, $button === 0 ? $cursor->getCount() : 1);
			if($amount <= 0){
				return;
			}
			$placed = $item->isNull() ? (clone $cursor)->setCount($amount) : $item->setCount($item->getCount() + $amount);
			$this->set($inventory, $slot, $placed);
			$cursor->pop($amount);
			$this->setCursor($cursor);
			return;
		}
		if($cursor->getCount() <= self::maxStack($cursor, $inventory)){
			$this->set($inventory, $slot, $cursor);
			$this->setCursor($item);
		}
	}

	/** Shift click: moves the stack to the other part of the window. */
	private function quickMove(int $javaSlot) : void{
		$source = $this->resolve($javaSlot);
		if($source === null){
			return;
		}
		[$inventory, $slot] = $source;
		$item = $this->get($inventory, $slot);
		if($item->isNull()){
			return;
		}
		$targets = $this->quickMoveTargets($javaSlot, $item);
		$this->insert($item, $targets);
		$this->set($inventory, $slot, $item);
	}

	/** @return list<array{Inventory, int}> */
	private function quickMoveTargets(int $javaSlot, Item $item) : array{
		$inventory = $this->player->getInventory();
		$playerSlots = [];
		for($i = 8; $i >= 0; --$i){
			$playerSlots[] = [$inventory, $i];
		}
		for($i = 35; $i >= 9; --$i){
			$playerSlots[] = [$inventory, $i];
		}
		if($this->windowId === 0){
			$isHotbar = $javaSlot >= self::INV_HOTBAR_START && $javaSlot < self::INV_OFFHAND;
			$targets = [];
			for($i = $isHotbar ? 9 : 0; $i < ($isHotbar ? 36 : 9); ++$i){
				$targets[] = [$inventory, $i];
			}
			return $targets;
		}
		$container = $this->container();
		if($container === null){
			return [];
		}
		$first = $this->containerSlotCount($container);
		if($javaSlot < $first){
			return $playerSlots;
		}
		$station = $this->stationTargets($container, $item);
		if($station !== null && $station !== []){
			return $station;
		}
		if($station !== null){
			// nothing for this block: vanilla moves between the inventory rows and the hotbar
			$targets = [];
			$fromHotbar = $javaSlot >= $first + 27;
			for($i = $fromHotbar ? 9 : 0; $i < ($fromHotbar ? 36 : 9); ++$i){
				$targets[] = [$inventory, $i];
			}
			return $targets;
		}
		$targets = [];
		for($i = 0; $i < $container->getSize(); ++$i){
			$targets[] = [$container, $i];
		}
		return $targets;
	}

	/**
	 * Puts as much of the item as possible into the slots (first onto equal stacks, then into empty slots).
	 * The item's count is reduced by what was placed.
	 * @param list<array{Inventory, int}> $slots
	 */
	private function insert(Item $item, array $slots) : void{
		foreach([false, true] as $empty){
			foreach($slots as [$inventory, $slot]){
				if($item->isNull()){
					return;
				}
				if(self::isOutputOnly($inventory, $slot)){
					continue;
				}
				$existing = $this->get($inventory, $slot);
				if($empty !== $existing->isNull() || (!$empty && !$existing->canStackWith($item))){
					continue;
				}
				$max = self::maxStack($item, $inventory);
				$amount = min($item->getCount(), $max - ($empty ? 0 : $existing->getCount()));
				if($amount <= 0){
					continue;
				}
				$this->set($inventory, $slot, $empty ? (clone $item)->setCount($amount) : $existing->setCount($existing->getCount() + $amount));
				$item->pop($amount);
			}
		}
	}

	/** Number keys 1-9 (button 0-8) swap with the hotbar, F (button 40) with the off hand. */
	private function swap(int $javaSlot, int $button) : void{
		$target = $this->resolve($javaSlot);
		if($target === null){
			return;
		}
		$other = $button === 40 ? [$this->player->getOffHandInventory(), 0] : ($button >= 0 && $button < 9 ? [$this->player->getInventory(), $button] : null);
		if($other === null){
			return;
		}
		[$inventory, $slot] = $target;
		[$otherInventory, $otherSlot] = $other;
		$a = $this->get($inventory, $slot);
		$b = $this->get($otherInventory, $otherSlot);
		if(self::isOutputOnly($inventory, $slot) && !$b->isNull()){
			return;
		}
		$this->set($inventory, $slot, $b);
		$this->set($otherInventory, $otherSlot, $a);
	}

	/** Q (button 0: one item, button 1 with Ctrl: whole stack) over a slot. */
	private function throw(int $javaSlot, int $button) : void{
		if(!$this->cursor()->isNull()){
			return;
		}
		$target = $this->resolve($javaSlot);
		if($target === null){
			return;
		}
		[$inventory, $slot] = $target;
		$item = $this->get($inventory, $slot);
		if($item->isNull()){
			return;
		}
		$this->drops[] = $item->pop($button === 1 ? $item->getCount() : 1);
		$this->set($inventory, $slot, $item);
	}

	/** Double click: collects equal items into the cursor. */
	private function pickupAll(int $javaSlot) : void{
		$cursor = $this->cursor();
		if($cursor->isNull()){
			return;
		}
		$max = $cursor->getMaxStackSize();
		foreach($this->windowSlots() as $slot){
			if($cursor->getCount() >= $max){
				break;
			}
			$target = $this->resolve($slot);
			if($target === null){
				continue;
			}
			[$inventory, $index] = $target;
			$item = $this->get($inventory, $index);
			if($item->isNull() || !$item->canStackWith($cursor)){
				continue;
			}
			$amount = min($item->getCount(), $max - $cursor->getCount());
			$cursor->setCount($cursor->getCount() + $amount);
			$item->pop($amount);
			$this->set($inventory, $index, $item);
		}
		$this->setCursor($cursor);
	}

	/**
	 * Dragging the cursor over several slots. Button: start 0/4/8, add slot 1/5/9, end 2/6/10
	 * (left = spread evenly, right = one item each, middle = creative full stacks).
	 */
	private function drag(int $javaSlot, int $button) : void{
		$id = $this->player->getId();
		$type = intdiv($button, 4);
		$stage = $button % 4;
		if($stage === 0){
			self::$drags[$id] = [$type, []];
			return;
		}
		if(!isset(self::$drags[$id]) || self::$drags[$id][0] !== $type){
			unset(self::$drags[$id]);
			return;
		}
		if($stage === 1){
			self::$drags[$id][1][] = $javaSlot;
			return;
		}
		[, $slots] = self::$drags[$id];
		unset(self::$drags[$id]);
		$cursor = $this->cursor();
		if($cursor->isNull() || $slots === []){
			return;
		}
		if($type === 2){
			if(!$this->player->isCreative()){
				return;
			}
		}
		$per = match($type){
			0 => max(1, intdiv($cursor->getCount(), count($slots))),
			1 => 1,
			default => $cursor->getMaxStackSize(),
		};
		foreach($slots as $slot){
			if($type !== 2 && $cursor->getCount() <= 0){
				break;
			}
			$target = $this->resolve($slot);
			if($target === null){
				continue;
			}
			[$inventory, $index] = $target;
			if(self::isOutputOnly($inventory, $index)){
				continue;
			}
			$item = $this->get($inventory, $index);
			if(!$item->isNull() && !$item->canStackWith($cursor)){
				continue;
			}
			$space = self::maxStack($cursor, $inventory) - ($item->isNull() ? 0 : $item->getCount());
			$amount = min($space, $per, $type === 2 ? $per : $cursor->getCount());
			if($amount <= 0){
				continue;
			}
			$this->set($inventory, $index, $item->isNull() ? (clone $cursor)->setCount($amount) : $item->setCount($item->getCount() + $amount));
			if($type !== 2){
				$cursor->pop($amount);
			}
		}
		if($type !== 2){
			$this->setCursor($cursor);
		}
	}

	// --------------------------------------------------------------- commit

	private function commit() : void{
		/** @var InventoryAction[] $actions */
		$actions = [];
		$inventories = [];
		foreach($this->changes as [$inventory, $slot, $original, $new]){
			$inventories[spl_object_id($inventory)] = $inventory;
			if(!$original->equalsExact($new)){
				$actions[] = new SlotChangeAction($inventory, $slot, $original, $new);
			}
		}
		foreach($this->drops as $drop){
			if(!$drop->isNull()){
				$actions[] = new DropItemAction($drop);
			}
		}
		if($actions !== []){
			try{
				if($this->transactionFactory !== null){
					// anvil, grindstone, cartography and enchanting table: the same transactions as for Bedrock players
					$transaction = ($this->transactionFactory)();
					foreach($actions as $action){
						$transaction->addAction($action);
					}
				}else{
					$transaction = $this->craftRecipe !== null
						? new CraftingTransaction($this->player, $this->player->getServer()->getCraftingManager(), $actions, $this->craftRecipe, $this->craftRepetitions)
						: new InventoryTransaction($this->player, $actions);
				}
				$transaction->execute();
			}catch(TransactionException $e){
				$this->player->getServer()->getLogger()->debug("[Java-Bridge] click of " . $this->player->getName() . " rejected: " . $e->getMessage());
			}
		}
		// the Java client predicted the click itself: send the real state back
		$inventories[spl_object_id($this->player->getCursorInventory())] = $this->player->getCursorInventory();
		$inventories[spl_object_id($this->player->getInventory())] = $this->player->getInventory();
		if(($container = $this->container()) !== null){
			$inventories[spl_object_id($container)] = $container;
		}
		$grid = $this->grid();
		if($grid !== null){
			$inventories[spl_object_id($grid)] = $grid;
		}
		foreach($inventories as $inventory){
			try{
				$this->manager->syncContents($inventory);
			}catch(\LogicException){
				// not tracked for this player (e.g. a window that was closed meanwhile)
			}
		}
		if($grid !== null){
			self::sendCraftingResult($this->player, $this->manager, $grid, $this->windowId);
		}elseif($container instanceof SmithingTableInventory){
			self::sendSmithingResult($this->player, $container, $this->windowId);
		}elseif($container !== null){
			self::sendStationState($this->player, $container, $this->windowId);
		}
	}

	// ------------------------------------------------------------- crafting

	private ?CraftingRecipe $craftRecipe = null;
	/** @var list<int> grid slots the last matched recipe does not use up */
	private static array $keptSlots = [];
	private int $craftRepetitions = 0;

	/** The crafting grid of the current window: the 2x2 grid of the inventory screen or a crafting table. */
	private function grid() : ?CraftingGrid{
		if($this->windowId === 0){
			return $this->player->getCraftingGrid();
		}
		$container = $this->container();
		return $container instanceof CraftingTableInventory ? $container : null;
	}

	/**
	 * Recipe the grid currently matches and its result.
	 * @return array{CraftingRecipe, Item}|null
	 */
	public static function findRecipe(Player $player, CraftingGrid $grid) : ?array{
		if($grid->getContents() === []){
			return null;
		}
		//BetterPMMP: fireworks, dyed leather armour, map and book copies (the Bedrock client computes these itself)
		if(($special = \pocketmine\crafting\SpecialRecipes::match($grid->getContents())) !== null){
			self::$keptSlots = $special[2];
			return [$special[0], $special[1]];
		}
		self::$keptSlots = [];
		$manager = $player->getServer()->getCraftingManager();
		foreach([$manager->getShapedRecipes(), $manager->getShapelessRecipes()] as $groups){
			foreach($groups as $recipes){
				foreach($recipes as $recipe){
					if($recipe instanceof ShapelessRecipe && $recipe->getType() !== ShapelessRecipeType::CRAFTING){
						continue; // stonecutter, cartography table and smithing recipes are not for the crafting grid
					}
					if($recipe->matchesCraftingGrid($grid)){
						$results = $recipe->getResultsFor($grid);
						if(isset($results[0]) && !$results[0]->isNull()){
							return [$recipe, $results[0]];
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * Clicking the result slot crafts once into the cursor; shift-click crafts as often as possible into the inventory.
	 */
	private function craftResult(bool $all) : void{
		$grid = $this->grid();
		$found = $grid === null ? null : self::findRecipe($this->player, $grid);
		if($grid === null || $found === null){
			return;
		}
		[$recipe, $result] = $found;
		$kept = self::$keptSlots;
		$times = PHP_INT_MAX;
		foreach($grid->getContents() as $slot => $item){
			if(!in_array($slot, $kept, true)){
				$times = min($times, $item->getCount());
			}
		}
		if(!$all){
			$times = 1;
		}
		$targets = [];
		$inventory = $this->player->getInventory();
		for($i = 8; $i >= 0; --$i){
			$targets[] = [$inventory, $i];
		}
		for($i = 35; $i >= 9; --$i){
			$targets[] = [$inventory, $i];
		}
		$done = 0;
		for($n = 0; $n < $times && $n < 64; ++$n){
			if($all){
				$output = clone $result;
				$snapshot = $this->changes;
				$this->insert($output, $targets);
				if(!$output->isNull()){
					$this->changes = $snapshot; // no room for a whole result any more
					break;
				}
			}else{
				$cursor = $this->cursor();
				if(!$cursor->isNull() && (!$cursor->canStackWith($result) || $cursor->getCount() + $result->getCount() > $cursor->getMaxStackSize())){
					break;
				}
				$this->setCursor($cursor->isNull() ? clone $result : $cursor->setCount($cursor->getCount() + $result->getCount()));
			}
			foreach($grid->getContents() as $slot => $item){
				if(in_array($slot, self::$keptSlots, true)){
					continue;
				}
				$current = $this->get($grid, $slot);
				$current->pop();
				$this->set($grid, $slot, $current);
			}
			++$done;
		}
		if($done > 0){
			$this->craftRecipe = $recipe;
			$this->craftRepetitions = $done;
		}
	}

	/**
	 * Java shows the crafting result in its own slot, which a Bedrock client computes itself: send it
	 * (as the "created output" UI slot, picked up by the BetterPMMP-Bridge plugin).
	 */
	public static function sendCraftingResult(Player $player, InventoryManager $manager, CraftingGrid $grid, int $windowId) : void{
		$found = self::findRecipe($player, $grid);
		$result = $found === null ? VanillaItems::AIR() : $found[1];
		$session = $player->getNetworkSession();
		$session->sendDataPacket(InventorySlotPacket::create(
			ContainerIds::UI,
			self::CREATED_OUTPUT_SLOT,
			null,
			null,
			new ItemStackWrapper(0, $session->getTypeConverter()->coreItemStackToNet($result))
		));
		$session->sendDataPacket(ScriptMessagePacket::create("betterpmmp:craft_result", (string) $windowId));
	}

	// ------------------------------------------------------------- smithing

	/** @return array<int, \Closure() : Item> diamond item type id => netherite item */
	private static function netheriteUpgrades() : array{
		static $map = null;
		return $map ??= [
			\pocketmine\item\ItemTypeIds::DIAMOND_SWORD => VanillaItems::NETHERITE_SWORD(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_PICKAXE => VanillaItems::NETHERITE_PICKAXE(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_AXE => VanillaItems::NETHERITE_AXE(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_SHOVEL => VanillaItems::NETHERITE_SHOVEL(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_HOE => VanillaItems::NETHERITE_HOE(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_HELMET => VanillaItems::NETHERITE_HELMET(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_CHESTPLATE => VanillaItems::NETHERITE_CHESTPLATE(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_LEGGINGS => VanillaItems::NETHERITE_LEGGINGS(...),
			\pocketmine\item\ItemTypeIds::DIAMOND_BOOTS => VanillaItems::NETHERITE_BOOTS(...),
		];
	}

	/**
	 * Netherite upgrade: template + diamond tool or armour + netherite ingot. Enchantments, name and damage stay.
	 */
	public static function smithingResult(SmithingTableInventory $table) : ?Item{
		//the same recipes as for Bedrock players: netherite upgrades and armour trims
		return \pocketmine\crafting\SmithingRecipes::resultFor($table);
	}

	/** Clicking the result takes one upgraded item (shift-click: into the inventory) and uses up one of each input. */
	private function smithResult(bool $intoInventory) : void{
		$table = $this->container();
		if(!$table instanceof SmithingTableInventory){
			return;
		}
		$result = self::smithingResult($table);
		if($result === null){
			return;
		}
		$inventory = $this->player->getInventory();
		$cursorInventory = $this->player->getCursorInventory();
		if($intoInventory){
			if(!$inventory->canAddItem($result)){
				return;
			}
		}elseif(!$cursorInventory->getItem(0)->isNull()){
			return;
		}
		// the upgrade creates and destroys items, which no ordinary inventory transaction allows
		for($slot = 0; $slot < 3; ++$slot){
			$item = $table->getItem($slot);
			$item->pop();
			$table->setItem($slot, $item);
		}
		if($intoInventory){
			$inventory->addItem($result);
		}else{
			$cursorInventory->setItem(0, $result);
		}
		$position = $table->getHolder();
		$position->getWorld()->addSound($position->add(0.5, 0.5, 0.5), new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::SMITHING_TABLE_USE));
	}

	/** Shows the result of the smithing table (as the "created output" UI slot, like the crafting result). */
	public static function sendSmithingResult(Player $player, SmithingTableInventory $table, int $windowId) : void{
		$session = $player->getNetworkSession();
		$session->sendDataPacket(InventorySlotPacket::create(
			ContainerIds::UI,
			self::CREATED_OUTPUT_SLOT,
			null,
			null,
			new ItemStackWrapper(0, $session->getTypeConverter()->coreItemStackToNet(self::smithingResult($table) ?? VanillaItems::AIR()))
		));
		$session->sendDataPacket(ScriptMessagePacket::create("betterpmmp:craft_result", (string) $windowId));
	}

	/**
	 * Java creative inventory: the Java client sets slots directly ("slot;bedrock item name;meta;count",
	 * translated by the bridge). Java slot numbers of the player screen: 5-8 armor, 9-35 inventory,
	 * 36-44 hotbar, 45 offhand, -1 = throw the item out of the creative menu.
	 */
	public static function handleCreative(Player $player, string $value) : void{
		if(!$player->isCreative()){
			return;
		}
		$parts = explode(";", $value, 4);
		if(count($parts) !== 4){
			return;
		}
		$slot = (int) $parts[0];
		$item = \pocketmine\item\VanillaItems::AIR();
		if($parts[1] !== "" && (int) $parts[3] > 0){
			try{
				$item = \pocketmine\item\Item::nbtDeserialize(\pocketmine\nbt\tag\CompoundTag::create()
					->setString("Name", $parts[1])
					->setShort("Damage", (int) $parts[2])
					->setByte("Count", min(255, (int) $parts[3])));
			}catch(\Throwable){
				return; // unknown item
			}
			$item->setCount(min($item->getMaxStackSize(), max(1, (int) $parts[3])));
		}
		if($slot === -1){
			if(!$item->isNull()){
				$player->dropItem($item);
			}
			return;
		}
		if($slot >= 36 && $slot <= 44){
			$player->getInventory()->setItem($slot - 36, $item);
		}elseif($slot >= 9 && $slot <= 35){
			$player->getInventory()->setItem($slot, $item);
		}elseif($slot >= 5 && $slot <= 8){
			$player->getArmorInventory()->setItem($slot - 5, $item);
		}elseif($slot === 45){
			$player->getOffHandInventory()->setItem(0, $item);
		}
	}

	// ------------------------------------------- anvil, grindstone, cartography and enchanting table

	/** @var (\Closure() : InventoryTransaction)|null transaction that performs the changes of this click */
	private ?\Closure $transactionFactory = null;

	/** @var array<int, array{int, string}> player id => [anvil window id, name typed into the anvil] */
	private static array $renames = [];

	/** Java screens with two inputs and a result slot. */
	private static function hasResultSlot(?Inventory $container) : bool{
		return $container instanceof AnvilInventory || $container instanceof GrindstoneInventory || $container instanceof CartographyTableInventory || $container instanceof TradeInventory;
	}

	private static function renameFor(Player $player, Inventory $anvil) : ?string{
		$entry = self::$renames[spl_object_id($player)] ?? null;
		return $entry !== null && $entry[0] === spl_object_id($anvil) ? $entry[1] : null;
	}

	/**
	 * What the station in front of the player would make now.
	 * @return array{Item, array<int, int>, \Closure() : InventoryTransaction, int}|null result, slot => items used up, transaction, anvil level cost
	 */
	private static function stationCalculation(Player $player, Inventory $container) : ?array{
		if($container instanceof AnvilInventory){
			$calculated = \pocketmine\block\utils\AnvilHelper::calculate($container->getItem(AnvilInventory::SLOT_INPUT), $container->getItem(AnvilInventory::SLOT_MATERIAL), self::renameFor($player, $container), $player->isCreative());
			if($calculated === null){
				return null;
			}
			[$result, $cost, $used] = $calculated;
			return [$result, [AnvilInventory::SLOT_INPUT => 1, AnvilInventory::SLOT_MATERIAL => $used], fn() => new \pocketmine\inventory\transaction\AnvilTransaction($player, $container, $result, $cost, $used), $cost];
		}
		if($container instanceof GrindstoneInventory){
			$calculated = GrindstoneInventory::calculate($container->getItem(0), $container->getItem(1));
			if($calculated === null){
				return null;
			}
			[$result, $xp] = $calculated;
			$used = [];
			foreach([0, 1] as $slot){
				if(!$container->getItem($slot)->isNull()){
					$used[$slot] = 1;
				}
			}
			return [$result, $used, fn() => new \pocketmine\inventory\transaction\GrindstoneTransaction($player, $container, $result, $xp), 0];
		}
		if($container instanceof TradeInventory){
			$match = self::tradeMatch($player, $container);
			if($match === null){
				return null;
			}
			[$offer, $slotA, $slotB] = $match;
			$used = [$slotA => $offer->getBuyAFor($player)->getCount()];
			if(($buyB = $offer->getBuyB()) !== null){
				$used[$slotB] = $buyB->getCount();
			}
			return [$offer->getSell(), $used, fn() => new \pocketmine\inventory\transaction\TradingTransaction($player, $container, $offer, 1), 0];
		}
		if($container instanceof CartographyTableInventory){
			$calculated = \pocketmine\inventory\transaction\CartographyTransaction::calculate($container->getItem(0), $container->getItem(1), null);
			if($calculated === null){
				return null;
			}
			[$result, $used] = $calculated;
			return [$result, [0 => 1, 1 => $used], fn() => new \pocketmine\inventory\transaction\CartographyTransaction($player, $container, $result, $used), 0];
		}
		return null;
	}

	/** Clicking the result: takes it (shift-click: into the inventory) and uses up the inputs, like vanilla. */
	private function stationResult(bool $intoInventory) : void{
		$container = $this->container();
		if($container instanceof TradeInventory){
			$this->tradeResult($container, $intoInventory);
			return;
		}
		if($container === null || ($calculation = self::stationCalculation($this->player, $container)) === null){
			return;
		}
		[$result, $used, $factory, $cost] = $calculation;
		if($cost > 0 && !$this->player->isCreative() && $this->player->getXpManager()->getXpLevel() < $cost){
			return; // too expensive
		}
		if($intoInventory){
			$targets = [];
			$inventory = $this->player->getInventory();
			for($i = 8; $i >= 0; --$i){
				$targets[] = [$inventory, $i];
			}
			for($i = 35; $i >= 9; --$i){
				$targets[] = [$inventory, $i];
			}
			$output = clone $result;
			$this->insert($output, $targets);
			if(!$output->isNull()){
				$this->changes = [];
				return; // no room
			}
		}else{
			if(!$this->cursor()->isNull()){
				return;
			}
			$this->setCursor(clone $result);
		}
		foreach($used as $slot => $count){
			if($count > 0){
				$item = $this->get($container, $slot);
				$item->pop(min($count, $item->getCount()));
				$this->set($container, $slot, $item);
			}
		}
		$this->transactionFactory = $factory;
	}

	/**
	 * Result slot and screen data of a station for the Java screen: the result as the "created output" UI slot,
	 * the level cost of the anvil, the costs of the three enchanting options.
	 */
	public static function sendStationState(Player $player, Inventory $container, int $windowId) : void{
		$session = $player->getNetworkSession();
		$data = null;
		if($container instanceof \pocketmine\block\inventory\LoomInventory){
			$type = self::loomPattern($player, $container);
			$session->sendDataPacket(InventorySlotPacket::create(
				ContainerIds::UI,
				self::CREATED_OUTPUT_SLOT,
				null,
				null,
				new ItemStackWrapper(0, $session->getTypeConverter()->coreItemStackToNet(($type !== null ? \pocketmine\inventory\transaction\LoomTransaction::calculate($container, $type) : null) ?? VanillaItems::AIR()))
			));
		}elseif($container instanceof \pocketmine\block\inventory\StonecutterInventory){
			$recipe = self::stonecutRecipe($player, $container);
			$session->sendDataPacket(InventorySlotPacket::create(
				ContainerIds::UI,
				self::CREATED_OUTPUT_SLOT,
				null,
				null,
				new ItemStackWrapper(0, $session->getTypeConverter()->coreItemStackToNet($recipe?->getResults()[0] ?? VanillaItems::AIR()))
			));
		}elseif(self::hasResultSlot($container)){
			$calculation = self::stationCalculation($player, $container);
			$session->sendDataPacket(InventorySlotPacket::create(
				ContainerIds::UI,
				self::CREATED_OUTPUT_SLOT,
				null,
				null,
				new ItemStackWrapper(0, $session->getTypeConverter()->coreItemStackToNet($calculation[0] ?? VanillaItems::AIR()))
			));
			if($container instanceof AnvilInventory){
				$data = "0=" . ($calculation[3] ?? 0);
			}
		}elseif($container instanceof \pocketmine\block\inventory\CrafterInventory){
			//Java crafter: 0-8 slot states (1 = disabled), 9 triggered
			$values = [];
			for($i = 0; $i < 9; ++$i){
				$values[] = $i . "=" . ($container->isSlotDisabled($i) ? 1 : 0);
			}
			$data = implode(",", $values);
		}elseif($container instanceof \pocketmine\block\inventory\BeaconInventory){
			$beacon = $container->getHolder()->getWorld()->getBlock($container->getHolder());
			if(!$beacon instanceof \pocketmine\block\Beacon){
				return;
			}
			//Java: 0 levels, 1 and 2 the powers (Java effect id = Bedrock id - 1, -1 = none)
			[$levels, $primary, $secondary] = $beacon->getScreenData();
			$data = "0=" . $levels . ",1=" . ($primary > 0 ? $primary - 1 : -1) . ",2=" . ($secondary > 0 ? $secondary - 1 : -1);
		}elseif($container instanceof EnchantInventory){
			$values = [];
			for($i = 0; $i < 3; ++$i){
				$option = $container->getInput()->isNull() ? null : $container->getOption($i);
				$values[] = $i . "=" . ($option?->getRequiredXpLevel() ?? 0);
			}
			$values[] = "3=" . ($player->getEnchantmentSeed() & 0x7fff);
			for($i = 4; $i < 10; ++$i){
				$values[] = $i . "=-1"; // no hint which enchantment the option gives
			}
			$data = implode(",", $values);
		}else{
			return;
		}
		if($data !== null){
			$session->sendDataPacket(ScriptMessagePacket::create("betterpmmp:container_data", $windowId . ";" . $data));
		}
		$session->sendDataPacket(ScriptMessagePacket::create("betterpmmp:craft_result", (string) $windowId));
	}

	/** The name field of the Java anvil screen ("betterpmmp:java_rename"). */
	public static function handleRename(Player $player, InventoryManager $manager, string $name) : void{
		$anvil = $player->getCurrentWindow();
		if(!$anvil instanceof AnvilInventory){
			return;
		}
		self::$renames[spl_object_id($player)] = [spl_object_id($anvil), mb_substr($name, 0, 50)];
		$windowId = $manager->getWindowId($anvil);
		if($windowId !== null){
			self::sendStationState($player, $anvil, $windowId);
		}
	}

	/** A button of a Java screen ("window;button"): the three options of the enchanting table. */
	public static function handleButton(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 2){
			return;
		}
		[$window, $button] = [(int) $parts[0], (int) $parts[1]];
		$click = new self($player, $manager, $window);
		$table = $click->container();
		if($table instanceof \pocketmine\block\inventory\LoomInventory){
			//Java loom: the n-th pattern of the list (patterns without item, or the one of the pattern item)
			self::$loomPicks[spl_object_id($player)] = [spl_object_id($table), $button];
			self::sendStationState($player, $table, $window);
			return;
		}
		if(!$table instanceof EnchantInventory || $button < 0 || $button > 2){
			return;
		}
		$option = $table->getOption($button);
		$input = $table->getInput();
		if($option === null || $input->isNull()){
			return;
		}
		$cost = $button + 1;
		$lapis = $table->getLapis();
		if(!$player->isCreative()){
			if($lapis->getCount() < $cost || $player->getXpManager()->getXpLevel() < $option->getRequiredXpLevel()){
				return;
			}
			$click->set($table, EnchantInventory::SLOT_LAPIS, (clone $lapis)->setCount($lapis->getCount() - $cost));
		}
		$click->set($table, EnchantInventory::SLOT_INPUT, \pocketmine\item\enchantment\EnchantingHelper::enchantItem($input, $option->getEnchantments()));
		$click->transactionFactory = fn() => new \pocketmine\inventory\transaction\EnchantingTransaction($player, $option, $cost);
		$click->commit();
	}

	// ------------------------------------------------------------- villager trading

	/** @var array<int, array{int, int}> player id => [trade window id, net id of the offer picked in the Java list] */
	private static array $selectedTrades = [];

	/**
	 * The offer the payment slots pay for: the one picked in the list first, then any other (vanilla also
	 * accepts the two prices in swapped slots).
	 * @return array{\pocketmine\entity\trade\TradeOffer, int, int}|null offer, slot of price 1, slot of price 2
	 */
	private static function tradeMatch(Player $player, TradeInventory $trade) : ?array{
		$offers = [];
		$selected = self::$selectedTrades[spl_object_id($player)] ?? null;
		if($selected !== null && $selected[0] === spl_object_id($trade) && ($picked = $trade->getOfferByNetId($selected[1])) !== null){
			$offers[] = $picked;
		}
		foreach($trade->getVillager()->getOffers() as $offer){
			$offers[] = $offer;
		}
		$items = [$trade->getItem(TradeInventory::SLOT_INGREDIENT_1), $trade->getItem(TradeInventory::SLOT_INGREDIENT_2)];
		foreach($offers as $offer){
			if($offer->isDisabled()){
				continue;
			}
			$buyA = $offer->getBuyAFor($player);
			$buyB = $offer->getBuyB();
			foreach([[0, 1], [1, 0]] as [$slotA, $slotB]){
				$a = $items[$slotA];
				$b = $items[$slotB];
				if($a->isNull() || !$a->canStackWith($buyA) || $a->getCount() < $buyA->getCount()){
					continue;
				}
				if($buyB !== null && ($b->isNull() || !$b->canStackWith($buyB) || $b->getCount() < $buyB->getCount())){
					continue;
				}
				return [$offer, $slotA, $slotB];
			}
		}
		return null;
	}

	/** @return list<array{Inventory, int}> hotbar first, like vanilla puts results into the inventory */
	private function playerSlots() : array{
		$inventory = $this->player->getInventory();
		$targets = [];
		for($i = 8; $i >= 0; --$i){
			$targets[] = [$inventory, $i];
		}
		for($i = 35; $i >= 9; --$i){
			$targets[] = [$inventory, $i];
		}
		return $targets;
	}

	/** Takes the trade result (shift-click: trades as often as the payment and the inventory allow). */
	private function tradeResult(TradeInventory $trade, bool $intoInventory) : void{
		$match = self::tradeMatch($this->player, $trade);
		if($match === null){
			return;
		}
		[$offer, $slotA, $slotB] = $match;
		$buyA = $offer->getBuyAFor($this->player);
		$buyB = $offer->getBuyB();
		$sell = $offer->getSell();
		$times = 1;
		if($intoInventory){
			$times = intdiv($trade->getItem($slotA)->getCount(), max(1, $buyA->getCount()));
			if($buyB !== null){
				$times = min($times, intdiv($trade->getItem($slotB)->getCount(), max(1, $buyB->getCount())));
			}
			$times = max(1, min($times, $offer->getMaxUses() - $offer->getUses()));
			for(; $times > 0; --$times){
				$this->changes = [];
				$output = (clone $sell)->setCount($sell->getCount() * $times);
				$this->insert($output, $this->playerSlots());
				if($output->isNull()){
					break;
				}
			}
			if($times === 0){
				$this->changes = [];
				return; // no room
			}
		}else{
			$cursor = $this->cursor();
			if($cursor->isNull()){
				$this->setCursor(clone $sell);
			}elseif($cursor->canStackWith($sell) && $cursor->getCount() + $sell->getCount() <= self::maxStack($sell, $this->player->getCursorInventory())){
				$this->setCursor($cursor->setCount($cursor->getCount() + $sell->getCount()));
			}else{
				return;
			}
		}
		$pay = [$slotA => $buyA->getCount() * $times];
		if($buyB !== null){
			$pay[$slotB] = $buyB->getCount() * $times;
		}
		foreach($pay as $slot => $count){
			$item = $this->get($trade, $slot);
			$item->pop(min($count, $item->getCount()));
			$this->set($trade, $slot, $item);
		}
		$this->transactionFactory = fn() => new \pocketmine\inventory\transaction\TradingTransaction($this->player, $trade, $offer, $times);
	}

	/**
	 * An offer picked in the Java list ("window;netId"): like a vanilla Java server, the payment goes back into
	 * the inventory and the prices of the picked offer are taken from the inventory into the payment slots.
	 */
	public static function handleTradeSelect(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 2){
			return;
		}
		$click = new self($player, $manager, (int) $parts[0]);
		$trade = $click->container();
		if(!$trade instanceof TradeInventory || ($offer = $trade->getOfferByNetId((int) $parts[1])) === null){
			return;
		}
		self::$selectedTrades[spl_object_id($player)] = [spl_object_id($trade), (int) $parts[1]];
		$targets = $click->playerSlots();
		$free = true;
		foreach([TradeInventory::SLOT_INGREDIENT_1, TradeInventory::SLOT_INGREDIENT_2] as $slot){
			$item = $click->get($trade, $slot);
			if(!$item->isNull()){
				$click->insert($item, $targets);
				$click->set($trade, $slot, $item);
				$free = $free && $item->isNull();
			}
		}
		if($free){
			$inventory = $player->getInventory();
			foreach([TradeInventory::SLOT_INGREDIENT_1 => $offer->getBuyAFor($player), TradeInventory::SLOT_INGREDIENT_2 => $offer->getBuyB()] as $slot => $cost){
				if($cost === null){
					continue;
				}
				$filled = $click->get($trade, $slot);
				$max = self::maxStack($cost, $trade);
				for($i = 0; $i < 36 && $filled->getCount() < $max; ++$i){
					$item = $click->get($inventory, $i);
					if($item->isNull() || !$item->canStackWith($cost)){
						continue;
					}
					$amount = min($item->getCount(), $max - $filled->getCount());
					$filled = $filled->isNull() ? (clone $item)->setCount($amount) : $filled->setCount($filled->getCount() + $amount);
					$item->pop($amount);
					$click->set($inventory, $i, $item);
				}
				$click->set($trade, $slot, $filled);
			}
		}
		$click->commit();
	}

	// ------------------------------------------------------------- stonecutter

	private const STONECUTTER_RESULT = 1;

	/** @var array<int, array{int, int}> player id => [stonecutter inventory id, recipe index] picked in the Java screen */
	private static array $stonecuts = [];

	/** The recipe picked in the Java stonecutter, if it still fits the input. */
	private static function stonecutRecipe(Player $player, Inventory $stonecutter) : ?\pocketmine\crafting\ShapelessRecipe{
		$picked = self::$stonecuts[spl_object_id($player)] ?? null;
		if($picked === null || $picked[0] !== spl_object_id($stonecutter)){
			return null;
		}
		$recipe = $player->getServer()->getCraftingManager()->getCraftingRecipeFromIndex($picked[1]);
		$input = $stonecutter->getItem(0);
		if(!$recipe instanceof \pocketmine\crafting\ShapelessRecipe || $recipe->getType() !== \pocketmine\crafting\ShapelessRecipeType::STONECUTTER || $input->isNull()){
			return null;
		}
		$ingredient = $recipe->getIngredientList()[0] ?? null;
		return $ingredient !== null && $ingredient->accepts($input) ? $recipe : null;
	}

	/** A recipe picked in the Java stonecutter list ("window;recipe index"). */
	public static function handleStonecut(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 2){
			return;
		}
		$click = new self($player, $manager, (int) $parts[0]);
		$stonecutter = $click->container();
		if(!$stonecutter instanceof \pocketmine\block\inventory\StonecutterInventory){
			return;
		}
		self::$stonecuts[spl_object_id($player)] = [spl_object_id($stonecutter), (int) $parts[1]];
		self::sendStationState($player, $stonecutter, (int) $parts[0]);
	}

	/** Taking the stonecutter result (shift-click: as many as the input and the inventory allow). */
	private function stonecutResult(bool $intoInventory) : void{
		$stonecutter = $this->container();
		if($stonecutter === null || ($recipe = self::stonecutRecipe($this->player, $stonecutter)) === null){
			return;
		}
		$result = $recipe->getResults()[0];
		$input = $this->get($stonecutter, 0);
		$times = 1;
		if($intoInventory){
			for($times = $input->getCount(); $times > 0; --$times){
				$this->changes = [];
				$output = (clone $result)->setCount($result->getCount() * $times);
				$this->insert($output, $this->playerSlots());
				if($output->isNull()){
					break;
				}
			}
			if($times === 0){
				$this->changes = [];
				return;
			}
		}else{
			$cursor = $this->cursor();
			if($cursor->isNull()){
				$this->setCursor(clone $result);
			}elseif($cursor->canStackWith($result) && $cursor->getCount() + $result->getCount() <= self::maxStack($result, $this->player->getCursorInventory())){
				$this->setCursor($cursor->setCount($cursor->getCount() + $result->getCount()));
			}else{
				return;
			}
		}
		$input = $this->get($stonecutter, 0);
		$input->pop($times);
		$this->set($stonecutter, 0, $input);
		$this->craftRecipe = $recipe;
		$this->craftRepetitions = $times;
	}

	/**
	 * The stonecutter recipes for the Java bridge, one per line: "recipe index|input name|input meta|result name|result meta|count".
	 * Java shows the recipe list of the stonecutter from this.
	 */
	public static function stonecutterRecipeList(\pocketmine\Server $server) : string{
		static $cached = null;
		if($cached !== null){
			return $cached;
		}
		$serializer = \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer();
		$lines = [];
		foreach($server->getCraftingManager()->getCraftingRecipeIndex() as $index => $recipe){
			if(!$recipe instanceof \pocketmine\crafting\ShapelessRecipe || $recipe->getType() !== \pocketmine\crafting\ShapelessRecipeType::STONECUTTER){
				continue;
			}
			$ingredient = $recipe->getIngredientList()[0] ?? null;
			$result = $recipe->getResults()[0] ?? null;
			if($result === null){
				continue;
			}
			try{
				if($ingredient instanceof \pocketmine\crafting\ExactRecipeIngredient){
					$in = $serializer->serializeType($ingredient->getItem());
					[$inName, $inMeta] = [$in->getName(), $in->getMeta()];
				}elseif($ingredient instanceof \pocketmine\crafting\MetaWildcardRecipeIngredient){
					[$inName, $inMeta] = [$ingredient->getItemId(), 0];
				}else{
					continue;
				}
				$out = $serializer->serializeType($result);
			}catch(\Throwable){
				continue;
			}
			$lines[] = $index . "|" . $inName . "|" . $inMeta . "|" . $out->getName() . "|" . $out->getMeta() . "|" . $result->getCount();
		}
		return $cached = implode("\n", $lines);
	}

	/** The "Done" button of the Java beacon screen ("window;primary;secondary", Bedrock effect ids, 0 = none). */
	public static function handleBeacon(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 3){
			return;
		}
		$click = new self($player, $manager, (int) $parts[0]);
		$beacon = $click->container();
		if(!$beacon instanceof \pocketmine\block\inventory\BeaconInventory){
			return;
		}
		$payment = $beacon->getItem(0);
		if(!\pocketmine\block\Beacon::isPayment($payment)){
			return;
		}
		$click->set($beacon, 0, (clone $payment)->setCount($payment->getCount() - 1));
		$click->transactionFactory = fn() => new \pocketmine\inventory\transaction\BeaconTransaction($player, $beacon, (int) $parts[1], (int) $parts[2]);
		$click->commit();
	}

	/** A slot of the Java crafter screen switched on or off ("window;slot;enabled"). */
	public static function handleCrafterSlot(Player $player, InventoryManager $manager, string $value) : void{
		$parts = explode(";", $value);
		if(count($parts) !== 3){
			return;
		}
		$click = new self($player, $manager, (int) $parts[0]);
		$crafter = $click->container();
		if(!$crafter instanceof \pocketmine\block\inventory\CrafterInventory){
			return;
		}
		$tile = $crafter->getHolder()->getWorld()->getTile($crafter->getHolder());
		if($tile instanceof \pocketmine\block\tile\Crafter){
			$tile->toggleSlot((int) $parts[1], $parts[2] !== "1");
		}
		self::sendStationState($player, $crafter, (int) $parts[0]);
	}

	// -------------------------------------------------------------------- loom

	private const LOOM_RESULT = 3;

	/** @var array<int, array{int, int}> player id => [loom inventory id, button picked in the Java pattern list] */
	private static array $loomPicks = [];

	/** The pattern picked in the Java loom for the items in it now. */
	private static function loomPattern(Player $player, Inventory $loom) : ?\pocketmine\block\utils\BannerPatternType{
		$pick = self::$loomPicks[spl_object_id($player)] ?? null;
		if($pick === null || $pick[0] !== spl_object_id($loom)){
			return null;
		}
		$fromItem = \pocketmine\inventory\transaction\LoomTransaction::patternOfItem($loom->getItem(\pocketmine\block\inventory\LoomInventory::SLOT_PATTERN));
		if($fromItem !== null){
			return $pick[1] === 0 ? $fromItem : null;
		}
		$name = \pocketmine\inventory\transaction\LoomTransaction::JAVA_FREE_PATTERNS[$pick[1]] ?? null;
		return $name === null ? null : constant(\pocketmine\block\utils\BannerPatternType::class . "::" . $name);
	}

	/** Taking the woven banner. */
	private function loomResult(bool $intoInventory) : void{
		$loom = $this->container();
		if(!$loom instanceof \pocketmine\block\inventory\LoomInventory || ($type = self::loomPattern($this->player, $loom)) === null || ($result = \pocketmine\inventory\transaction\LoomTransaction::calculate($loom, $type)) === null){
			return;
		}
		if($intoInventory){
			$output = clone $result;
			$this->insert($output, $this->playerSlots());
			if(!$output->isNull()){
				$this->changes = [];
				return;
			}
		}elseif($this->cursor()->isNull()){
			$this->setCursor(clone $result);
		}else{
			return;
		}
		foreach([\pocketmine\block\inventory\LoomInventory::SLOT_BANNER, \pocketmine\block\inventory\LoomInventory::SLOT_DYE] as $slot){
			$item = $this->get($loom, $slot);
			$item->pop();
			$this->set($loom, $slot, $item);
		}
		$player = $this->player;
		$this->transactionFactory = fn() => new \pocketmine\inventory\transaction\LoomTransaction($player, $result);
	}
}
