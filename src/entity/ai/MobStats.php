<?php

/*
 * BetterPMMP - vanilla mob attributes.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds as E;
use pocketmine\world\World;
use function max;
use function min;

/**
 * Vanilla base values per mob: max health, movement speed attribute, melee
 * damage on normal difficulty, follow range, and collision box size.
 */
final class MobStats{

	/** health, speed attribute, melee damage (normal), follow range, width, height */
	public const TABLE = [
		E::ZOMBIE => [20, 0.23, 3, 35, 0.6, 1.9],
		E::HUSK => [20, 0.23, 3, 35, 0.6, 1.9],
		E::DROWNED => [20, 0.23, 3, 35, 0.6, 1.9],
		E::ZOMBIE_VILLAGER => [20, 0.23, 3, 35, 0.6, 1.9],
		E::ZOMBIE_PIGMAN => [20, 0.23, 5, 35, 0.6, 1.9],
		E::SKELETON => [20, 0.25, 2, 16, 0.6, 1.99],
		E::STRAY => [20, 0.25, 2, 16, 0.6, 1.99],
		E::BOGGED => [16, 0.25, 2, 16, 0.6, 1.99],
		E::PARCHED => [16, 0.25, 2, 16, 0.6, 1.99],
		E::WITHER_SKELETON => [20, 0.25, 8, 16, 0.72, 2.01],
		E::CREEPER => [20, 0.2, 0, 16, 0.6, 1.8],
		E::SPIDER => [16, 0.3, 2, 16, 1.4, 0.9],
		E::CAVE_SPIDER => [12, 0.3, 2, 16, 0.7, 0.5],
		E::ENDERMAN => [40, 0.3, 7, 64, 0.6, 2.9],
		E::ENDERMITE => [8, 0.25, 2, 16, 0.4, 0.3],
		E::SILVERFISH => [8, 0.25, 1, 16, 0.4, 0.3],
		E::WITCH => [26, 0.25, 0, 16, 0.6, 1.95],
		E::PILLAGER => [24, 0.35, 4, 32, 0.6, 1.9],
		E::VINDICATOR => [24, 0.35, 13, 12, 0.6, 1.9],
		E::EVOCATION_ILLAGER => [24, 0.3, 6, 16, 0.6, 1.9],
		E::RAVAGER => [100, 0.3, 12, 32, 1.95, 2.2],
		E::VEX => [14, 0.3, 9, 16, 0.4, 0.8],
		E::PIGLIN => [16, 0.35, 5, 16, 0.6, 1.9],
		E::PIGLIN_BRUTE => [50, 0.35, 13, 16, 0.6, 1.9],
		E::HOGLIN => [40, 0.3, 6, 16, 1.4, 1.4],
		E::ZOGLIN => [40, 0.3, 6, 16, 1.4, 1.4],
		E::BLAZE => [20, 0.23, 6, 48, 0.6, 1.8],
		E::GHAST => [10, 0.03, 0, 64, 4.0, 4.0],
		E::WARDEN => [500, 0.3, 30, 24, 0.9, 2.9],
		E::GUARDIAN => [30, 0.12, 6, 16, 0.85, 0.85],
		E::ELDER_GUARDIAN => [80, 0.12, 8, 16, 2.0, 2.0],
		E::PHANTOM => [20, 0.3, 2, 64, 0.9, 0.5],
		E::SHULKER => [30, 0.0, 4, 16, 1.0, 1.0],
		E::BREEZE => [30, 0.3, 1, 24, 0.6, 1.77],
		E::CREAKING => [1, 0.4, 3, 32, 0.9, 2.7],
		E::IRON_GOLEM => [100, 0.25, 15, 16, 1.4, 2.9],
		E::SNOW_GOLEM => [4, 0.2, 0, 16, 0.7, 1.9],
		E::WOLF => [8, 0.3, 4, 16, 0.6, 0.8],
		E::POLAR_BEAR => [30, 0.25, 6, 20, 1.3, 1.4],
		E::PANDA => [20, 0.15, 6, 16, 1.3, 1.25],
		E::BEE => [10, 0.3, 2, 16, 0.55, 0.5],
		E::GOAT => [10, 0.2, 2, 16, 0.9, 1.3],
		E::FOX => [10, 0.3, 2, 16, 0.6, 0.7],
		E::DOLPHIN => [10, 1.2, 3, 16, 0.9, 0.6],
		E::LLAMA => [22, 0.25, 1, 16, 0.9, 1.87],
		E::TRADER_LLAMA => [22, 0.25, 1, 16, 0.9, 1.87],
		E::COW => [10, 0.25, 0, 16, 0.9, 1.3],
		E::MOOSHROOM => [10, 0.25, 0, 16, 0.9, 1.3],
		E::PIG => [10, 0.25, 0, 16, 0.9, 0.9],
		E::SHEEP => [8, 0.25, 0, 16, 0.9, 1.3],
		E::CHICKEN => [4, 0.25, 0, 16, 0.6, 0.8],
		E::RABBIT => [3, 0.3, 0, 16, 0.4, 0.5],
		E::HORSE => [22, 0.225, 0, 16, 1.4, 1.6],
		E::DONKEY => [22, 0.175, 0, 16, 1.4, 1.6],
		E::MULE => [22, 0.175, 0, 16, 1.4, 1.6],
		E::SKELETON_HORSE => [15, 0.2, 0, 16, 1.4, 1.6],
		E::ZOMBIE_HORSE => [15, 0.2, 0, 16, 1.4, 1.6],
		E::CAMEL => [32, 0.18, 0, 16, 1.7, 2.375],
		E::SNIFFER => [14, 0.1, 0, 16, 1.9, 1.75],
		E::TURTLE => [30, 0.1, 0, 16, 1.2, 0.4],
		E::FROG => [10, 0.1, 0, 16, 0.5, 0.5],
		E::ARMADILLO => [12, 0.14, 0, 16, 0.7, 0.65],
		E::CAT => [10, 0.3, 0, 16, 0.6, 0.7],
		E::OCELOT => [10, 0.3, 0, 16, 0.6, 0.7],
		E::PARROT => [6, 0.4, 0, 16, 0.5, 0.9],
		E::BAT => [6, 0.1, 0, 16, 0.5, 0.9],
		E::AXOLOTL => [14, 0.1, 2, 16, 0.75, 0.42],
		E::STRIDER => [20, 0.16, 0, 16, 0.9, 1.7],
		E::WANDERING_TRADER => [20, 0.25, 0, 16, 0.6, 1.9],
		E::ALLAY => [20, 0.1, 0, 16, 0.35, 0.6],
		E::COD => [3, 0.1, 0, 16, 0.5, 0.3],
		E::SALMON => [3, 0.1, 0, 16, 0.5, 0.5],
		E::PUFFERFISH => [3, 0.1, 0, 16, 0.8, 0.8],
		E::TROPICALFISH => [3, 0.1, 0, 16, 0.5, 0.52],
		E::GLOW_SQUID => [10, 0.1, 0, 16, 0.95, 0.95],
		E::TADPOLE => [6, 0.1, 0, 16, 0.4, 0.3],
	];

