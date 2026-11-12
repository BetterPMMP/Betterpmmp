<?php

/*
 * BetterPMMP - inventory clicks of Java Edition players (Java bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use function count;
use function explode;
use function intdiv;
use function max;
use function min;
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
		$size = $container->getSize();
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
		$count = $this->windowId === 0 ? 46 : (($this->container()?->getSize() ?? 0) + 36);
		$slots = [];
		for($i = 0; $i < $count; ++$i){
			$slots[] = $i;
		}
		return $slots;
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
		$targets = $this->quickMoveTargets($javaSlot);
		$this->insert($item, $targets);
		$this->set($inventory, $slot, $item);
	}

	/** @return list<array{Inventory, int}> */
	private function quickMoveTargets(int $javaSlot) : array{
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
		if($javaSlot < $container->getSize()){
			return $playerSlots;
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
				(new InventoryTransaction($this->player, $actions))->execute();
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
		foreach($inventories as $inventory){
			if($this->manager->getWindowId($inventory) !== null){
				$this->manager->syncContents($inventory);
			}
		}
	}
}
