<?php

/*
 * BetterPMMP - trial spawners and vaults (trial chambers, 1.21).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\trial;

use pocketmine\block\GenericBedrockBlock;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\sound\VanillaSound;
use pocketmine\world\World;
use function array_filter;
use function array_keys;
use function count;
use function crc32;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function mt_rand;

/**
 * Vanilla trial spawner: wakes up when players come within 14 blocks, spawns
 * 6 mobs (+2 per extra player), at most 2 at a time (+1 per extra player),
 * one every 2 s within 4 blocks. When all are defeated it ejects a reward
 * for every player that took part (trial key, food, arrows, emeralds ...)
 * and cools down for 30 minutes. The mob type is fixed per spawner.
 *
 * Vanilla vault: a player puts in a trial key and gets 1-3 items from the
 * vault loot table - once per player per vault (saved in the world folder).
 */
final class TrialManager{

	private const DETECT_RANGE = 14;
	private const COOLDOWN = 36000;

	/** @var array<int, true>|null */
	private static ?array $spawnerStates = null;

	/** @var array<string, array{Vector3, list<int>, int, int, array<string, true>, int, int}> pos => [pos, living mob ids, spawned, total, players, phase ticks, next spawn] */
	private array $active = [];
	/** @var array<string, int> pos => world tick when the cooldown ends */
	private array $cooldowns = [];
	/** @var array<string, array<string, true>> vault pos => rewarded player uuids */
	private array $vaults = [];
	private int $scanTicks = 0;
	private bool $dirty = false;

	public function __construct(private World $world){
		$file = $this->file();
		if(file_exists($file)){
			$data = json_decode((string) file_get_contents($file), true);
			if(is_array($data)){
				foreach(($data["cooldowns"] ?? []) as $key => $until){
					$this->cooldowns[(string) $key] = (int) $until;
				}
				foreach(($data["vaults"] ?? []) as $key => $players){
					foreach(is_array($players) ? $players : [] as $uuid){
						$this->vaults[(string) $key][(string) $uuid] = true;
					}
				}
			}
		}
	}

	private function file() : string{
		return $this->world->getProvider()->getPath() . "/betterpmmp_trials.json";
	}

	public function save() : void{
		if(!$this->dirty){
			return;
		}
		$vaults = [];
		foreach($this->vaults as $key => $players){
			$vaults[$key] = array_keys($players);
		}
		@file_put_contents($this->file(), json_encode(["cooldowns" => $this->cooldowns, "vaults" => $vaults]));
		$this->dirty = false;
	}

	private static function key(Vector3 $pos) : string{
		return $pos->getFloorX() . ":" . $pos->getFloorY() . ":" . $pos->getFloorZ();
	}

