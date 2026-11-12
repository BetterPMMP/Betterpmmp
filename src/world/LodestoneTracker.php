<?php

/*
 * BetterPMMP - lodestone compass tracking.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\GenericBedrockBlock;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\PositionTrackingDBServerBroadcastPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\Server;
use pocketmine\utils\Filesystem;
use function file_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use const JSON_PRETTY_PRINT;

/**
 * Bedrock lodestone compasses only carry a tracking id ("trackingHandle").
 * The client asks the server where that id points to with a
 * PositionTrackingDBClientRequest; this keeps the ids and answers it.
 * The ids are stored in lodestones.json in the server data folder.
 */
final class LodestoneTracker{

	public const TAG_TRACKING_HANDLE = "trackingHandle";

	/** @var array<int, array{string, int, int, int}>|null id => [world, x, y, z] */
	private static ?array $entries = null;
	private static int $nextId = 1;

	private static function file() : string{
		return Server::getInstance()->getDataPath() . "lodestones.json";
	}

	/** @return array<int, array{string, int, int, int}> */
	private static function entries() : array{
		if(self::$entries === null){
			self::$entries = [];
			if(file_exists(self::file()) && is_array($data = json_decode((string) file_get_contents(self::file()), true))){
				foreach($data as $id => $entry){
					if(is_array($entry) && isset($entry[0], $entry[1], $entry[2], $entry[3])){
						self::$entries[(int) $id] = [(string) $entry[0], (int) $entry[1], (int) $entry[2], (int) $entry[3]];
						self::$nextId = max(self::$nextId, (int) $id + 1);
					}
				}
			}
		}
		return self::$entries;
	}

	private static function save() : void{
		try{
			Filesystem::safeFilePutContents(self::file(), (string) json_encode(self::$entries ?? [], JSON_PRETTY_PRINT));
		}catch(\Throwable){
			// not fatal: the compass keeps working until a restart
		}
	}

	/** Tracking id of the lodestone at this position (created if needed). */
	public static function idFor(Position $pos) : int{
		$entry = [$pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()];
		foreach(self::entries() as $id => $existing){
			if($existing === $entry){
				return $id;
			}
		}
		$id = self::$nextId++;
		self::$entries[$id] = $entry;
		self::save();
		return $id;
	}

	public static function forget(Position $pos) : void{
		$entry = [$pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()];
		foreach(self::entries() as $id => $existing){
			if($existing === $entry){
				unset(self::$entries[$id]);
				self::save();
			}
		}
	}

	public static function createCompass(Position $pos) : ?Item{
		$compass = ExtraVanillaItems::get("minecraft:lodestone_compass");
		if($compass === null){
			return null;
		}
		$nbt = $compass->getNamedTag();
		$nbt->setInt(self::TAG_TRACKING_HANDLE, self::idFor($pos));
		return $compass->setNamedTag($nbt);
	}

	/** Answer to a client asking where a tracking id points. */
	public static function answer(int $trackingId) : PositionTrackingDBServerBroadcastPacket{
		$entry = self::entries()[$trackingId] ?? null;
		$world = null;
		$found = false;
		if($entry !== null){
			$world = Server::getInstance()->getWorldManager()->getWorldByName($entry[0]);
			if($world !== null){
				// an unloaded chunk: assume the lodestone is still there
				$found = !$world->isChunkLoaded($entry[1] >> 4, $entry[3] >> 4) ||
					(($block = $world->getBlockAt($entry[1], $entry[2], $entry[3])) instanceof GenericBedrockBlock && $block->getBedrockName() === "minecraft:lodestone");
			}
		}
		$nbt = CompoundTag::create()
			->setInt("dim", $world?->getDimensionId() ?? 0)
			->setInt("id", $trackingId)
			->setTag("pos", new ListTag([new IntTag($entry[1] ?? 0), new IntTag($entry[2] ?? 0), new IntTag($entry[3] ?? 0)]))
			->setByte("status", $found ? 0 : 2)
			->setByte("version", 1);
		return PositionTrackingDBServerBroadcastPacket::create(
			$found ? PositionTrackingDBServerBroadcastPacket::ACTION_UPDATE : PositionTrackingDBServerBroadcastPacket::ACTION_NOT_FOUND,
			$trackingId,
			new CacheableNbt($nbt)
		);
	}
}
