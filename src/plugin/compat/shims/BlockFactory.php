<?php

/*
 * BetterPMMP - pocketmine\block\BlockFactory of API 3 and 4 for old plugins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\plugin\compat\LegacyIds;

/**
 * API 3: BlockFactory::get($id, $meta), API 4: BlockFactory::getInstance()->get($id, $meta).
 *
 * @method static Block get(int $id, int $meta = 0)
 */
final class BlockFactory{

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
			"get" => LegacyIds::block((int) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
			"isRegistered" => LegacyIds::block((int) ($args[0] ?? 0), (int) ($args[1] ?? 0))->getTypeId() !== BlockTypeIds::AIR || (int) ($args[0] ?? 0) === 0,
			"fromFullBlock" => LegacyIds::block(((int) ($args[0] ?? 0)) >> 4, ((int) ($args[0] ?? 0)) & 0xf),
			default => throw new \BadMethodCallException("BlockFactory::$name() is not available on BetterPMMP"),
		};
	}
}
