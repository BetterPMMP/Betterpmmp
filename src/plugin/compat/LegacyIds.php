<?php

/*
 * BetterPMMP - numeric item and block IDs for plugins written for API 3 and 4.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\plugin\compat;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function ksort;
use function file_get_contents;
use function is_array;
use function json_decode;

/**
 * Old plugins create items and blocks from numeric IDs (Item::get(276), BlockFactory::get(35, 14)) and
 * compare them with getId(). PocketMine-MP 5 has no numeric IDs any more, but its world converter still knows
 * how they map to today's items and blocks. This class uses that map in both directions.
 */
final class LegacyIds{

	/** @var array<string, int>|null item name => legacy id */
	private static ?array $itemIds = null;
	/** @var array<string, int>|null block name => legacy id */
	private static ?array $blockIds = null;

	public static function item(int $id, int $meta = 0, int $count = 1) : Item{
		try{
			$data = GlobalItemDataHandlers::getUpgrader()->upgradeItemTypeDataInt($id, $meta, $count, null);
			return GlobalItemDataHandlers::getDeserializer()->deserializeStack($data);
		}catch(\Throwable){
			return VanillaItems::AIR();
		}
	}

	/** @var array<int, int> legacy id << 4 | meta => state id */
	private static array $stateIds = [];

	/** State id for a numeric id and meta, cached (generators set millions of blocks). */
	public static function stateId(int $id, int $meta = 0) : int{
		return self::$stateIds[($id << 4) | ($meta & 0xf)] ??= self::block($id, $meta)->getStateId();
	}

	public static function block(int $id, int $meta = 0) : Block{
		try{
			$state = GlobalBlockStateHandlers::getUpgrader()->upgradeIntIdMeta($id, $meta);
			return RuntimeBlockStateRegistry::getInstance()->fromStateId(GlobalBlockStateHandlers::getDeserializer()->deserialize($state));
		}catch(\Throwable){
			return VanillaBlocks::AIR();
		}
	}

	public static function itemId(Item $item) : int{
		return self::itemIdMeta($item)[0];
	}

	public static function itemMeta(Item $item) : int{
		if($item instanceof Durable){
			return $item->getDamage();
		}
		return self::itemIdMeta($item)[1];
	}

	/** @return array{int, int} legacy id and meta of an item, [0, 0] when there is none */
	private static function itemIdMeta(Item $item) : array{
		if($item->isNull()){
			return [0, 0];
		}
		try{
			$data = GlobalItemDataHandlers::getSerializer()->serializeType($item);
		}catch(\Throwable){
			return [0, 0];
		}
		$name = $data->getName();
		$meta = $item instanceof Durable ? 0 : $data->getMeta();
		$block = $data->getBlock();
		if($block !== null){
			// block items (wool, logs, stone ...): the legacy item id is the block id
			[$id, $blockMeta] = self::blockIdMetaFromState($block);
			if($id !== 0){
				return [$id > 255 ? 255 - $id : $id, $blockMeta];
			}
		}
		$items = self::itemMap();
		if(isset($items[$name]) && $meta === 0){
			return [$items[$name], 0];
		}
		$reverse = self::itemReverse();
		return $reverse[$name . ":" . $meta] ?? (isset($items[$name]) ? [$items[$name], $meta] : [0, 0]);
	}

	public static function blockId(Block $block) : int{
		return self::blockIdMeta($block)[0];
	}

	public static function blockMeta(Block $block) : int{
		return self::blockIdMeta($block)[1];
	}

	/** @return array{int, int} */
	private static function blockIdMeta(Block $block) : array{
		try{
			return self::blockIdMetaFromState(GlobalBlockStateHandlers::getSerializer()->serializeBlock($block));
		}catch(\Throwable){
			return [0, 0];
		}
	}

	/** @return array{int, int} */
	private static function blockIdMetaFromState(\pocketmine\data\bedrock\block\BlockStateData $state) : array{
		$reverse = self::blockReverse();
		$key = self::stateKey($state);
		if(isset($reverse[$key])){
			return $reverse[$key];
		}
		$blocks = self::blockMap();
		return isset($blocks[$state->getName()]) ? [$blocks[$state->getName()], 0] : [0, 0];
	}

	private static function stateKey(\pocketmine\data\bedrock\block\BlockStateData $state) : string{
		$states = $state->getStates();
		ksort($states);
		$key = $state->getName();
		foreach($states as $name => $tag){
			$key .= ";" . $name . "=" . $tag->getValue();
		}
		return $key;
	}

	/** Builds the lookup tables now (when an old plugin is loaded) instead of on its first getId() in game. */
	public static function warmUp() : void{
		try{
			self::itemReverse();
			self::blockReverse();
		}catch(\Throwable){
		}
	}

	/** @var array<string, array{int, int}>|null modern name:meta => legacy id and meta */
	private static ?array $itemReverse = null;
	/** @var array<string, array{int, int}>|null block state => legacy id and meta */
	private static ?array $blockReverse = null;

	/**
	 * Built once when needed: every legacy item id with meta 0-15 run through the world converter, so items
	 * that were renamed (dye:4 -> lapis_lazuli) find their old id again.
	 *
	 * @return array<string, array{int, int}>
	 */
	private static function itemReverse() : array{
		if(self::$itemReverse !== null){
			return self::$itemReverse;
		}
		$reverse = [];
		$upgrader = GlobalItemDataHandlers::getUpgrader();
		foreach(self::itemMap() as $id){
			for($meta = 0; $meta < 16; ++$meta){
				try{
					$data = $upgrader->upgradeItemTypeDataInt($id, $meta, 1, null)->getTypeData();
				}catch(\Throwable){
					continue;
				}
				$reverse[$data->getName() . ":" . $data->getMeta()] ??= [$id, $meta];
			}
		}
		return self::$itemReverse = $reverse;
	}

	/** @return array<string, array{int, int}> */
	private static function blockReverse() : array{
		if(self::$blockReverse !== null){
			return self::$blockReverse;
		}
		$reverse = [];
		$upgrader = GlobalBlockStateHandlers::getUpgrader();
		foreach(self::blockMap() as $id){
			for($meta = 0; $meta < 16; ++$meta){
				try{
					$state = $upgrader->upgradeIntIdMeta($id, $meta);
				}catch(\Throwable){
					continue;
				}
				$reverse[self::stateKey($state)] ??= [$id, $meta];
			}
		}
		return self::$blockReverse = $reverse;
	}

	/** @return array<string, int> */
	public static function itemMap() : array{
		return self::$itemIds ??= self::load(Path::join(\pocketmine\BEDROCK_ITEM_UPGRADE_SCHEMA_PATH, "item_legacy_id_map.json"));
	}

	/** @return array<string, int> */
	public static function blockMap() : array{
		return self::$blockIds ??= self::load(Path::join(\pocketmine\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH, "block_legacy_id_map.json"));
	}

	/** @return array<string, int> */
	private static function load(string $file) : array{
		$data = json_decode((string) @file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}
}
