<?php

/*
 * BetterPMMP - omens and raids.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\raid;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\ExtraEffects;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Villager;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\World;
use function count;
use function method_exists;
use const M_PI;
use function cos;
use function max;
use function min;
use function mt_rand;
use function sin;

/**
 * Vanilla omens:
 *  - Bad Omen (ominous bottle) becomes Raid Omen (30 s) when the player is in
 *    a village, and Trial Omen (15 min per level) at a trial spawner
 *  - when Raid Omen runs out a raid starts at the village
 *
 * Vanilla raids: 3 / 5 / 7 waves on Easy / Normal / Hard plus a bonus wave
 * with Bad Omen II+, pillagers, vindicators, ravagers, witches and evokers
 * per wave from the vanilla table, spawned 24-32 blocks from the village,
 * boss bar "Raid" with the raiders' remaining health, horn at every wave,
 * 15 s between waves. Victory gives Hero of the Village to the players who
 * defended it; the raid is lost when no villager is left.
 */
final class RaidManager{

	/** wave => [pillager, vindicator, ravager, witch, evoker] (vanilla Normal counts) */
	private const WAVES = [
		1 => [4, 0, 0, 0, 0], 2 => [3, 2, 0, 0, 0], 3 => [3, 0, 1, 0, 0], 4 => [4, 1, 0, 3, 0],
		5 => [4, 4, 1, 0, 1], 6 => [4, 2, 0, 0, 1], 7 => [2, 5, 1, 1, 2], 8 => [2, 5, 2, 1, 2],
	];
	private const CLASSES = [
		\pocketmine\entity\PillagerMob::class, \pocketmine\entity\VindicatorMob::class, \pocketmine\entity\RavagerMob::class,
		\pocketmine\entity\WitchMob::class, \pocketmine\entity\EvokerMob::class,
	];
	private const RANGE = 96;

	/** @var Raid[] */
	private array $raids = [];
	private int $nextId = 1;
	private int $ticker = 0;

	public function __construct(private World $world){}

	/** @return Raid[] */
	public function getRaids() : array{ return $this->raids; }

	public function tick() : void{
		if(++$this->ticker % 20 === 0){
			$this->checkOmens();
		}
		foreach($this->raids as $id => $raid){
			if(!$this->tickRaid($raid)){
				unset($this->raids[$id]);
			}
		}
	}

