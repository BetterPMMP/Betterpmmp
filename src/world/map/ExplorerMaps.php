<?php

/*
 * BetterPMMP - explorer and treasure maps.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\map;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use pocketmine\world\generator\vanilla\Overworld;
use pocketmine\world\World;
use function in_array;
use function cos;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Vanilla explorer maps: buried treasure maps from shipwrecks and ocean
 * ruins, ocean / woodland / trial explorer maps from cartographers. They are
 * handed out unfilled (the world generator cannot write maps) and get their
 * target the first time a player holds them: the nearest ocean monument,
 * woodland mansion or trial chambers, or a beach spot where the buried
 * treasure chest is dug in once a player comes close.
 */
final class ExplorerMaps{

	public const TAG_KIND = "BetterPMMPExplorer";

	/** kind => [display name, map scale, decoration icon] */
	private const KINDS = [
		"buried_treasure" => ["Buried Treasure Map", 1, 4],
		"monument" => ["Ocean Explorer Map", 2, 15],
		"mansion" => ["Woodland Explorer Map", 2, 14],
		"trial_chambers" => ["Trial Explorer Map", 2, 4],
	];

	/** @var array<string, Overworld> world folder => generator used to locate structures */
	private static array $generators = [];

	/** An unfilled explorer map item (used by loot tables and trades). */
	public static function placeholder(string $kind) : ?Item{
		$item = ExtraVanillaItems::get("minecraft:filled_map");
		if($item === null || !isset(self::KINDS[$kind])){
			return null;
		}
		$tag = $item->getNamedTag();
		$tag->setString(self::TAG_KIND, $kind);
		$item->setNamedTag($tag);
		$item->setCustomName("§r" . self::KINDS[$kind][0]);
		return $item;
	}

	public static function isUnresolved(Item $item) : bool{
		return $item->getNamedTag()->getTag(self::TAG_KIND) !== null && $item->getNamedTag()->getTag(MapManager::TAG_UUID) === null;
	}

	private static function generator(World $world) : ?Overworld{
		if(isset(self::$generators[$world->getFolderName()])){
			return self::$generators[$world->getFolderName()];
		}
		if(\pocketmine\world\generator\vanilla\structure\WorldStructures::generatorClass($world) !== Overworld::class){
			return null;
		}
		return self::$generators[$world->getFolderName()] = new Overworld($world->getSeed(), $world->getProvider()->getWorldData()->getGeneratorOptions());
	}

	/** Gives an unfilled explorer map its target; returns the filled map or null. */
	public static function resolve(Player $player, Item $item) : ?Item{
		$kind = $item->getNamedTag()->getString(self::TAG_KIND, "");
		if(!isset(self::KINDS[$kind])){
			return null;
		}
		$world = $player->getWorld();
		$generator = self::generator($world);
		if($generator === null){
			return null;
		}
		$pos = $player->getPosition();
		$target = match($kind){
			"buried_treasure" => self::findBeach($generator, $pos->getFloorX(), $pos->getFloorZ()),
			"monument" => $generator->getStructureManager()->locate("ocean_monument", $pos->getFloorX(), $pos->getFloorZ(), 100),
			"mansion" => $generator->getStructureManager()->locate("woodland_mansion", $pos->getFloorX(), $pos->getFloorZ(), 300),
			"trial_chambers" => $generator->getStructureManager()->locate("trial_chambers", $pos->getFloorX(), $pos->getFloorZ(), 100),
			default => null
		};
		if($target === null){
			return null;
		}
		[, $scale, $icon] = self::KINDS[$kind];
		$map = MapManager::createTargetMap($world, $target[0], $target[1], $scale, $icon, $kind === "buried_treasure");
		$map?->setCustomName($item->getCustomName());
		return $map;
	}

	/** Nearest beach column, searched in rings (vanilla: buried treasure is on beaches). @return int[]|null */
	private static function findBeach(Overworld $generator, int $x, int $z) : ?array{
		for($radius = 64; $radius <= 1600; $radius += 64){
			for($i = 0; $i < 32; ++$i){
				$angle = $i / 32 * 2 * M_PI;
				$bx = (int) ($x + cos($angle) * $radius);
				$bz = (int) ($z + sin($angle) * $radius);
				if(in_array($generator->biomeAt($bx, $bz), [B::BEACH, B::COLD_BEACH], true)){
					return [$bx, $bz];
				}
			}
		}
		return null;
	}

	/** Digs the buried treasure chest in at the target once a player is close and the chunk is loaded. */
	public static function placeTreasure(World $world, int $x, int $z) : bool{
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return false;
		}
		$top = $world->getHighestBlockAt($x, $z);
		if($top === null){
			return false;
		}
		$y = $top - 2;
		$world->setBlockAt($x, $y, $z, VanillaBlocks::CHEST());
		$tile = $world->getTileAt($x, $y, $z);
		if($tile instanceof \pocketmine\block\tile\Chest){
			$random = new Random(mt_rand());
			foreach(LootTables::roll("buried_treasure", $random) as $i => $loot){
				$tile->getInventory()->setItem(mt_rand(0, 26), $loot);
			}
		}
		return true;
	}
}
