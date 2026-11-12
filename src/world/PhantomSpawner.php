<?php

/*
 * BetterPMMP - phantoms for players who do not sleep.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\BetterPMMPConfig;
use pocketmine\entity\Location;
use pocketmine\entity\PhantomMob;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use function min;
use function mt_rand;

/**
 * Vanilla insomnia: a player who has not slept (or died) for three in-game
 * days gets phantoms at night - every 1-2 minutes a pack of 1-4 appears 20-34
 * blocks above them if they are above sea level under the open sky. Sleeping
 * resets the timer. The timer is kept while the server runs.
 */
final class PhantomSpawner{

	private const INSOMNIA_TICKS = 72000;

	/** @var array<string, int> player uuid => ticks since the last rest */
	private static array $sinceRest = [];
	/** @var array<int, int> world id => ticks until the next attempt */
	private static array $nextAttempt = [];

	public static function rested(Player $player) : void{
		self::$sinceRest[$player->getUniqueId()->toString()] = 0;
	}

	public static function getTicksSinceRest(Player $player) : int{
		return self::$sinceRest[$player->getUniqueId()->toString()] ?? 0;
	}

	public static function setTicksSinceRest(Player $player, int $ticks) : void{
		self::$sinceRest[$player->getUniqueId()->toString()] = $ticks;
	}

	public static function tick(World $world) : void{
		if($world->getDimensionId() !== DimensionIds::OVERWORLD || $world->getServer()->getTick() % 20 !== 0){
			return;
		}
		foreach($world->getPlayers() as $player){
			$key = $player->getUniqueId()->toString();
			self::$sinceRest[$key] = $player->isAlive() ? (self::$sinceRest[$key] ?? 0) + 20 : 0;
		}
		$id = $world->getId();
		self::$nextAttempt[$id] = (self::$nextAttempt[$id] ?? mt_rand(1200, 2400)) - 20;
		if(self::$nextAttempt[$id] > 0){
			return;
		}
		self::$nextAttempt[$id] = mt_rand(1200, 2400);
		$time = $world->getTimeOfDay();
		$night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
		if((!$night && !$world->getWeather()->isThundering()) || $world->getDifficulty() === World::DIFFICULTY_PEACEFUL
			|| !BetterPMMPConfig::mobSpawningEnabled($world) || !BetterPMMPConfig::spawns($world, "monsters")){
			return;
		}
		foreach($world->getPlayers() as $player){
			if($player->isSpectator() || $player->isCreative() || self::getTicksSinceRest($player) < self::INSOMNIA_TICKS){
				continue;
			}
			$pos = $player->getPosition();
			$top = $world->getHighestBlockAt($pos->getFloorX(), $pos->getFloorZ());
			if($pos->y < 63 || ($top !== null && $top > $pos->y + 1)){
				continue; // below sea level or under a roof
			}
			// the longer without sleep, the more likely
			if(mt_rand(0, self::getTicksSinceRest($player)) < self::INSOMNIA_TICKS){
				continue;
			}
			$base = $pos->add(mt_rand(-10, 10), mt_rand(20, 34), mt_rand(-10, 10));
			for($i = mt_rand(1, $world->getDifficulty() === World::DIFFICULTY_HARD ? 4 : 3); $i > 0; --$i){
				$at = new Vector3($base->x + mt_rand(-3, 3), min($world->getMaxY() - 2, $base->y), $base->z + mt_rand(-3, 3));
				if($world->getBlock($at)->isSolid()){
					continue;
				}
				$phantom = new PhantomMob(Location::fromObject($at, $world, mt_rand(0, 359), 0));
				$phantom->markNaturalSpawned();
				$phantom->spawnToAll();
			}
		}
	}
}