	/** Villagers within 32 blocks, or null if not in a village. */
	private function villageCenter(Vector3 $pos) : ?Vector3{
		$sum = new Vector3(0, 0, 0);
		$n = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Villager && $entity->isAlive() && $entity->getPosition()->distanceSquared($pos) < 32 * 32){
				$sum = $sum->addVector($entity->getPosition());
				++$n;
			}
		}
		return $n === 0 ? null : $sum->divide($n);
	}

	private function nearTrialSpawner(Vector3 $pos) : bool{
		$x0 = $pos->getFloorX();
		$y0 = $pos->getFloorY();
		$z0 = $pos->getFloorZ();
		for($x = $x0 - 8; $x <= $x0 + 8; ++$x){
			for($z = $z0 - 8; $z <= $z0 + 8; ++$z){
				for($y = $y0 - 3; $y <= $y0 + 3; ++$y){
					$block = $this->world->getBlockAt($x, $y, $z);
					if($block instanceof \pocketmine\block\VanillaTrialSpawner){
						return true;
					}
				}
			}
		}
		return false;
	}

	private function checkOmens() : void{
		foreach($this->world->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator()){
				continue;
			}
			$effects = $player->getEffects();
			$bad = $effects->get(ExtraEffects::get("bad_omen"));
			if($bad !== null && $this->world->getDifficulty() !== World::DIFFICULTY_PEACEFUL){
				$level = $bad->getAmplifier();
				if($this->villageCenter($player->getPosition()) !== null && $this->raidAt($player->getPosition()) === null){
					$effects->remove(ExtraEffects::get("bad_omen"));
					$effects->add(new EffectInstance(ExtraEffects::get("raid_omen"), 600, $level));
					$player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\VanillaSound(LevelSoundEvent::APPLY_EFFECT_RAID_OMEN));
				}elseif($player->getPosition()->y < 0 && $this->nearTrialSpawner($player->getPosition())){
					$effects->remove(ExtraEffects::get("bad_omen"));
					$effects->add(new EffectInstance(ExtraEffects::get("trial_omen"), 18000 * ($level + 1), 0));
					$player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\VanillaSound(LevelSoundEvent::APPLY_EFFECT_TRIAL_OMEN));
				}
			}
			$omen = $effects->get(ExtraEffects::get("raid_omen"));
			if($omen !== null && $omen->getDuration() <= 20){
				$effects->remove(ExtraEffects::get("raid_omen"));
				$center = $this->villageCenter($player->getPosition());
				if($center !== null){
					$this->startRaid($center, $omen->getAmplifier());
				}
			}
		}
	}

	public function raidAt(Vector3 $pos) : ?Raid{
		foreach($this->raids as $raid){
			if($raid->center->distanceSquared($pos) < self::RANGE * self::RANGE){
				return $raid;
			}
		}
		return null;
	}

	public function startRaid(Vector3 $center, int $omenLevel) : Raid{
		$existing = $this->raidAt($center);
		if($existing !== null){
			$existing->omenLevel = min(4, max($existing->omenLevel, $omenLevel) + 1);
			return $existing;
		}
		$waves = match($this->world->getDifficulty()){
			World::DIFFICULTY_EASY => 3,
			World::DIFFICULTY_HARD => 7,
			default => 5
		};
		$raid = new Raid($this->nextId++, $center, $waves + ($omenLevel > 0 ? 1 : 0), $omenLevel);
		$this->raids[$raid->id] = $raid;
		return $raid;
	}

	/** @return Player[] */
	private function playersNear(Raid $raid) : array{
		$players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isConnected() && $player->getPosition()->distanceSquared($raid->center) < self::RANGE * self::RANGE){
				$players[] = $player;
			}
		}
		return $players;
	}

	private function tickRaid(Raid $raid) : bool{
		++$raid->ticks;
		$players = $this->playersNear($raid);
		$raiders = [];
		$health = 0.0;
		foreach($raid->raiders as $id){
			$e = $this->world->getEntity($id);
			if($e instanceof Living && $e->isAlive()){
				$raiders[] = $id;
				$health += $e->getHealth();
			}
		}
		$raid->raiders = $raiders;

		if($raid->state === Raid::STATE_OVER){
			if(--$raid->endTicks <= 0){
				$this->hideBar($raid);
				return false;
			}
			$this->showBar($raid, $players, $raid->won ? "Raid - Victory" : "Raid - Defeat", $raid->won ? 1.0 : 0.0);
			return true;
		}
		// lost: no villagers left
		if($raid->ticks % 20 === 0 && $this->villageCenter($raid->center) === null){
			$this->finish($raid, false, $players);
			return true;
		}
		if(count($players) === 0){
			if(++$raid->idleTicks > 2400){
				$this->hideBar($raid);
				return false;
			}
			return true;
		}
		$raid->idleTicks = 0;

		if(count($raiders) === 0){
			if($raid->wave >= $raid->totalWaves){
				$this->finish($raid, true, $players);
				return true;
			}
			// between waves: 15 s, the bar fills up
			$raid->pause += 1;
			$this->showBar($raid, $players, "Raid", min(1.0, $raid->pause / 300));
			if($raid->pause >= 300){
				$raid->pause = 0;
				$this->spawnWave($raid);
			}
			return true;
		}
		$this->showBar($raid, $players, "Raid", $raid->waveHealth > 0 ? min(1.0, $health / $raid->waveHealth) : 0.0);
		return true;
	}

	private function spawnWave(Raid $raid) : void{
		++$raid->wave;
		$row = self::WAVES[min(8, $raid->wave)];
		$angle = mt_rand(0, 359) / 180 * M_PI;
		$distance = mt_rand(24, 32);
		$sx = (int) ($raid->center->x + cos($angle) * $distance);
		$sz = (int) ($raid->center->z + sin($angle) * $distance);
		$raid->waveHealth = 0.0;
		foreach($row as $index => $count){
			if($this->world->getDifficulty() === World::DIFFICULTY_HARD && $index < 2){
				++$count; // vanilla: more pillagers and vindicators on Hard
			}
			for($i = 0; $i < $count; ++$i){
				$x = $sx + mt_rand(-3, 3);
				$z = $sz + mt_rand(-3, 3);
				if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
					$this->world->loadChunk($x >> 4, $z >> 4);
				}
				$y = $this->world->getHighestBlockAt($x, $z) ?? $raid->center->getFloorY();
				$class = self::CLASSES[$index];
				$mob = new $class(Location::fromObject(new Vector3($x + 0.5, $y + 1, $z + 0.5), $this->world, mt_rand(0, 359), 0));
				if(method_exists($mob, "joinRaid")){
					$mob->joinRaid($raid->id, $raid->center);
				}
				$mob->spawnToAll();
				$raid->raiders[] = $mob->getId();
				$raid->waveHealth += $mob->getMaxHealth();
			}
		}
		$pk = LevelSoundEventPacket::nonActorSound(LevelSoundEvent::RAID_HORN, new Vector3($sx, $raid->center->y, $sz), true);
		foreach($this->playersNear($raid) as $player){
			$player->getNetworkSession()->sendDataPacket($pk);
		}
	}

	private function finish(Raid $raid, bool $won, array $players) : void{
		$raid->state = Raid::STATE_OVER;
		$raid->won = $won;
		$raid->endTicks = 200;
		if($won){
			foreach($players as $player){
				$player->getEffects()->add(new EffectInstance(ExtraEffects::get("village_hero"), 48000, $raid->omenLevel));
			}
		}
	}

	/** @param Player[] $players */
	private function showBar(Raid $raid, array $players, string $title, float $percent) : void{
		$visible = [];
		foreach($players as $player){
			$id = $player->getId();
			$visible[$id] = true;
			$session = $player->getNetworkSession();
			if(!isset($raid->barPlayers[$id])){
				$raid->barPlayers[$id] = $player;
				$session->sendDataPacket(BossEventPacket::show($id, $title, $percent, BossBarColor::RED));
			}elseif($raid->ticks % 10 === 0){
				$session->sendDataPacket(BossEventPacket::title($id, $title));
				$session->sendDataPacket(BossEventPacket::healthPercent($id, $percent));
			}
		}
		foreach($raid->barPlayers as $id => $player){
			if(!isset($visible[$id])){
				if($player->isConnected()){
					$player->getNetworkSession()->sendDataPacket(BossEventPacket::hide($id));
				}
				unset($raid->barPlayers[$id]);
			}
		}
	}

	private function hideBar(Raid $raid) : void{
		foreach($raid->barPlayers as $id => $player){
			if($player->isConnected()){
				$player->getNetworkSession()->sendDataPacket(BossEventPacket::hide($id));
			}
		}
		$raid->barPlayers = [];
	}
}
