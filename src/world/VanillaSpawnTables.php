<?php

/*
 * BetterPMMP - vanilla natural spawn tables.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\entity\ArmadilloMob;
use pocketmine\entity\BatMob;
use pocketmine\entity\BoggedMob;
use pocketmine\entity\CamelMob;
use pocketmine\entity\Chicken;
use pocketmine\entity\Cow;
use pocketmine\entity\CreeperMob;
use pocketmine\entity\DonkeyMob;
use pocketmine\entity\EndermanMob;
use pocketmine\entity\FoxMob;
use pocketmine\entity\FrogMob;
use pocketmine\entity\GoatMob;
use pocketmine\entity\HorseMob;
use pocketmine\entity\HuskMob;
use pocketmine\entity\LlamaMob;
use pocketmine\entity\MooshroomMob;
use pocketmine\entity\OcelotMob;
use pocketmine\entity\PandaMob;
use pocketmine\entity\ParchedMob;
use pocketmine\entity\ParrotMob;
use pocketmine\entity\Pig;
use pocketmine\entity\PolarBearMob;
use pocketmine\entity\RabbitMob;
use pocketmine\entity\Sheep;
use pocketmine\entity\SkeletonMob;
use pocketmine\entity\SlimeMob;
use pocketmine\entity\SpiderMob;
use pocketmine\entity\StrayMob;
use pocketmine\entity\TurtleMob;
use pocketmine\entity\WitchMob;
use pocketmine\entity\WolfMob;
use pocketmine\entity\Zombie;
use pocketmine\entity\ZombieVillagerMob;
use function in_array;
use function mt_rand;

/**
 * Overworld spawn lists per biome with vanilla weights and group sizes.
 * Each entry: [entity class, weight, min group, max group].
 */
final class VanillaSpawnTables{

	public const CATEGORY_MONSTER = 0;
	public const CATEGORY_CREATURE = 1;
	public const CATEGORY_AMBIENT = 2;

	/** Vanilla per-player mob caps. */
	public const CAPS = [
		self::CATEGORY_MONSTER => 70,
		self::CATEGORY_CREATURE => 10,
		self::CATEGORY_AMBIENT => 15,
	];

	private const DESERT = [B::DESERT, B::DESERT_HILLS, B::DESERT_MUTATED];
	private const SNOWY = [B::ICE_PLAINS, B::ICE_MOUNTAINS, B::ICE_PLAINS_SPIKES, B::COLD_TAIGA, B::COLD_TAIGA_HILLS, B::COLD_TAIGA_MUTATED, B::FROZEN_RIVER, B::COLD_BEACH, B::ICE_CAVES];
	private const SWAMP = [B::SWAMPLAND, B::SWAMPLAND_MUTATED, B::MANGROVE_SWAMP];
	private const NO_MONSTERS = [B::MUSHROOM_ISLAND, B::MUSHROOM_ISLAND_SHORE, B::DEEP_DARK];
	private const FARM = [[Sheep::class, 12, 4, 4], [Pig::class, 10, 4, 4], [Chicken::class, 10, 4, 4], [Cow::class, 8, 4, 4]];

	public static function isSwamp(int $biome) : bool{
		return in_array($biome, self::SWAMP, true);
	}

	/**
	 * @return array<int, array{class-string, int, int, int}>
	 */
	public static function monsters(int $biome) : array{
		if(in_array($biome, self::NO_MONSTERS, true)){
			return [];
		}
		$zombie = [[Zombie::class, 95, 4, 4], [ZombieVillagerMob::class, 5, 1, 1]];
		$skeleton = [[SkeletonMob::class, 100, 4, 4]];
		if(in_array($biome, self::DESERT, true)){
			$zombie = [[Zombie::class, 19, 4, 4], [ZombieVillagerMob::class, 1, 1, 1], [HuskMob::class, 80, 4, 4]];
			$skeleton = [[SkeletonMob::class, 20, 4, 4], [ParchedMob::class, 80, 4, 4]];
		}elseif(in_array($biome, self::SNOWY, true)){
			$skeleton = [[SkeletonMob::class, 20, 4, 4], [StrayMob::class, 80, 4, 4]];
		}elseif(self::isSwamp($biome)){
			$skeleton = [[SkeletonMob::class, 50, 4, 4], [BoggedMob::class, 50, 4, 4]];
		}
		return [
			[SpiderMob::class, 100, 4, 4],
			...$zombie,
			...$skeleton,
			[CreeperMob::class, 100, 4, 4],
			[SlimeMob::class, 100, 4, 4],
			[EndermanMob::class, 10, 1, 4],
			[WitchMob::class, 5, 1, 1],
		];
	}

