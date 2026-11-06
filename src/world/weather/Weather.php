<?php

/*
 * BetterPMMP - vanilla weather: rain, snow, thunderstorms, lightning.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\weather;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\SnowLayer;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function count;
use function file_get_contents;
use function floor;
use function is_array;
use function json_decode;
use function max;
use function min;
use function mt_rand;

/**
 * The vanilla weather cycle of the overworld:
 *  - clear for 0.5-7.5 days, then rain for 0.5-1 day; independently a
 *    thunder timer (off 0.5-7.5 days, on 3-13 minutes) - thunder only shows
 *    while it rains
 *  - rain in warm biomes, snow in cold ones (temperature < 0.15), nothing in
 *    deserts, savannas, badlands
 *  - in a thunderstorm each loaded chunk has a 1 in 100 000 chance per tick
 *    of a lightning strike (it prefers mobs standing there)
 *  - snow piles up and water freezes in cold biomes, rain puts out burning
 *    mobs, the sky gets darker (hostile mobs spawn by day in thunderstorms)
 * Saved in level.dat like Bedrock (rainTime, rainLevel, lightningTime, lightningLevel).
 */
final class Weather{

	private const CHUNK_RADIUS = 6;

	private int $rainTime;
	private bool $raining;
	private float $rainLevel;
	private int $thunderTime;
	private bool $thundering;
	private float $thunderLevel;

	/** @var array<int, array{float, bool}>|null biome id => [temperature, has precipitation] */
	private static ?array $biomes = null;

	public function __construct(private World $world){
		$data = $world->getProvider()->getWorldData();
		$this->rainTime = $data->getRainTime();
		$this->rainLevel = $data->getRainLevel();
		$this->raining = $this->rainLevel > 0;
		$this->thunderTime = $data->getLightningTime();
		$this->thunderLevel = $data->getLightningLevel();
		$this->thundering = $this->thunderLevel > 0;
		if($this->rainTime <= 0){
			$this->rainTime = mt_rand(12000, 180000);
		}
		if($this->thunderTime <= 0){
			$this->thunderTime = mt_rand(12000, 180000);
		}
	}

	public function hasWeather() : bool{
		return $this->world->getDimensionId() === DimensionIds::OVERWORLD;
	}

	public function isRaining() : bool{ return $this->hasWeather() && $this->raining; }
	public function isThundering() : bool{ return $this->isRaining() && $this->thundering; }
	public function getRainLevel() : float{ return $this->hasWeather() ? $this->rainLevel : 0.0; }
	public function getThunderLevel() : float{ return $this->hasWeather() ? $this->thunderLevel * $this->rainLevel : 0.0; }
	public function getRainTime() : int{ return $this->rainTime; }
	public function getThunderTime() : int{ return $this->thunderTime; }

	public function save() : void{
		$data = $this->world->getProvider()->getWorldData();
		$data->setRainTime($this->rainTime);
		$data->setRainLevel($this->raining ? 1.0 : 0.0);
		$data->setLightningTime($this->thunderTime);
		$data->setLightningLevel($this->thundering ? 1.0 : 0.0);
	}

	/** Sets clear weather, rain or a thunderstorm for the given ticks (like /weather). */
	public function set(bool $rain, bool $thunder, ?int $duration = null) : void{
		$duration ??= $rain ? ($thunder ? mt_rand(3600, 15600) : mt_rand(12000, 24000)) : mt_rand(12000, 180000);
		$this->raining = $rain;
		$this->thundering = $rain && $thunder;
		$this->rainTime = $duration;
		$this->thunderTime = $this->thundering ? $duration : mt_rand(12000, 180000);
		$this->rainLevel = $rain ? 1.0 : 0.0;
		$this->thunderLevel = $this->thundering ? 1.0 : 0.0;
		$this->broadcast();
	}

	/** Sleeping through the night or a storm clears the weather (vanilla). */
	public function clearAfterSleep() : void{
		if($this->raining || $this->thundering){
			$this->set(false, false);
		}
	}

	public function tick() : void{
		if(!$this->hasWeather()){
			return;
		}
		$wasRaining = $this->raining;
		$wasThundering = $this->thundering;
		if(--$this->thunderTime <= 0){
			$this->thundering = !$this->thundering;
			$this->thunderTime = $this->thundering ? mt_rand(3600, 15600) : mt_rand(12000, 180000);
		}
		if(--$this->rainTime <= 0){
			$this->raining = !$this->raining;
			$this->rainTime = $this->raining ? mt_rand(12000, 24000) : mt_rand(12000, 180000);
		}
		$this->rainLevel = max(0.0, min(1.0, $this->rainLevel + ($this->raining ? 0.01 : -0.01)));
		$this->thunderLevel = max(0.0, min(1.0, $this->thunderLevel + ($this->thundering ? 0.01 : -0.01)));
		if($wasRaining !== $this->raining || $wasThundering !== $this->thundering){
			$this->broadcast();
		}
		if($this->rainLevel <= 0 && $this->world->getServer()->getTick() % 4 !== 0){
			return; // without rain only freezing water is left to do, a quarter as often is plenty
		}
		$this->tickChunks();
	}

