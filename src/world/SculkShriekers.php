<?php

/*
 * BetterPMMP - sculk shriekers and warden summoning.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\ExtraVanillaBlocks;
use pocketmine\block\GenericBedrockBlock;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\entity\WardenMob;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\Sound;
use function count;
use function max;
use function mt_rand;

/**
 * Vanilla warden summoning, simplified to what players can observe:
 *
 * - A player who moves (without sneaking) within 8 blocks of a sculk shrieker
 *   that can summon (the ones generated in the deep dark) makes it shriek, at
 *   most once every 10 seconds per player.
 * - Each shriek raises the player's warning level and gives darkness to
 *   players within 40 blocks.
 * - On the fourth warning a warden emerges near the shrieker, if no other
 *   warden is within 48 blocks. Warning levels drop by one every 10 minutes.
 */
final class SculkShriekers{

	private const COOLDOWN_TICKS = 200;
	private const WARNINGS_TO_SUMMON = 4;
	private const DECAY_TICKS = 12000;

	/** @var array<int, true>|null state ids of shriekers that can summon */
	private static ?array $summoningStates = null;
	/** @var array<int, int> state id (inactive, can summon) => state id (active) */
	private static array $activeStates = [];

	/** @var array<string, array{int, int, int}> player uuid => [warning level, cooldown until tick, last decay tick] */
	private static array $warnings = [];
	/** @var array<string, Vector3> player uuid => last checked position */
	private static array $lastPositions = [];

	private static function init() : void{
		if(self::$summoningStates !== null){
			return;
		}
		self::$summoningStates = [];
		$shrieker = ExtraVanillaBlocks::get("minecraft:sculk_shrieker");
		if(!$shrieker instanceof GenericBedrockBlock){
			return;
		}
		try{
			$inactive = $shrieker->withProperties(["can_summon" => true, "active" => false]);
			$on = $shrieker->withProperties(["can_summon" => true, "active" => true]);
			self::$summoningStates[$inactive->getStateId()] = true;
			self::$summoningStates[$on->getStateId()] = true;
			self::$activeStates[$inactive->getStateId()] = $on->getStateId();
		}catch(\InvalidArgumentException){
		}
	}

	/** @param Player[] $players */
	public static function tick(World $world, array $players) : void{
		self::init();
		if(count(self::$summoningStates ?? []) === 0){
			return;
		}
		$tick = $world->getServer()->getTick();
		foreach($players as $player){
			if($player->isCreative() || $player->isSpectator() || $player->isSneaking()){
				continue;
			}
			$id = $player->getUniqueId()->toString();
			$pos = $player->getPosition();
			$last = self::$lastPositions[$id] ?? null;
			self::$lastPositions[$id] = $pos->asVector3();
			if($last === null || $last->distanceSquared($pos) < 0.04){
				continue; // standing still makes no vibration
			}
			[$level, $cooldown, $decay] = self::$warnings[$id] ?? [0, 0, $tick];
			if($tick - $decay >= self::DECAY_TICKS){
				$level = max(0, $level - 1);
				$decay = $tick;
			}
			self::$warnings[$id] = [$level, $cooldown, $decay];
			if($tick < $cooldown){
				continue;
			}
			$shrieker = self::findShrieker($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if($shrieker === null){
				continue;
			}
			++$level;
			self::$warnings[$id] = [$level, $tick + self::COOLDOWN_TICKS, $decay];
			self::shriek($world, $shrieker);
			if($level >= self::WARNINGS_TO_SUMMON){
				if(self::summonWarden($world, $shrieker)){
					self::$warnings[$id] = [0, $tick + self::COOLDOWN_TICKS, $tick];
				}
			}
		}
	}

	/** Nearest summoning shrieker within 8 blocks, using raw chunk data (cheap). */
	private static function findShrieker(World $world, int $px, int $py, int $pz) : ?Vector3{
		$best = null;
		$bestDistance = PHP_INT_MAX;
		for($x = $px - 8; $x <= $px + 8; ++$x){
			for($z = $pz - 8; $z <= $pz + 8; ++$z){
				$chunk = $world->getChunk($x >> 4, $z >> 4);
				if($chunk === null){
					continue;
				}
				for($y = max($world->getMinY(), $py - 4); $y <= $py + 4; ++$y){
					if(isset(self::$summoningStates[$chunk->getBlockStateId($x & 15, $y, $z & 15)])){
						$d = ($x - $px) ** 2 + ($y - $py) ** 2 + ($z - $pz) ** 2;
						if($d <= 64 && $d < $bestDistance){
							$bestDistance = $d;
							$best = new Vector3($x, $y, $z);
						}
					}
				}
			}
		}
		return $best;
	}

	private static function shriek(World $world, Vector3 $pos) : void{
		$block = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		$active = self::$activeStates[$block->getStateId()] ?? null;
		if($active !== null){
			$world->setBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($active));
			$world->scheduleDelayedBlockUpdate($pos, 90);
		}
		$world->addSound($pos->add(0.5, 0.5, 0.5), new class implements Sound{
			public function encode(Vector3 $pos) : array{
				return [LevelSoundEventPacket::nonActorSound(LevelSoundEvent::BLOCK_SCULK_SHRIEKER_SHRIEK, $pos, false)];
			}
		});
		foreach($world->getPlayers() as $player){
			if($player->getPosition()->distanceSquared($pos) <= 40 * 40 && !$player->isCreative() && !$player->isSpectator()){
				$player->getEffects()->add(new EffectInstance(VanillaEffects::DARKNESS(), 260, 0, false));
			}
		}
	}

	private static function summonWarden(World $world, Vector3 $at) : bool{
		foreach($world->getEntities() as $entity){
			if($entity instanceof WardenMob && $entity->isAlive() && $entity->getPosition()->distanceSquared($at) < 48 * 48){
				return false;
			}
		}
		for($attempt = 0; $attempt < 20; ++$attempt){
			$x = $at->getFloorX() + mt_rand(-5, 5);
			$z = $at->getFloorZ() + mt_rand(-5, 5);
			for($dy = 6; $dy >= -6; --$dy){
				$y = $at->getFloorY() + $dy;
				if(!$world->getBlockAt($x, $y - 1, $z)->isSolid()){
					continue;
				}
				$free = true;
				for($h = 0; $h < 3; ++$h){
					foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ox, $oz]){
						if($world->getBlockAt($x + $ox, $y + $h, $z + $oz)->isSolid()){
							$free = false;
						}
					}
				}
				if(!$free){
					continue;
				}
				$warden = new WardenMob(new Location($x + 0.5, $y, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0));
				$warden->markNaturalSpawned();
				$warden->spawnToAll();
				return true;
			}
		}
		return false;
	}

	/** Called by the shrieker block update: back to the inactive state. */
	public static function deactivate(World $world, Vector3 $pos) : void{
		self::init();
		$block = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		foreach(self::$activeStates as $inactive => $active){
			if($block->getStateId() === $active){
				$world->setBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($inactive));
			}
		}
	}
}
