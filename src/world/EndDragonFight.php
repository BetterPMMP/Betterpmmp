<?php

/*
 * BetterPMMP - vanilla ender dragon fight.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\ExtraVanillaBlocks;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\BossBarTracker;
use pocketmine\entity\EnderDragonMob;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\the_end\TheEnd;
use pocketmine\world\generator\vanilla\VanillaEnd;
use function array_map;
use function array_values;
use function count;
use function cos;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;

use function mt_rand;
use function sin;
use function usort;
use function strtolower;

/**
 * Runs the dragon fight of an End world like vanilla's EndDragonFight:
 *
 * - When a player is in the End and the dragon was never killed, the centre
 *   of the island is kept loaded, the ten end crystals are placed once on the
 *   obsidian pillars and exactly one dragon is kept alive.
 * - The boss bar is shown to every player near the island.
 * - When the dragon dies, the exit portal opens, the first kill leaves the
 *   dragon egg on top of the portal pillar, and an end gateway appears.
 *
 * The state is stored in the world folder, so a slain dragon stays slain.
 */
final class EndDragonFight implements ChunkLoader{

	private const STATE_FILE = "betterpmmp_dragon_fight.json";
	private const KEEP_LOADED_RADIUS = 5;
	private const ARENA_RADIUS = 192;

	/** @var self[] world id => fight */
	private static array $fights = [];

	private bool $killed = false;
	private bool $previouslyKilled = false;
	private bool $crystalsPlaced = false;
	/** @var int[] indexes of gateways already created */
	private array $gateways = [];
	private bool $loadersRegistered = false;
	private ?BossBarTracker $bossBar = null;
	private ?EnderDragonMob $bossBarDragon = null;
	private bool $populationRequested = false;

	private function __construct(private World $world){
		$this->load();
	}

	public static function isEndWorld(World $world) : bool{
		return $world->getDimensionId() === DimensionIds::THE_END;
	}

	public static function get(World $world) : self{
		return self::$fights[$world->getId()] ??= new self($world);
	}

	public static function forget(World $world) : void{
		unset(self::$fights[$world->getId()]);
	}

	public function isDragonKilled() : bool{
		return $this->killed;
	}

	public function wasPreviouslyKilled() : bool{
		return $this->previouslyKilled;
	}

	private function stateFile() : string{
		return $this->world->getProvider()->getPath() . "/" . self::STATE_FILE;
	}

	private function load() : void{
		$file = $this->stateFile();
		if(!file_exists($file)){
			return;
		}
		$data = json_decode((string) file_get_contents($file), true);
		if(!is_array($data)){
			return;
		}
		$this->killed = (bool) ($data["killed"] ?? false);
		$this->previouslyKilled = (bool) ($data["previouslyKilled"] ?? false);
		$this->crystalsPlaced = (bool) ($data["crystalsPlaced"] ?? false);
		$this->gateways = array_values(array_map('intval', (array) ($data["gateways"] ?? [])));
	}

	private function save() : void{
		@file_put_contents($this->stateFile(), json_encode([
			"killed" => $this->killed,
			"previouslyKilled" => $this->previouslyKilled,
			"crystalsPlaced" => $this->crystalsPlaced,
			"gateways" => $this->gateways,
		]));
	}

	/**
	 * Pillars of this End: [x, z, height above 65, caged].
	 * @return list<array{int, int, int, bool}>
	 */
	public function getPillars() : array{
		$pillars = [];
		$vanilla = false;
		try{
			$vanilla = \pocketmine\world\generator\vanilla\structure\WorldStructures::generatorClass($this->world) === VanillaEnd::class;
		}catch(\Throwable){
		}
		foreach($vanilla ? VanillaEnd::getPillars() : TheEnd::getPillars() as $pillar){
			$pillars[] = [$pillar[0], $pillar[1], $pillar[2], (bool) $pillar[3]];
		}
		return $pillars;
	}