	private static function isSpawnerState(int $stateId) : bool{
		if(self::$spawnerStates === null){
			self::$spawnerStates = [];
			foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $id => $state){
				if($state instanceof GenericBedrockBlock && $state->getBedrockName() === "minecraft:trial_spawner"){
					self::$spawnerStates[$id] = true;
				}
			}
		}
		return isset(self::$spawnerStates[$stateId]);
	}

	public function tick() : void{
		if(++$this->scanTicks >= 40){
			$this->scanTicks = 0;
			$this->discover();
			if($this->world->getServer()->getTick() % 1200 === 0){
				$this->save();
			}
		}
		foreach($this->active as $key => $_){
			$this->tickSpawner($key);
		}
	}

	/** Finds trial spawners in the chunks around players (palette check first, so it is cheap). */
	private function discover() : void{
		$seen = [];
		foreach($this->world->getPlayers() as $player){
			$cx = $player->getPosition()->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$cz = $player->getPosition()->getFloorZ() >> Chunk::COORD_BIT_SIZE;
			for($x = $cx - 1; $x <= $cx + 1; ++$x){
				for($z = $cz - 1; $z <= $cz + 1; ++$z){
					if(isset($seen[World::chunkHash($x, $z)]) || !$this->world->isChunkLoaded($x, $z) || ($chunk = $this->world->getChunk($x, $z)) === null){
						continue;
					}
					$seen[World::chunkHash($x, $z)] = true;
					foreach($chunk->getSubChunks() as $sy => $sub){
						$has = false;
						foreach($sub->getBlockLayers() as $layer){
							foreach($layer->getPalette() as $id){
								if(self::isSpawnerState($id)){
									$has = true;
									break 2;
								}
							}
						}
						if(!$has){
							continue;
						}
						for($bx = 0; $bx < 16; ++$bx){
							for($by = 0; $by < 16; ++$by){
								for($bz = 0; $bz < 16; ++$bz){
									if(self::isSpawnerState($sub->getBlockStateId($bx, $by, $bz))){
										$pos = new Vector3(($x << 4) + $bx, ($sy << 4) + $by, ($z << 4) + $bz);
										$this->active[self::key($pos)] ??= [$pos, [], 0, 0, [], 0, 0];
									}
								}
							}
						}
					}
				}
			}
		}
	}

	private function setSpawnerState(Vector3 $pos, int $state) : void{
		$block = $this->world->getBlock($pos);
		if($block instanceof GenericBedrockBlock && $block->getBedrockName() === "minecraft:trial_spawner"){
			try{
				$this->world->setBlock($pos, $block->withProperties(["trial_spawner_state" => $state]));
			}catch(\InvalidArgumentException){
			}
		}
	}

	/** @return Player[] */
	private function playersNear(Vector3 $pos) : array{
		$players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator() && !$player->isCreative() && $player->getPosition()->distanceSquared($pos->add(0.5, 0.5, 0.5)) <= self::DETECT_RANGE ** 2){
				$players[] = $player;
			}
		}
		return $players;
	}

	private function tickSpawner(string $key) : void{
		[$pos, $mobs, $spawned, $total, $participants, $phaseTicks, $nextSpawn] = $this->active[$key];
		$block = $this->world->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4) ? $this->world->getBlock($pos) : null;
		if(!$block instanceof GenericBedrockBlock || $block->getBedrockName() !== "minecraft:trial_spawner"){
			unset($this->active[$key]);
			return;
		}
		$tick = $this->world->getServer()->getTick();
		$time = $this->world->getTime();
		if(isset($this->cooldowns[$key])){
			if($time < $this->cooldowns[$key]){
				return;
			}
			unset($this->cooldowns[$key]);
			$this->dirty = true;
			$this->setSpawnerState($pos, 1);
		}
		$players = $this->playersNear($pos);
		foreach($players as $player){
			if(!isset($participants[$player->getUniqueId()->toString()])){
				$participants[$player->getUniqueId()->toString()] = true;
				if($total > 0){
					$total += 2; // every extra player adds 2 mobs
				}
			}
		}
		// living mobs
		$mobs = array_filter($mobs, fn(int $id) => ($e = $this->world->getEntity($id)) instanceof Living && $e->isAlive());

		if($total === 0){
			if(count($players) === 0){
				$this->active[$key] = [$pos, [], 0, 0, [], 0, 0];
				return;
			}
			// wake up
			$total = 6 + 2 * (count($participants) - 1);
			$this->setSpawnerState($pos, 2);
			$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::TRIAL_SPAWNER_DETECT_PLAYER));
			$this->world->broadcastPacketToViewers($pos, LevelEventPacket::create(LevelEvent::PARTICLE_TRIAL_SPAWNER_DETECTION, count($players), $pos->add(0.5, 0.5, 0.5)));
			$nextSpawn = $tick + 20;
		}

		if($spawned < $total){
			$maxAlive = 2 + (count($participants) - 1);
			if(count($mobs) < $maxAlive && $tick >= $nextSpawn){
				$id = $this->spawnMob($pos, $key);
				if($id !== null){
					$mobs[] = $id;
					++$spawned;
				}
				$nextSpawn = $tick + 40;
			}
			if(count($players) === 0 && count($mobs) === 0){
				// everybody left: reset
				$this->setSpawnerState($pos, 1);
				$this->active[$key] = [$pos, [], 0, 0, [], 0, 0];
				return;
			}
			$this->active[$key] = [$pos, $mobs, $spawned, $total, $participants, 0, $nextSpawn];
			return;
		}
		if(count($mobs) > 0){
			$this->active[$key] = [$pos, $mobs, $spawned, $total, $participants, 0, $nextSpawn];
			return;
		}
		// all defeated: wait, eject rewards, cool down
		++$phaseTicks;
		if($phaseTicks === 1){
			$this->setSpawnerState($pos, 3);
		}elseif($phaseTicks === 40){
			$this->setSpawnerState($pos, 4);
			$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::TRIAL_SPAWNER_OPEN_SHUTTER));
			foreach(array_keys($participants) as $_){
				foreach(self::spawnerReward() as $item){
					$this->world->dropItem($pos->add(0.5, 1.2, 0.5), $item, new Vector3(mt_rand(-10, 10) / 100, 0.3, mt_rand(-10, 10) / 100));
				}
				$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::TRIAL_SPAWNER_EJECT_ITEM));
			}
		}elseif($phaseTicks >= 60){
			$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::TRIAL_SPAWNER_CLOSE_SHUTTER));
			$this->setSpawnerState($pos, 5);
			$this->cooldowns[$key] = $time + self::COOLDOWN;
			$this->dirty = true;
			$this->active[$key] = [$pos, [], 0, 0, [], 0, 0];
			return;
		}
		$this->active[$key] = [$pos, $mobs, $spawned, $total, $participants, $phaseTicks, $nextSpawn];
	}

	/** @return list<class-string<Living>> */
	private static function mobPool() : array{
		return [
			\pocketmine\entity\Zombie::class, \pocketmine\entity\HuskMob::class, \pocketmine\entity\SkeletonMob::class,
			\pocketmine\entity\StrayMob::class, \pocketmine\entity\SpiderMob::class, \pocketmine\entity\CaveSpiderMob::class,
			\pocketmine\entity\SlimeMob::class, \pocketmine\entity\SilverfishMob::class, \pocketmine\entity\BreezeMob::class,
			\pocketmine\entity\BoggedMob::class,
		];
	}

	/** The mob type of a spawner, fixed by its position (like the spawner's configuration in vanilla). */
	public static function mobFor(string $key) : string{
		$pool = self::mobPool();
		return $pool[crc32($key) % count($pool)];
	}

	private function spawnMob(Vector3 $pos, string $key) : ?int{
		$class = self::mobFor($key);
		for($attempt = 0; $attempt < 10; ++$attempt){
			$x = $pos->getFloorX() + mt_rand(-4, 4);
			$z = $pos->getFloorZ() + mt_rand(-4, 4);
			for($dy = -1; $dy <= 1; ++$dy){
				$y = $pos->getFloorY() + $dy;
				if($this->world->getBlockAt($x, $y - 1, $z)->isSolid() && !$this->world->getBlockAt($x, $y, $z)->isSolid() && !$this->world->getBlockAt($x, $y + 1, $z)->isSolid()){
					$mob = new $class(Location::fromObject(new Vector3($x + 0.5, $y, $z + 0.5), $this->world, mt_rand(0, 359), 0));
					if($mob instanceof \pocketmine\entity\Zombie){
						$mob->setBaby(false);
					}
					$mob->spawnToAll();
					$this->world->broadcastPacketToViewers($mob->getPosition(), LevelEventPacket::create(LevelEvent::PARTICLE_TRIAL_SPAWNER_SPAWNING, 0, $mob->getPosition()->add(0, 0.5, 0)));
					$this->world->addSound($mob->getPosition(), new VanillaSound(LevelSoundEvent::TRIAL_SPAWNER_SPAWN_MOB));
					return $mob->getId();
				}
			}
		}
		return null;
	}

	/** @return Item[] vanilla trial_chamber/consumables + key */
	private static function spawnerReward() : array{
		$items = [];
		$key = ExtraVanillaItems::get("minecraft:trial_key");
		if($key !== null && mt_rand(0, 99) < 50){
			$items[] = $key;
		}
		$parser = StringToItemParser::getInstance();
		$pick = ["cooked_chicken" => [1, 3], "bread" => [1, 3], "baked_potato" => [1, 3], "arrow" => [4, 8], "potion" => [1, 1], "emerald" => [1, 3], "honey_bottle" => [1, 2], "golden_carrot" => [1, 2]];
		$names = array_keys($pick);
		$name = $names[mt_rand(0, count($names) - 1)];
		$item = $parser->parse($name);
		if($item !== null){
			$items[] = $item->setCount(mt_rand($pick[$name][0], $pick[$name][1]));
		}
		return $items;
	}

	// ------------------------------------------------------------------ vaults

	/** A player uses a vault. Returns whether something happened. */
	public function useVault(Player $player, GenericBedrockBlock $vault, Item $held) : bool{
		$name = $held instanceof \pocketmine\item\GenericBedrockItem ? $held->getBedrockName() : "";
		$pos = $vault->getPosition();
		if($name !== "minecraft:trial_key"){
			return false;
		}
		$key = self::key($pos);
		$uuid = $player->getUniqueId()->toString();
		if(isset($this->vaults[$key][$uuid])){
			$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::VAULT_REJECT_REWARDED_PLAYER));
			return true;
		}
		$this->vaults[$key][$uuid] = true;
		$this->dirty = true;
		if($player->hasFiniteResources()){
			$held->pop();
			$player->getInventory()->setItemInHand($held);
		}
		$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::VAULT_INSERT_ITEM));
		try{
			$this->world->setBlock($pos, $vault->withProperties(["vault_state" => "ejecting"]));
		}catch(\InvalidArgumentException){
		}
		$rewards = self::vaultLoot();
		foreach($rewards as $i => $item){
			$this->world->dropItem($pos->add(0.5, 1.1, 0.5), $item, new Vector3(mt_rand(-5, 5) / 100, 0.25, mt_rand(-5, 5) / 100), 10 + 10 * $i);
		}
		$this->world->addSound($pos, new VanillaSound(LevelSoundEvent::VAULT_EJECT_ITEM));
		$this->world->scheduleDelayedBlockUpdate($pos, 40);
		$this->save();
		return true;
	}

	/** @return Item[] vanilla trial_chambers/reward loot, 1-3 items */
	private static function vaultLoot() : array{
		$parser = StringToItemParser::getInstance();
		$table = [
			["emerald", 2, 4, 5], ["arrow", 2, 8, 4], ["iron_ingot", 1, 2, 4], ["honey_bottle", 1, 2, 4], ["wind_charge", 4, 12, 4],
			["ominous_bottle", 1, 1, 2], ["shield", 1, 1, 3], ["bolt_armor_trim_smithing_template", 1, 1, 1], ["golden_carrot", 1, 2, 3],
			["diamond", 1, 2, 1], ["crossbow", 1, 1, 2], ["iron_axe", 1, 1, 2], ["iron_chestplate", 1, 1, 2], ["golden_apple", 1, 1, 1],
			["enchanted_golden_apple", 1, 1, 0], ["diamond_axe", 1, 1, 1], ["diamond_chestplate", 1, 1, 1], ["trident", 1, 1, 0],
			["music_disc_precipice", 1, 1, 1], ["guster_banner_pattern", 1, 1, 1], ["flow_banner_pattern", 1, 1, 1], ["book", 1, 1, 2],
		];
		$total = 0;
		foreach($table as $row){
			$total += $row[3];
		}
		$items = [];
		for($n = mt_rand(1, 3); $n > 0; --$n){
			$roll = mt_rand(1, max(1, $total));
			foreach($table as [$name, $min, $max, $weight]){
				$roll -= $weight;
				if($roll <= 0){
					$item = $parser->parse($name);
					if($item !== null){
						if($name === "book"){
							$item = \pocketmine\item\VanillaItems::ENCHANTED_BOOK()->addEnchantment(new \pocketmine\item\enchantment\EnchantmentInstance(\pocketmine\item\enchantment\VanillaEnchantments::SHARPNESS(), mt_rand(1, 5)));
						}
						$items[] = $item->setCount(mt_rand($min, $max));
					}
					break;
				}
			}
		}
		return $items;
	}

	public function hasBeenRewarded(Vector3 $pos, Player $player) : bool{
		return isset($this->vaults[self::key($pos)][$player->getUniqueId()->toString()]);
	}
}
