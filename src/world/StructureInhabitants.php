<?php

/*
 * BetterPMMP - mobs that belong to a structure from the start (shulkers, elder guardians, witches).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\entity\ElderGuardianMob;
use pocketmine\entity\Location;
use pocketmine\entity\ShulkerMob;
use pocketmine\entity\WitchMob;
use pocketmine\world\generator\vanilla\structure\WorldStructures;
use Symfony\Component\Filesystem\Path;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function mt_rand;
use function spl_object_id;
use function str_starts_with;

/**
 * Structures are generated in worker threads, which cannot create entities. A structure that should be
 * inhabited marks the spots ("mob:shulker:3" => [x, y, z]); once a player comes near and all those chunks
 * are loaded, the mobs are created like vanilla does it during generation. Structures that already got
 * their mobs are remembered in the world folder (betterpmmp_structure_mobs.json), so nothing doubles
 * after a restart.
 */
final class StructureInhabitants{
	private const FILE = "betterpmmp_structure_mobs.json";
	private const CLASSES = [
		"shulker" => ShulkerMob::class,
		"elder_guardian" => ElderGuardianMob::class,
		"witch" => WitchMob::class,
	];

	/** @var array<int, int> world id => calls of tick() */
	private static array $calls = [];
	/** @var array<int, array<string, true>> world object id => structures done */
	private static array $done = [];

	public static function tick(World $world) : void{
		//called every 10 ticks per world: look every 4th call (2 seconds)
		if((self::$calls[$world->getId()] = (self::$calls[$world->getId()] ?? 0) + 1) % 4 !== 0 || $world->getPlayers() === []){
			return;
		}
		$manager = WorldStructures::get($world);
		if($manager === null){
			return;
		}
		$done = &self::done($world);
		$changed = false;
		foreach($world->getPlayers() as $player){
			$cx = $player->getPosition()->getFloorX() >> 4;
			$cz = $player->getPosition()->getFloorZ() >> 4;
			for($dx = -4; $dx <= 4; ++$dx){
				for($dz = -4; $dz <= 4; ++$dz){
					foreach($manager->startsIn($cx + $dx, $cz + $dz) as $start){
						$key = $start->type . ":" . $start->chunkX . ":" . $start->chunkZ;
						if(isset($done[$key])){
							continue;
						}
						$spots = [];
						foreach($start->markers as $name => $position){
							if(str_starts_with($name, "mob:")){
								$spots[] = [explode(":", $name)[1], $position];
							}
						}
						if($spots === []){
							$done[$key] = true;
							continue;
						}
						foreach($spots as [, [$x, , $z]]){
							if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
								continue 2; //wait until the whole structure is there
							}
						}
						foreach($spots as [$kind, [$x, $y, $z]]){
							$class = self::CLASSES[$kind] ?? null;
							if($class !== null && self::fits($world, $kind, $x, $y, $z)){
								(new $class(Location::fromObject(new \pocketmine\math\Vector3($x + 0.5, $y, $z + 0.5), $world, mt_rand(0, 359), 0)))->spawnToAll();
							}
						}
						$done[$key] = true;
						$changed = true;
					}
				}
			}
		}
		if($changed){
			@file_put_contents(Path::join($world->getProvider()->getPath(), self::FILE), json_encode(array_keys($done)));
		}
	}

	/**
	 * Only where the structure really is (a world generated with an older version may have a different building
	 * there): shulkers need air with a block next to them, guardians water, witches air above a floor.
	 */
	private static function fits(World $world, string $kind, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$here = $world->getBlockAt($x, $y, $z);
		$below = $world->getBlockAt($x, $y - 1, $z);
		return match($kind){
			"elder_guardian" => $here instanceof \pocketmine\block\Water,
			"witch" => !$here->isSolid() && $below->isSolid(),
			default => $here->getTypeId() === \pocketmine\block\BlockTypeIds::AIR && ($below->isSolid() || $world->getBlockAt($x, $y + 1, $z)->isSolid()),
		};
	}

	/** @return array<string, true> */
	private static function &done(World $world) : array{
		$id = spl_object_id($world);
		if(!isset(self::$done[$id])){
			self::$done[$id] = [];
			$file = Path::join($world->getProvider()->getPath(), self::FILE);
			$data = file_exists($file) ? json_decode((string) file_get_contents($file), true) : null;
			foreach(is_array($data) ? $data : [] as $key){
				self::$done[$id][(string) $key] = true;
			}
		}
		return self::$done[$id];
	}

	public static function forget(World $world) : void{
		unset(self::$done[spl_object_id($world)]);
	}
}