	/** @return EnderDragonMob[] */
	private function dragons() : array{
		$dragons = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof EnderDragonMob && !$entity->isClosed() && $entity->isAlive()){
				$dragons[] = $entity;
			}
		}
		return $dragons;
	}

	/** @return EndCrystal[] */
	public function getPillarCrystals() : array{
		$crystals = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof EndCrystal && !$entity->isClosed() && $entity->isAlive()){
				$pos = $entity->getPosition();
				if($pos->x * $pos->x + $pos->z * $pos->z < 70 * 70){
					$crystals[] = $entity;
				}
			}
		}
		return $crystals;
	}

	private function playersInArena() : int{
		$count = 0;
		foreach($this->world->getPlayers() as $player){
			$pos = $player->getPosition();
			if($player->isConnected() && $pos->x * $pos->x + $pos->z * $pos->z <= self::ARENA_RADIUS * self::ARENA_RADIUS){
				++$count;
			}
		}
		return $count;
	}

	private function registerLoaders() : void{
		if($this->loadersRegistered){
			return;
		}
		$this->loadersRegistered = true;
		for($x = -self::KEEP_LOADED_RADIUS; $x <= self::KEEP_LOADED_RADIUS; ++$x){
			for($z = -self::KEEP_LOADED_RADIUS; $z <= self::KEEP_LOADED_RADIUS; ++$z){
				$this->world->registerChunkLoader($this, $x, $z, true);
			}
		}
	}

	private function unregisterLoaders() : void{
		if(!$this->loadersRegistered){
			return;
		}
		$this->loadersRegistered = false;
		for($x = -self::KEEP_LOADED_RADIUS; $x <= self::KEEP_LOADED_RADIUS; ++$x){
			for($z = -self::KEEP_LOADED_RADIUS; $z <= self::KEEP_LOADED_RADIUS; ++$z){
				$this->world->unregisterChunkLoader($this, $x, $z);
			}
		}
	}

	/** Called about twice a second by the world manager. */
	public function tick() : void{
		if(!$this->world->isLoaded()){
			return;
		}
		if($this->playersInArena() === 0){
			$this->unregisterLoaders();
			$this->bossBar?->hideAll();
			return;
		}
		if($this->killed){
			$this->unregisterLoaders();
			$this->bossBar?->hideAll();
			$this->bossBar = null;
			return;
		}
		$this->registerLoaders();
		if(!$this->populationRequested){
			$this->populationRequested = true;
			foreach($this->getPillars() as [$x, $z]){
				$this->world->requestChunkPopulation($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, null);
			}
			$this->world->requestChunkPopulation(0, 0, null);
		}

		if(!$this->crystalsPlaced){
			$ready = $this->world->isChunkPopulated(0, 0);
			foreach($this->getPillars() as [$x, $z]){
				$ready = $ready && $this->world->isChunkPopulated($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			}
			if(!$ready){
				return;
			}
			foreach($this->getPillars() as [$x, $z, $height]){
				$crystal = new EndCrystal(new Location($x + 0.5, 66 + $height, $z + 0.5, $this->world, 0.0, 0.0));
				$crystal->setShowBase(true);
				$crystal->spawnToAll();
			}
			$this->crystalsPlaced = true;
			$this->save();
		}

		$dragons = $this->dragons();
		if(count($dragons) === 0){
			if(!$this->world->isChunkLoaded(0, 0)){
				return;
			}
			$dragon = new EnderDragonMob(new Location(0.5, 128.0, 0.5, $this->world, 0.0, 0.0));
			$dragon->spawnToAll();
			$dragons = [$dragon];
		}
		// a world from an older build may contain stray dragons: keep the one closest to the centre
		if(count($dragons) > 1){
			usort($dragons, fn(EnderDragonMob $a, EnderDragonMob $b) => ($a->getPosition()->x ** 2 + $a->getPosition()->z ** 2) <=> ($b->getPosition()->x ** 2 + $b->getPosition()->z ** 2));
			for($i = 1; $i < count($dragons); ++$i){
				$dragons[$i]->flagForDespawn();
			}
		}
		$dragon = $dragons[0];
		if($this->bossBarDragon !== $dragon){
			$this->bossBar?->hideAll();
			$this->bossBar = new BossBarTracker($dragon, "Enderdrache", BossBarColor::PINK, BossBarOverlay::PROGRESS, self::ARENA_RADIUS);
			$this->bossBarDragon = $dragon;
		}
		$this->bossBar?->sync();
	}

	/** Called by the dragon when its death animation ends. */
	public function onDragonKilled(EnderDragonMob $dragon) : void{
		$this->bossBar?->hideAll();
		$this->bossBar = null;
		$this->bossBarDragon = null;
		$first = !$this->previouslyKilled;
		$this->killed = true;
		$this->previouslyKilled = true;
		$this->openExitPortal($first);
		$this->spawnGateway();
		$this->save();
	}

	/** Vanilla: the exit portal opens inside the bedrock bowl; the first kill leaves the egg. */
	private function openExitPortal(bool $placeEgg) : void{
		$world = $this->world;
		$portal = VanillaBlocks::END_PORTAL();
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				$d = $dx * $dx + $dz * $dz;
				if($d >= 5 || ($dx === 0 && $dz === 0)){
					continue;
				}
				$world->setBlockAt($dx, 65, $dz, $portal);
			}
		}
		if($placeEgg){
			$world->setBlockAt(0, 69, 0, VanillaBlocks::DRAGON_EGG());
		}
	}

	/** One of the 20 end gateways in a ring at radius 96, y 75, like vanilla. */
	private function spawnGateway() : void{
		$free = [];
		for($i = 0; $i < 20; ++$i){
			if(!in_array($i, $this->gateways, true)){
				$free[] = $i;
			}
		}
		if(count($free) === 0){
			return;
		}
		$index = $free[mt_rand(0, count($free) - 1)];
		$this->gateways[] = $index;
		[$x, $z] = self::gatewayPosition($index);
		$y = 75;
		$world = $this->world;
		$world->requestChunkPopulation($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			static function() use ($world, $x, $y, $z) : void{
				if(!$world->isLoaded()){
					return;
				}
				$gateway = ExtraVanillaBlocks::get("minecraft:end_gateway");
				$bedrock = VanillaBlocks::BEDROCK();
				foreach([-1, 1] as $dy){
					$world->setBlockAt($x, $y + $dy, $z, $bedrock);
					foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ox, $oz]){
						$world->setBlockAt($x + $ox, $y + $dy, $z + $oz, $bedrock);
					}
				}
				$world->setBlockAt($x, $y, $z, $gateway ?? VanillaBlocks::END_PORTAL());
			},
			static function() : void{}
		);
	}

	/** @return array{int, int} */
	public static function gatewayPosition(int $index) : array{
		$angle = 2.0 * (-M_PI + 0.15707963267948966 * $index);
		return [(int) floor(96.0 * cos($angle)), (int) floor(96.0 * sin($angle))];
	}

	/** Whether (x, z) is the position of one of the main-island gateways. */
	public static function isGatewayColumn(int $x, int $z) : bool{
		for($i = 0; $i < 20; ++$i){
			[$gx, $gz] = self::gatewayPosition($i);
			if($gx === $x && $gz === $z){
				return true;
			}
		}
		return false;
	}
}
