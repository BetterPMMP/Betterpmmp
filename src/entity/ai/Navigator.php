<?php

/*
 * BetterPMMP - path following for walking mobs.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use function abs;
use function atan2;
use function count;
use function floor;
use function max;
use function min;
use function rad2deg;
use function sqrt;

/**
 * Follows a path from {@link PathFinder}: steers towards the next node,
 * jumps up steps, swims up in water and re-plans when the goal moved, the
 * mob got stuck or the path ran out. Paths are computed under a global
 * per-tick budget so large mob farms cannot stall the server.
 */
final class Navigator{

	/**
	 * @var int[][]
	 * @phpstan-var list<array{int, int, int}>
	 */
	private array $path = [];
	private int $index = 0;
	private ?Vector3 $goal = null;
	private float $speed = 0.0;
	private float $reach = 1.0;
	private int $repathCooldown = 0;
	private int $stuckTicks = 0;
	private ?Vector3 $lastPos = null;
	private int $failures = 0;

	public function __construct(
		private Living $mob,
		private int $height = 2,
		private bool $canSwim = true
	){}

	public function isMoving() : bool{
		return $this->goal !== null;
	}

	public function getGoal() : ?Vector3{
		return $this->goal;
	}

	public function stop() : void{
		$this->goal = null;
		$this->path = [];
		$this->index = 0;
	}

	/**
	 * Starts or updates navigation. The path is only re-planned when the goal
	 * moved noticeably or the cooldown ran out.
	 */
	public function moveTo(Vector3 $goal, float $speed, float $reach = 1.0) : void{
		$this->speed = $speed;
		$this->reach = $reach;
		$moved = $this->goal === null || $this->goal->distanceSquared($goal) > 2.25;
		$this->goal = $goal->asVector3();
		if($moved && $this->repathCooldown <= 0){
			$this->repath();
		}
	}

	private function repath() : bool{
		$goal = $this->goal;
		if($goal === null){
			return false;
		}
		$world = $this->mob->getWorld();
		if(!PathFinder::takeBudget()){
			$this->repathCooldown = 1;
			return false;
		}
		$pos = $this->mob->getPosition();
		$distance = $pos->distance($goal);
		$finder = new PathFinder($world, $this->height, $this->canSwim);
		$path = $finder->find(
			(int) floor($pos->x), (int) floor($pos->y + 0.01), (int) floor($pos->z),
			(int) floor($goal->x), (int) floor($goal->y + 0.01), (int) floor($goal->z),
			(int) min(600, max(100, $distance * 16)),
			$this->reach
		);
		$this->repathCooldown = 10;
		if($path === null){
			++$this->failures;
			$this->path = [];
			$this->index = 0;
			// back off when the goal keeps being unreachable
			$this->repathCooldown = min(60, 10 * $this->failures);
			return false;
		}
		$this->failures = 0;
		$this->path = $path;
		$this->index = 0;
		return true;
	}

	/**
	 * Applies one tick of steering. Returns false when navigation finished
	 * (goal reached or no way there).
	 */
	public function tick(int $tickDiff) : bool{
		if($this->goal === null){
			return false;
		}
		$this->repathCooldown -= $tickDiff;
		$pos = $this->mob->getPosition();

		// stuck detection
		if($this->lastPos !== null && $this->lastPos->distanceSquared($pos) < 0.0025 * $tickDiff){
			$this->stuckTicks += $tickDiff;
		}else{
			$this->stuckTicks = 0;
		}
		$this->lastPos = $pos->asVector3();

		$goal = $this->goal;
		$gdx = $goal->x - $pos->x;
		$gdz = $goal->z - $pos->z;
		if($gdx * $gdx + $gdz * $gdz <= $this->reach * $this->reach && abs($goal->y - $pos->y) < 2){
			$this->halt();
			$this->stop();
			return false;
		}

		if(($this->index >= count($this->path) || $this->stuckTicks > 20) && $this->repathCooldown <= 0){
			$this->stuckTicks = 0;
			if(!$this->repath() && $this->failures >= 4){
				$this->halt();
				$this->stop();
				return false;
			}
		}
		if($this->index >= count($this->path)){
			$this->halt();
			return true;
		}

		// skip nodes we already passed
		while($this->index < count($this->path)){
			[$nx, $ny, $nz] = $this->path[$this->index];
			$dx = $nx + 0.5 - $pos->x;
			$dz = $nz + 0.5 - $pos->z;
			if($dx * $dx + $dz * $dz < 0.12 && abs($ny - $pos->y) < 1.2){
				++$this->index;
				continue;
			}
			break;
		}
		if($this->index >= count($this->path)){
			return true;
		}
		[$nx, $ny, $nz] = $this->path[$this->index];
		$dx = $nx + 0.5 - $pos->x;
		$dz = $nz + 0.5 - $pos->z;
		$len = sqrt($dx * $dx + $dz * $dz);
		$motion = $this->mob->getMotion();
		$vy = $motion->y;
		$inWater = $this->mob->isUnderwater() || $this->mob->getWorld()->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))->getTypeId() === \pocketmine\block\BlockTypeIds::WATER;
		if($inWater){
			// keep the head above water like vanilla's FloatGoal
			$vy = max($vy, 0.08);
		}elseif($ny > floor($pos->y + 0.01) && $this->mob->isOnGround() && $len < 1.6){
			$vy = 0.42;
		}
		$speed = $this->speed;
		if($len < $speed){
			$speed = $len;
		}
		if($len > 0.0001){
			$this->mob->setMotion(new Vector3($dx / $len * $speed, $vy, $dz / $len * $speed));
			$this->mob->setRotation(rad2deg(atan2(-$dx, $dz)), $this->mob->getLocation()->getPitch());
		}else{
			$this->mob->setMotion(new Vector3(0, $vy, 0));
		}
		return true;
	}

	private function halt() : void{
		$motion = $this->mob->getMotion();
		$this->mob->setMotion(new Vector3(0, $motion->y, 0));
	}
}