	/** @return LevelEventPacket[] */
	private function packets() : array{
		return [
			LevelEventPacket::create($this->raining ? LevelEvent::START_RAIN : LevelEvent::STOP_RAIN, $this->raining ? 65535 : 0, null),
			LevelEventPacket::create($this->isThundering() ? LevelEvent::START_THUNDER : LevelEvent::STOP_THUNDER, $this->isThundering() ? 65535 : 0, null),
		];
	}

	private function broadcast() : void{
		foreach($this->world->getPlayers() as $player){
			$this->sendTo($player);
		}
	}

	public function sendTo(Player $player) : void{
		if(!$this->hasWeather()){
			foreach([LevelEventPacket::create(LevelEvent::STOP_RAIN, 0, null), LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null)] as $pk){
				$player->getNetworkSession()->sendDataPacket($pk);
			}
			return;
		}
		foreach($this->packets() as $pk){
			$player->getNetworkSession()->sendDataPacket($pk);
		}
	}

	/** @return array{float, bool} temperature, has precipitation */
	public static function biomeClimate(int $biomeId) : array{
		if(self::$biomes === null){
			self::$biomes = [];
			$defs = json_decode((string) file_get_contents(BedrockDataFiles::BIOME_DEFINITIONS_JSON), true);
			$ids = [];
			foreach(LegacyBiomeIdToStringIdMap::getInstance()->getLegacyToStringMap() as $legacy => $name){
				$ids[$name] = $legacy;
				$ids["minecraft:" . $name] = $legacy;
			}
			if(is_array($defs)){
				foreach($defs as $name => $def){
					$id = $ids[$name] ?? null;
					if($id !== null && is_array($def)){
						self::$biomes[$id] = [(float) ($def["temperature"] ?? 0.5), (bool) ($def["rain"] ?? true)];
					}
				}
			}
		}
		return self::$biomes[$biomeId] ?? [0.5, true];
	}

	/** Whether rain (not snow) falls on this column right now. */
	public function isRainingAt(int $x, int $y, int $z) : bool{
		if(!$this->isRaining() || !$this->world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
			return false;
		}
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top !== null && $top >= $y){
			return false;
		}
		[$temperature, $precipitation] = self::biomeClimate($this->world->getBiomeId($x, $y, $z));
		return $precipitation && $temperature >= 0.15;
	}

	/** Whether it rains or snows on this column right now. */
	public function isPrecipitatingAt(int $x, int $y, int $z) : bool{
		if(!$this->isRaining() || !$this->world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
			return false;
		}
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top !== null && $top >= $y){
			return false;
		}
		return self::biomeClimate($this->world->getBiomeId($x, $y, $z))[1];
	}

	private function tickChunks() : void{
		$chunks = [];
		foreach($this->world->getPlayers() as $player){
			$cx = $player->getPosition()->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$cz = $player->getPosition()->getFloorZ() >> Chunk::COORD_BIT_SIZE;
			for($x = $cx - self::CHUNK_RADIUS; $x <= $cx + self::CHUNK_RADIUS; ++$x){
				for($z = $cz - self::CHUNK_RADIUS; $z <= $cz + self::CHUNK_RADIUS; ++$z){
					if($this->world->isChunkLoaded($x, $z)){
						$chunks[World::chunkHash($x, $z)] = [$x, $z];
					}
				}
			}
		}
		if(count($chunks) === 0){
			return;
		}
		$thunder = $this->isThundering();
		foreach($chunks as [$cx, $cz]){
			if($thunder && mt_rand(0, 99999) === 0){
				$this->strikeInChunk($cx, $cz);
			}
			if(mt_rand(0, 15) === 0){
				$this->precipitate(($cx << 4) + mt_rand(0, 15), ($cz << 4) + mt_rand(0, 15));
			}
		}
		// rain puts out burning mobs
		if($this->rainLevel > 0.2 && $this->world->getServer()->getTick() % 10 === 0){
			foreach($this->world->getEntities() as $entity){
				if($entity->isOnFire() && $entity instanceof Living){
					$p = $entity->getPosition();
					if($this->isRainingAt($p->getFloorX(), (int) floor($p->y + $entity->getSize()->getHeight()), $p->getFloorZ())){
						$entity->extinguish();
					}
				}
			}
		}
	}

	/** Snow layers and ice in cold biomes, like vanilla's precipitation tick. */
	private function precipitate(int $x, int $z) : void{
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top === null){
			return;
		}
		$block = $this->world->getBlockAt($x, $top, $z);
		[$temperature, $precipitation] = self::biomeClimate($this->world->getBiomeId($x, $top, $z));
		if(!$precipitation || $temperature >= 0.15){
			return;
		}
		$lowLight = $this->world->getBlockLightAt($x, $top + 1, $z) < 10;
		if($block instanceof Water && $block->isSource() && $lowLight){
			$this->world->setBlockAt($x, $top, $z, VanillaBlocks::ICE());
			return;
		}
		if(!$this->isRaining() || !$lowLight){
			return;
		}
		if($block instanceof SnowLayer){
			if(mt_rand(0, 1) === 0 && $block->getLayers() < SnowLayer::MAX_LAYERS - 7){ // vanilla piles up one layer by default
				$this->world->setBlockAt($x, $top, $z, $block->setLayers($block->getLayers() + 1));
			}
			return;
		}
		$above = $this->world->getBlockAt($x, $top + 1, $z);
		if($above->getTypeId() === BlockTypeIds::AIR && $block->isSolid() && $block->isFullCube() && !$block instanceof \pocketmine\block\Ice){
			$this->world->setBlockAt($x, $top + 1, $z, VanillaBlocks::SNOW_LAYER());
		}
	}

	private function strikeInChunk(int $cx, int $cz) : void{
		$x = ($cx << 4) + mt_rand(0, 15);
		$z = ($cz << 4) + mt_rand(0, 15);
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top === null){
			return;
		}
		$pos = new Vector3($x + 0.5, $top + 1, $z + 0.5);
		// a mob standing near the column is hit instead
		$box = new AxisAlignedBB($x - 3, $top - 3, $z - 3, $x + 4, $top + 8, $z + 4);
		$targets = [];
		foreach($this->world->getNearbyEntities($box) as $entity){
			if($entity instanceof Living && $entity->isAlive() && !($entity instanceof Player && $entity->isSpectator())){
				$targets[] = $entity;
			}
		}
		if(count($targets) > 0){
			$pos = $targets[mt_rand(0, count($targets) - 1)]->getPosition()->asVector3();
		}
		if(!$this->isRainingAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			return;
		}
		$rod = $this->findLightningRod($pos);
		if($rod !== null){
			$pos = $rod;
		}
		self::strike($this->world, $pos);
	}

	/** @var array<int, true>|null state ids of lightning rods */
	private static ?array $rodStates = null;

	/**
	 * Vanilla: a lightning rod open to the sky within 128 blocks takes natural strikes.
	 * Only sub chunks whose palette contains a rod are searched, so this stays cheap.
	 */
	private function findLightningRod(Vector3 $near) : ?Vector3{
		if(self::$rodStates === null){
			self::$rodStates = [];
			foreach(\pocketmine\block\RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $state){
				if($state instanceof \pocketmine\block\LightningRod){
					self::$rodStates[$stateId] = true;
				}
			}
		}
		$best = null;
		$bestDistance = 128 * 128;
		$cx = $near->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$cz = $near->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		for($x = $cx - 8; $x <= $cx + 8; ++$x){
			for($z = $cz - 8; $z <= $cz + 8; ++$z){
				$chunk = $this->world->isChunkLoaded($x, $z) ? $this->world->getChunk($x, $z) : null;
				if($chunk === null){
					continue;
				}
				foreach($chunk->getSubChunks() as $sy => $sub){
					$found = false;
					foreach($sub->getBlockLayers() as $layer){
						foreach($layer->getPalette() as $id){
							if(isset(self::$rodStates[$id])){
								$found = true;
								break 2;
							}
						}
					}
					if(!$found){
						continue;
					}
					for($bx = 0; $bx < 16; ++$bx){
						for($bz = 0; $bz < 16; ++$bz){
							for($by = 0; $by < 16; ++$by){
								if(!isset(self::$rodStates[$sub->getBlockStateId($bx, $by, $bz)])){
									continue;
								}
								$wx = ($x << 4) + $bx;
								$wy = ($sy << 4) + $by;
								$wz = ($z << 4) + $bz;
								if($this->world->getHighestBlockAt($wx, $wz) !== $wy){
									continue; // must be open to the sky
								}
								$d = ($wx - $near->x) ** 2 + ($wz - $near->z) ** 2;
								if($d < $bestDistance){
									$bestDistance = $d;
									$best = new Vector3($wx + 0.5, $wy + 1, $wz + 0.5);
								}
							}
						}
					}
				}
			}
		}
		return $best;
	}

	/** Summons a lightning bolt (natural or channeling). */
	public static function strike(World $world, Vector3 $pos, ?\pocketmine\entity\Entity $cause = null) : LightningBolt{
		$bolt = new LightningBolt(Location::fromObject($pos, $world, mt_rand(0, 359), 0), $cause);
		$bolt->spawnToAll();
		return $bolt;
	}
}
