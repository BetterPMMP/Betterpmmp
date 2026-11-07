<?php

/*
 * BetterPMMP - one raid.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\raid;

use pocketmine\math\Vector3;
use pocketmine\player\Player;

final class Raid{

	public const STATE_ACTIVE = 0;
	public const STATE_OVER = 1;

	public int $wave = 0;
	public int $state = self::STATE_ACTIVE;
	public bool $won = false;
	public int $ticks = 0;
	public int $pause = 200;
	public int $idleTicks = 0;
	public int $endTicks = 0;
	public float $waveHealth = 0.0;
	/** @var int[] */
	public array $raiders = [];
	/** @var array<int, Player> */
	public array $barPlayers = [];

	public function __construct(
		public readonly int $id,
		public readonly Vector3 $center,
		public int $totalWaves,
		public int $omenLevel
	){}
}
