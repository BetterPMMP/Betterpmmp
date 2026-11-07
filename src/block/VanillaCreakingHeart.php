<?php

/*
 * BetterPMMP - creaking heart (pale garden).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\CreakingMob;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\World;
use function abs;
use function mt_rand;

/**
 * Vanilla creaking heart: only works between two pale oak logs along its
 * axis. A natural heart wakes up at night ("awake") and calls a creaking
 * within 16 blocks once a player is within 32 blocks; the creaking cannot be
 * hurt while its heart lives - hitting it makes the heart grow resin on the
 * logs around it. At day the heart falls asleep and the creaking crumbles.
 * Breaking the heart kills its creaking; the heart drops 1-3 resin clumps.
 */
class VanillaCreakingHeart extends GenericBedrockBlock{

	/** @var array<string, int> heart pos => creaking entity id */
	private static array $creakings = [];

	private static function key(Vector3 $pos, World $world) : string{
		return $world->getId() . ":" . $pos->getFloorX() . ":" . $pos->getFloorY() . ":" . $pos->getFloorZ();
	}

	private function state() : string{
		$tag = $this->getStateData()->getStates()["creaking_heart_state"] ?? null;
		return $tag === null ? "uprooted" : (string) $tag->getValue();
	}

	private function isNatural() : bool{
		$tag = $this->getStateData()->getStates()["natural"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	private function axis() : int{
		$tag = $this->getStateData()->getStates()["pillar_axis"] ?? null;
		return match($tag === null ? "y" : (string) $tag->getValue()){ "x" => Axis::X, "z" => Axis::Z, default => Axis::Y };
	}

	/** Between two pale oak logs on its axis. */
	public function isEnclosed() : bool{
		[$a, $b] = match($this->axis()){ Axis::X => [Facing::WEST, Facing::EAST], Axis::Z => [Facing::NORTH, Facing::SOUTH], default => [Facing::DOWN, Facing::UP] };
		foreach([$a, $b] as $face){
			$side = $this->getSide($face);
			if($side->getTypeId() !== BlockTypeIds::PALE_OAK_LOG && $side->getTypeId() !== BlockTypeIds::PALE_OAK_WOOD){
				return false;
			}
		}
		return true;
	}

	private function setState(string $state) : void{
		try{
			$this->position->getWorld()->setBlock($this->position, $this->withProperties(["creaking_heart_state" => $state]));
		}catch(\InvalidArgumentException){
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	private static function isNight(World $world) : bool{
		$time = $world->getTimeOfDay();
		return $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if(!$this->isEnclosed()){
			if($this->state() !== "uprooted"){
				$this->setState("uprooted");
			}
			return;
		}
		$night = self::isNight($world);
		if($night && $this->state() !== "awake"){
			$this->setState("awake");
			$world->scheduleDelayedBlockUpdate($this->position, 20);
		}elseif(!$night && $this->state() === "awake"){
			$this->setState("dormant");
			$this->releaseCreaking(false);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if($this->state() !== "awake"){
			return;
		}
		if(!self::isNight($world) || !$this->isEnclosed()){
			$this->setState($this->isEnclosed() ? "dormant" : "uprooted");
			$this->releaseCreaking(false);
			return;
		}
		$creaking = $this->getCreaking();
		if($creaking !== null && $creaking->getPosition()->distanceSquared($this->position) > 32 * 32){
			$this->releaseCreaking(false);
			$creaking = null;
		}
		if($creaking === null && $this->isNatural() && $world->getDifficulty() !== World::DIFFICULTY_PEACEFUL){
			foreach($world->getPlayers() as $player){
				if(!$player->isSpectator() && $player->getPosition()->distanceSquared($this->position) < 32 * 32){
					$this->spawnCreaking();
					break;
				}
			}
		}
		$world->scheduleDelayedBlockUpdate($this->position, 20);
	}

	public function getCreaking() : ?CreakingMob{
		$id = self::$creakings[self::key($this->position, $this->position->getWorld())] ?? null;
		if($id === null){
			return null;
		}
		$entity = $this->position->getWorld()->getEntity($id);
		return $entity instanceof CreakingMob && $entity->isAlive() ? $entity : null;
	}

	private function spawnCreaking() : void{
		$world = $this->position->getWorld();
		for($attempt = 0; $attempt < 16; ++$attempt){
			$x = $this->position->getFloorX() + mt_rand(-16, 16);
			$z = $this->position->getFloorZ() + mt_rand(-16, 16);
			if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
				continue;
			}
			$y = $world->getHighestBlockAt($x, $z);
			if($y === null || abs($y - $this->position->getFloorY()) > 12 || !$world->getBlockAt($x, $y, $z)->isSolid()){
				continue;
			}
			$creaking = new CreakingMob(Location::fromObject(new Vector3($x + 0.5, $y + 1, $z + 0.5), $world, mt_rand(0, 359), 0));
			$creaking->linkHeart($this->position->asVector3());
			$creaking->spawnToAll();
			self::$creakings[self::key($this->position, $world)] = $creaking->getId();
			$world->broadcastPacketToViewers($this->position, LevelSoundEventPacket::nonActorSound(LevelSoundEvent::CREAKING_HEART_SPAWN, $this->position, false));
			return;
		}
	}

	/** Removes the creaking: crumbles (despawn) or dies when the heart is broken. */
	public function releaseCreaking(bool $kill) : void{
		$creaking = $this->getCreaking();
		unset(self::$creakings[self::key($this->position, $this->position->getWorld())]);
		if($creaking === null){
			return;
		}
		$creaking->linkHeart(null);
		if($kill){
			$creaking->kill();
		}else{
			$creaking->flagForDespawn();
		}
	}

	/** The creaking was hit: the heart grows resin on nearby logs (vanilla: up to 3). */
	public function onCreakingHurt() : void{
		$world = $this->position->getWorld();
		$world->broadcastPacketToViewers($this->position, LevelSoundEventPacket::nonActorSound(LevelSoundEvent::BLOCK_CREAKING_HEART_TRAIL, $this->position, false));
		$grown = 0;
		for($attempt = 0; $attempt < 20 && $grown < mt_rand(1, 3); ++$attempt){
			$log = $world->getBlockAt($this->position->getFloorX() + mt_rand(-2, 2), $this->position->getFloorY() + mt_rand(-2, 2), $this->position->getFloorZ() + mt_rand(-2, 2));
			if($log->getTypeId() !== BlockTypeIds::PALE_OAK_LOG){
				continue;
			}
			foreach(Facing::ALL as $face){
				$side = $log->getSide($face);
				if($side->getTypeId() === BlockTypeIds::AIR){
					$world->setBlock($side->getPosition(), VanillaBlocks::RESIN_CLUMP()->setFace(Facing::opposite($face), true));
					++$grown;
					break;
				}
			}
		}
	}

	public function onBreak(Item $item, ?\pocketmine\player\Player $player = null, array &$returnedItems = []) : bool{
		$this->releaseCreaking(true);
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [VanillaBlocks::RESIN_CLUMP()->asItem()->setCount(mt_rand(1, 3))];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
