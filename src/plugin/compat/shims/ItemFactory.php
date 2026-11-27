<?php

/*
 * BetterPMMP - pocketmine\item\ItemFactory of API 3 and 4 for old plugins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\plugin\compat\LegacyIds;

/**
 * API 3: ItemFactory::get($id, $meta, $count), API 4: ItemFactory::getInstance()->get($id, $meta, $count).
 * Both work; the item is looked up with the world converter's legacy ID map.
 *
 * @method static Item get(int $id, int $meta = 0, int $count = 1, mixed $tags = null)
 */
final class ItemFactory{

	private static ?self $instance = null;

	public static function getInstance() : self{
		return self::$instance ??= new self();
	}

	/** @param mixed[] $args */
	public function __call(string $name, array $args) : mixed{
		return self::call($name, $args);
	}

	/** @param mixed[] $args */
	public static function __callStatic(string $name, array $args) : mixed{
		return self::call($name, $args);
	}

	/** @param mixed[] $args */
	private static function call(string $name, array $args) : mixed{
		return match($name){
			"get" => LegacyIds::item((int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 1)),
			"isRegistered" => !LegacyIds::item((int) ($args[0] ?? 0), (int) ($args[1] ?? 0))->isNull(),
			"air" => VanillaItems::AIR(),
			"fromString", "fromStringSingle" => StringToItemParser::getInstance()->parse((string) ($args[0] ?? "")) ?? LegacyStringToItemParser::getInstance()->parse((string) ($args[0] ?? "")),
			default => throw new \BadMethodCallException("ItemFactory::$name() is not available on BetterPMMP"),
		};
	}
}