	/**
	 * @return array<int, array{class-string, int, int, int}>
	 */
	public static function creatures(int $biome) : array{
		return match(true){
			in_array($biome, [B::PLAINS, B::SUNFLOWER_PLAINS], true) => [...self::FARM, [HorseMob::class, 5, 2, 6], [DonkeyMob::class, 1, 1, 3]],
			in_array($biome, [B::FOREST, B::FOREST_HILLS, B::ROOFED_FOREST, B::ROOFED_FOREST_MUTATED, B::DAPPLED_FOREST], true) => [...self::FARM, [WolfMob::class, 5, 4, 4]],
			$biome === B::FLOWER_FOREST => [...self::FARM, [RabbitMob::class, 4, 2, 3]],
			in_array($biome, [B::BIRCH_FOREST, B::BIRCH_FOREST_HILLS, B::BIRCH_FOREST_MUTATED, B::BIRCH_FOREST_HILLS_MUTATED, B::PALE_GARDEN], true) => self::FARM,
			in_array($biome, [B::TAIGA, B::TAIGA_HILLS, B::TAIGA_MUTATED, B::MEGA_TAIGA, B::MEGA_TAIGA_HILLS, B::REDWOOD_TAIGA_MUTATED, B::REDWOOD_TAIGA_HILLS_MUTATED], true) => [...self::FARM, [WolfMob::class, 8, 4, 4], [RabbitMob::class, 4, 2, 3], [FoxMob::class, 8, 2, 4]],
			in_array($biome, [B::COLD_TAIGA, B::COLD_TAIGA_HILLS, B::COLD_TAIGA_MUTATED, B::GROVE], true) => [[WolfMob::class, 8, 4, 4], [RabbitMob::class, 4, 2, 3], [FoxMob::class, 8, 2, 4]],
			in_array($biome, [B::ICE_PLAINS, B::ICE_MOUNTAINS, B::ICE_PLAINS_SPIKES], true) => [[RabbitMob::class, 10, 2, 3], [PolarBearMob::class, 1, 1, 2]],
			in_array($biome, self::DESERT, true) => [[RabbitMob::class, 4, 2, 3], [CamelMob::class, 1, 1, 1]],
			in_array($biome, [B::SAVANNA, B::SAVANNA_MUTATED], true) => [...self::FARM, [HorseMob::class, 1, 2, 6], [DonkeyMob::class, 1, 1, 1], [ArmadilloMob::class, 10, 2, 3]],
			in_array($biome, [B::SAVANNA_PLATEAU, B::SAVANNA_PLATEAU_MUTATED], true) => [...self::FARM, [HorseMob::class, 1, 2, 6], [DonkeyMob::class, 1, 1, 1], [LlamaMob::class, 8, 4, 4], [ArmadilloMob::class, 10, 2, 3]],
			in_array($biome, [B::EXTREME_HILLS, B::EXTREME_HILLS_EDGE, B::EXTREME_HILLS_MUTATED, B::EXTREME_HILLS_PLUS_TREES, B::EXTREME_HILLS_PLUS_TREES_MUTATED], true) => [...self::FARM, [LlamaMob::class, 5, 4, 6]],
			in_array($biome, [B::JUNGLE, B::JUNGLE_HILLS, B::JUNGLE_MUTATED, B::JUNGLE_EDGE, B::JUNGLE_EDGE_MUTATED], true) => [...self::FARM, [ParrotMob::class, 40, 1, 2], [PandaMob::class, 1, 1, 2], [OcelotMob::class, 2, 1, 3]],
			in_array($biome, [B::BAMBOO_JUNGLE, B::BAMBOO_JUNGLE_HILLS], true) => [...self::FARM, [ParrotMob::class, 40, 1, 2], [PandaMob::class, 80, 1, 2], [OcelotMob::class, 2, 1, 1]],
			$biome === B::MEADOW => [[DonkeyMob::class, 1, 1, 2], [RabbitMob::class, 2, 2, 6], [Sheep::class, 2, 2, 4]],
			$biome === B::CHERRY_GROVE => [[Pig::class, 1, 1, 2], [RabbitMob::class, 2, 2, 6], [Sheep::class, 2, 2, 4]],
			in_array($biome, [B::SNOWY_SLOPES, B::JAGGED_PEAKS, B::FROZEN_PEAKS], true) => [[GoatMob::class, 5, 1, 3], [RabbitMob::class, 4, 2, 3]],
			self::isSwamp($biome) => [...self::FARM, [FrogMob::class, 10, 2, 5]],
			in_array($biome, [B::BEACH], true) => [[TurtleMob::class, 5, 2, 5]],
			in_array($biome, [B::MUSHROOM_ISLAND, B::MUSHROOM_ISLAND_SHORE], true) => [[MooshroomMob::class, 8, 4, 8]],
			in_array($biome, [B::MESA, B::MESA_BRYCE, B::MESA_PLATEAU, B::MESA_PLATEAU_STONE, B::MESA_PLATEAU_MUTATED, B::MESA_PLATEAU_STONE_MUTATED], true) => [[ArmadilloMob::class, 6, 1, 2]],
			default => [],
		};
	}

	/**
	 * @return array<int, array{class-string, int, int, int}>
	 */
	public static function ambient(int $biome) : array{
		return in_array($biome, self::NO_MONSTERS, true) ? [] : [[BatMob::class, 10, 8, 8]];
	}

	/**
	 * @param array<int, array{class-string, int, int, int}> $entries
	 * @return array{class-string, int, int, int}|null
	 */
	public static function pick(array $entries) : ?array{
		$total = 0;
		foreach($entries as $entry){
			$total += $entry[1];
		}
		if($total <= 0){
			return null;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($entries as $entry){
			$roll -= $entry[1];
			if($roll < 0){
				return $entry;
			}
		}
		return null;
	}

	/** Vanilla slime chunk test (one chunk in ten, derived from the world seed). */
	public static function isSlimeChunk(int $seed, int $chunkX, int $chunkZ) : bool{
		$hash = ($seed + (($chunkX * $chunkX * 0x4c1906) & 0xffffffff) + (($chunkX * 0x5ac0db) & 0xffffffff) + (($chunkZ * $chunkZ * 0x4307a7) & 0xffffffff) + (($chunkZ * 0x5f24f) & 0xffffffff)) ^ 0x3ad8025f;
		$random = new \pocketmine\utils\Random($hash & 0x7fffffff);
		return $random->nextBoundedInt(10) === 0;
	}
}