	/** Ground speed in blocks per tick when chasing, from the speed attribute. */
	public const CHASE_FACTOR = 0.85;
	/** Ground speed in blocks per tick when strolling. */
	public const WANDER_FACTOR = 0.55;

	/**
	 * @return array{int, float, float, int, float, float}|null
	 */
	public static function get(string $entityId) : ?array{
		$row = self::TABLE[$entityId] ?? null;
		return $row === null ? null : [(int) $row[0], (float) $row[1], (float) $row[2], (int) $row[3], (float) $row[4], (float) $row[5]];
	}

	/**
	 * Vanilla difficulty scaling of mob melee damage: easy = min(d / 2 + 1, d),
	 * hard = d * 1.5, peaceful deals none.
	 */
	public static function scaleDamage(float $normal, int $difficulty) : float{
		return match($difficulty){
			World::DIFFICULTY_PEACEFUL => 0.0,
			World::DIFFICULTY_EASY => min($normal / 2 + 1, $normal),
			World::DIFFICULTY_HARD => $normal * 1.5,
			default => $normal,
		};
	}

	/** Squared melee reach between two mobs, like vanilla's getAttackReachSqr. */
	public static function reachSq(float $attackerWidth, float $targetWidth) : float{
		$r = $attackerWidth * 2.0;
		return max(2.0, $r * $r + $targetWidth);
	}
}
