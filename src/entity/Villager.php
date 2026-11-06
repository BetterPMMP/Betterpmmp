<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\Bed;
use pocketmine\block\Bell;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Crops;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\trade\TradeOffer;
use pocketmine\entity\trade\VillagerTrades;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;
use function array_key_exists;
use function array_slice;
use function atan2;
use function max;
use function min;
use function usort;
use function count;
use function rad2deg;
use function mt_rand;

/**
 * BetterPMMP villager (Bedrock villager_v2): professions taken from job site
 * blocks, trading with vanilla trade tables, five levels with experience,
 * restocking at the job site and biome-dependent appearance.
 */
class Villager extends Mob implements Ageable, \pocketmine\entity\trade\Trader{
	//legacy (villager v1) profession ids kept for API compatibility
	public const PROFESSION_FARMER = 0;
	public const PROFESSION_LIBRARIAN = 1;
	public const PROFESSION_PRIEST = 2;
	public const PROFESSION_BLACKSMITH = 3;
	public const PROFESSION_BUTCHER = 4;

	private const TAG_PROFESSION = "Profession"; //TAG_Int (legacy)
	private const TAG_DATA = "BetterPMMPVillager";

	private const JOB_SEARCH_INTERVAL = 200;
	private const JOB_SEARCH_RADIUS = 32; // vanilla villagers find job sites up to 48 blocks away
	private const RESTOCK_INTERVAL = 6000; //twice per Minecraft day

	/**
	 * Claimed job sites: world id => block hash => villager entity id.
	 * @var int[][]
	 */
	private static array $claimedJobSites = [];

	public static function getNetworkTypeId() : string{ return EntityIds::VILLAGER_V2; }

	private bool $baby = false;
	private int $profession = VillagerTrades::UNEMPLOYED;
	private int $level = 0;
	private int $tradeExperience = 0;
	private int $skin = 0;
	private ?Vector3 $jobSite = null;
	/** @var TradeOffer[] */
	private array $offers = [];
	private int $jobTicks = 0;
	private int $restockTicks = 0;
	private int $tradeSeed = 0;

	protected function shouldFleeFromAttacker(EntityDamageByEntityEvent $source) : bool{ return true; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6);
	}

	public function getName() : string{
		return VillagerTrades::NAMES[$this->profession] ?? "Villager";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$data = $nbt->getCompoundTag(self::TAG_DATA);
		if($data !== null){
			$this->profession = $data->getInt("Profession", VillagerTrades::UNEMPLOYED);
			$this->level = $data->getInt("Level", 0);
			$this->tradeExperience = $data->getInt("TradeExperience", 0);
			$this->skin = $data->getInt("Skin", 0);
			$this->tradeSeed = $data->getInt("TradeSeed", mt_rand());
			if($data->getByte("Baby", 0) !== 0){
				$this->setBaby(true);
				$this->growTicks = $data->getInt("GrowTicks", 24000);
			}
			if($data->getTag("JobSiteX") !== null){
				$this->jobSite = new Vector3($data->getInt("JobSiteX"), $data->getInt("JobSiteY"), $data->getInt("JobSiteZ"));
			}
			foreach($data->getListTag("Offers") ?? [] as $offerTag){
				if($offerTag instanceof CompoundTag && ($offer = TradeOffer::fromNbt($offerTag)) !== null){
					$this->offers[] = $offer;
				}
			}
		}else{
			//villagers from older worlds (villager v1) become unemployed and pick a job site
			$this->tradeSeed = mt_rand();
			$this->skin = $this->biomeSkin();
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$data = CompoundTag::create()
			->setInt("Profession", $this->profession)
			->setInt("Level", $this->level)
			->setInt("TradeExperience", $this->tradeExperience)
			->setInt("Skin", $this->skin)
			->setInt("TradeSeed", $this->tradeSeed)
			->setByte("Baby", $this->baby ? 1 : 0)
			->setInt("GrowTicks", $this->growTicks);
		if($this->jobSite !== null){
			$data->setInt("JobSiteX", $this->jobSite->getFloorX());
			$data->setInt("JobSiteY", $this->jobSite->getFloorY());
			$data->setInt("JobSiteZ", $this->jobSite->getFloorZ());
		}
		$offers = new ListTag();
		foreach($this->offers as $i => $offer){
			$offers->push($offer->toNbt($i + 1));
		}
		$data->setTag("Offers", $offers);
		$nbt->setTag(self::TAG_DATA, $data);
		$nbt->setInt(self::TAG_PROFESSION, $this->legacyProfession());
		return $nbt;
	}

	/** Appearance by biome, like vanilla (plains, desert, jungle, savanna, snow, swamp, taiga). */
	private function biomeSkin() : int{
		if(!$this->location->isValid()){
			return 0;
		}
		$pos = $this->location;
		$biome = $pos->getWorld()->getBiomeId($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		return match($biome){
			BiomeIds::DESERT, BiomeIds::MESA, BiomeIds::MESA_BRYCE, BiomeIds::MESA_PLATEAU_STONE => 1,
			BiomeIds::JUNGLE, BiomeIds::JUNGLE_EDGE, BiomeIds::BAMBOO_JUNGLE => 2,
			BiomeIds::SAVANNA, BiomeIds::SAVANNA_PLATEAU, BiomeIds::SAVANNA_MUTATED => 3,
			BiomeIds::ICE_PLAINS, BiomeIds::ICE_PLAINS_SPIKES, BiomeIds::COLD_TAIGA, BiomeIds::GROVE, BiomeIds::SNOWY_SLOPES => 4,
			BiomeIds::SWAMPLAND, BiomeIds::MANGROVE_SWAMP => 5,
			BiomeIds::TAIGA, BiomeIds::MEGA_TAIGA, BiomeIds::REDWOOD_TAIGA_MUTATED => 6,
			default => 0
		};
	}

	private function legacyProfession() : int{
		return match($this->profession){
			VillagerTrades::LIBRARIAN, VillagerTrades::CARTOGRAPHER => self::PROFESSION_LIBRARIAN,
			VillagerTrades::CLERIC => self::PROFESSION_PRIEST,
			VillagerTrades::ARMORER, VillagerTrades::WEAPONSMITH, VillagerTrades::TOOLSMITH => self::PROFESSION_BLACKSMITH,
			VillagerTrades::BUTCHER, VillagerTrades::LEATHERWORKER => self::PROFESSION_BUTCHER,
			default => self::PROFESSION_FARMER
		};
	}

	/**
	 * Sets the profession. Accepts the villager_v2 professions of
	 * {@link VillagerTrades}; the legacy PROFESSION_* constants (0-4) of older
	 * plugins are mapped onto them.
	 */
	public function setProfession(int $profession) : void{
		if($profession < 0 || $profession > VillagerTrades::NITWIT){
			$profession = VillagerTrades::UNEMPLOYED;
		}
		if($profession !== $this->profession){
			$this->profession = $profession;
			$this->offers = [];
			$this->level = 0;
			$this->tradeExperience = 0;
			if(VillagerTrades::isTradingProfession($profession)){
				$this->offers = VillagerTrades::rollLevel($profession, 0, new Random($this->tradeSeed));
			}
		}
		$this->networkPropertiesDirty = true;
	}

	public function getProfession() : int{
		return $this->profession;
	}

	public function getLevel() : int{
		return $this->level;
	}

	public function getTradeExperience() : int{
		return $this->tradeExperience;
	}

	/** @return TradeOffer[] */
	public function getOffers() : array{
		return $this->offers;
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::VILLAGER_SPAWN_EGG();
	}

	private ?Player $tradingPlayer = null;

	/** Bedrock opens the trade screen only when the villager knows who trades with it. */
	public function setTradingPlayer(?Player $player) : void{
		$this->tradingPlayer = $player;
		$this->networkPropertiesDirty = true;
		$this->sendData(null);
	}

	public function getTradingPlayer() : ?Player{
		return $this->tradingPlayer !== null && $this->tradingPlayer->isConnected() ? $this->tradingPlayer : null;
	}

	public function getInteractButtonText(Player $player) : string{
		return !$this->baby && $this->isAlive() && VillagerTrades::isTradingProfession($this->profession) ? "action.interact.trade" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->baby || !$this->isAlive() || !VillagerTrades::isTradingProfession($this->profession)){
			return false;
		}
		if(count($this->offers) === 0){
			$this->offers = VillagerTrades::rollLevel($this->profession, $this->level, new Random($this->tradeSeed + $this->level));
		}
		if(count($this->offers) === 0){
			return false;
		}
		if($this->getTradingPlayer() !== null && $this->getTradingPlayer() !== $player){
			return false; // already trading with someone else
		}
		$this->getNavigator()->stop();
		$this->tradingPlayer = $player;
		$this->networkPropertiesDirty = true;
		$this->sendData(null);
		$player->setCurrentWindow(new TradeInventory($this));
		return true;
	}

	/**
	 * Called after a trade was executed: experience, level ups (two new
	 * trades per level, regeneration like vanilla) and window refresh.
	 */
	public function onTrade(Player $player, TradeOffer $offer, int $times) : void{
		$this->tradeExperience += $offer->getTraderExp() * $times;
		while($this->level < 4 && $this->tradeExperience >= VillagerTrades::LEVEL_XP[$this->level + 1]){
			++$this->level;
			foreach(VillagerTrades::rollLevel($this->profession, $this->level, new Random($this->tradeSeed + $this->level)) as $newOffer){
				$this->offers[] = $newOffer;
			}
			$this->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 200));
		}
		$this->networkPropertiesDirty = true;
		$this->refreshTradeWindows();
	}

	/** Re-sends the trade window to every player trading with this villager. */
	public function refreshTradeWindows() : void{
		foreach($this->getWorld()->getPlayers() as $viewer){
			$window = $viewer->getCurrentWindow();
			if($window instanceof TradeInventory && $window->getVillager() === $this){
				$viewer->getNetworkSession()->getInvManager()?->syncTradeWindow($window);
			}
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$result = parent::entityBaseTick($tickDiff);
		if($this->baby && $this->isAlive()){
			$this->growTicks -= $tickDiff;
			if($this->growTicks <= 0){
				$this->setBaby(false);
			}
		}
		if(!$this->isAlive() || $this->baby){
			return $result;
		}
		if(($this->jobTicks += $tickDiff) >= self::JOB_SEARCH_INTERVAL){
			$this->jobTicks = 0;
			$this->updateJobSite();
		}
		if($this->jobSite !== null && ($this->restockTicks += $tickDiff) >= self::RESTOCK_INTERVAL){
			$this->restockTicks = 0;
			if($this->location->distanceSquared($this->jobSite) <= 16 * 16){
				foreach($this->offers as $offer){
					$offer->restock();
				}
				$this->refreshTradeWindows();
			}
		}
		return $result;
	}

	/** @var array<int, int|null> state id => profession of that job site block */
	private static array $professionByState = [];

	private static function professionOfState(int $stateId) : ?int{
		if(array_key_exists($stateId, self::$professionByState)){
			return self::$professionByState[$stateId];
		}
		try{
			$name = GlobalBlockStateHandlers::getSerializer()->serialize($stateId)->getName();
		}catch(\Throwable){
			$name = "";
		}
		return self::$professionByState[$stateId] = VillagerTrades::JOB_SITES[$name] ?? null;
	}

	private function jobSiteProfession(World $world, int $x, int $y, int $z) : ?int{
		if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> 4, $z >> 4)){
			return null;
		}
		$block = $world->getBlockAt($x, $y, $z);
		try{
			$name = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId())->getName();
		}catch(\Throwable){
			return null;
		}
		return VillagerTrades::JOB_SITES[$name] ?? null;
	}

	private function updateJobSite() : void{
		$world = $this->getWorld();
		$worldId = $world->getId();
		//keep the current job site while it exists
		if($this->jobSite !== null){
			$x = $this->jobSite->getFloorX();
			$y = $this->jobSite->getFloorY();
			$z = $this->jobSite->getFloorZ();
			if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
				return;
			}
			$profession = $this->jobSiteProfession($world, $x, $y, $z);
			if($profession === $this->profession){
				self::$claimedJobSites[$worldId][World::blockHash($x, $y, $z)] = $this->getId();
				return;
			}
			//job site gone: villagers that never traded lose their profession
			unset(self::$claimedJobSites[$worldId][World::blockHash($x, $y, $z)]);
			$this->jobSite = null;
			if($this->tradeExperience === 0 && $this->profession !== VillagerTrades::NITWIT){
				$this->setProfession(VillagerTrades::UNEMPLOYED);
			}
			return;
		}
		if($this->profession === VillagerTrades::NITWIT){
			return;
		}
		// vanilla: villagers look for an unclaimed job site in a wide area and walk there to work
		$base = $this->location->floor();
		$r = self::JOB_SEARCH_RADIUS;
		$best = null;
		$bestDistance = PHP_INT_MAX;
		for($x = $base->getFloorX() - $r; $x <= $base->getFloorX() + $r; ++$x){
			for($z = $base->getFloorZ() - $r; $z <= $base->getFloorZ() + $r; ++$z){
				$chunk = $world->getChunk($x >> 4, $z >> 4);
				if($chunk === null){
					continue;
				}
				for($y = max($world->getMinY(), $base->getFloorY() - 4); $y <= min($world->getMaxY() - 1, $base->getFloorY() + 6); ++$y){
					$profession = self::professionOfState($chunk->getBlockStateId($x & 15, $y, $z & 15));
					if($profession === null){
						continue;
					}
					//an experienced villager only accepts a job site of its own profession
					if($this->profession !== VillagerTrades::UNEMPLOYED && $profession !== $this->profession){
						continue;
					}
					$hash = World::blockHash($x, $y, $z);
					$claimant = self::$claimedJobSites[$worldId][$hash] ?? null;
					if($claimant !== null && $claimant !== $this->getId() && $world->getEntity($claimant) !== null){
						continue;
					}
					$d = ($x - $base->x) ** 2 + ($y - $base->y) ** 2 + ($z - $base->z) ** 2;
					if($d < $bestDistance){
						$bestDistance = $d;
						$best = [$x, $y, $z, $profession];
					}
				}
			}
		}
		if($best !== null){
			[$x, $y, $z, $profession] = $best;
			self::$claimedJobSites[$worldId][World::blockHash($x, $y, $z)] = $this->getId();
			$this->jobSite = new Vector3($x, $y, $z);
			if($this->profession === VillagerTrades::UNEMPLOYED){
				$this->setProfession($profession);
			}
		}
	}

	// ------------------------------------------------------------------
	// Daily schedule (vanilla villager brain, simplified)
	// ------------------------------------------------------------------

	/** @var array<int, array<int, int>> world id => bed hash => villager entity id */
	private static array $claimedBeds = [];
	private ?Vector3 $bed = null;
	private bool $sleeping = false;
	private int $brainTicks = 0;
	private int $workTicks = 0;
	private ?Vector3 $harvestTarget = null;
	private int $golemTicks = 0;
	private int $breedTicks = 0;

	private const ACTIVITY_IDLE = 0;
	private const ACTIVITY_WORK = 1;
	private const ACTIVITY_MEET = 2;
	private const ACTIVITY_REST = 3;

	private function activity() : int{
		$time = $this->getWorld()->getTimeOfDay();
		if($time >= 12000){
			return self::ACTIVITY_REST;
		}
		if($this->baby){
			return self::ACTIVITY_IDLE;
		}
		if($time >= 2000 && $time < 9000 && $this->jobSite !== null){
			return self::ACTIVITY_WORK;
		}
		if($time >= 9000 && $time < 11000){
			return self::ACTIVITY_MEET;
		}
		return self::ACTIVITY_IDLE;
	}

	/** Monsters villagers run from. */
	private static function isThreat(Entity $entity) : bool{
		return $entity instanceof Zombie || $entity instanceof ZombieVillagerMob || $entity instanceof HuskMob || $entity instanceof DrownedMob
			|| $entity instanceof VindicatorMob || $entity instanceof PillagerMob || $entity instanceof EvokerMob || $entity instanceof RavagerMob
			|| $entity instanceof ZoglinMob || $entity instanceof VexMob;
	}

	protected function tickWandering(int $tickDiff) : void{
		if(($trader = $this->getTradingPlayer()) !== null){
			// stand still and look at the customer
			$this->getNavigator()->stop();
			$this->setMotion(new Vector3(0, $this->getMotion()->y, 0));
			$dx = $trader->getPosition()->x - $this->getPosition()->x;
			$dz = $trader->getPosition()->z - $this->getPosition()->z;
			$this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
			return;
		}
		if($this->isPanicking()){
			$this->wakeUp();
			parent::tickWandering($tickDiff);
			return;
		}
		$this->brainTicks -= $tickDiff;
		$scan = $this->brainTicks <= 0;
		if($scan){
			$this->brainTicks = 20;
			foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(8, 4, 8), $this) as $entity){
				if(self::isThreat($entity) && $entity->isAlive()){
					$this->startPanic($entity->getPosition(), 80);
					return;
				}
			}
			$this->tickVillageLife();
		}
		$navigator = $this->getNavigator();
		switch($this->activity()){
			case self::ACTIVITY_REST:
				$this->tickRest($scan, $tickDiff);
				return;
			case self::ACTIVITY_WORK:
				$this->wakeUp();
				$this->tickWork($scan, $tickDiff);
				return;
			case self::ACTIVITY_MEET:
				$this->wakeUp();
				if($scan && !$navigator->isMoving()){
					if($this->meetingPointAge-- <= 0){
						$this->meetingPointAge = 30;
						$this->meetingPoint = $this->findBlocksNear(24, 4, "bell", 1)[0] ?? null;
					}
					$bell = $this->meetingPoint;
					if($bell !== null && $bell->distanceSquared($this->getPosition()) > 16){
						$navigator->moveTo($bell->add(mt_rand(-3, 3) + 0.5, 0, mt_rand(-3, 3) + 0.5), $this->getWalkSpeed(), 2.0);
					}
				}
				break;
			default:
				$this->wakeUp();
				break;
		}
		parent::tickWandering($tickDiff);
	}

	/** Go to the claimed bed and sleep in it. */
	private function tickRest(bool $scan, int $tickDiff) : void{
		$navigator = $this->getNavigator();
		if($this->sleeping){
			$this->setMotion(new Vector3(0, $this->getMotion()->y, 0));
			return;
		}
		if($scan && ($this->bed === null || !$this->isBedValid($this->bed))){
			$this->bed = null;
			$this->claimBed();
		}
		if($this->bed === null){
			parent::tickWandering($tickDiff);
			return;
		}
		$target = $this->bed->add(0.5, 0.6, 0.5);
		if($this->getPosition()->distanceSquared($target) <= 2.5){
			$navigator->stop();
			$this->sleeping = true;
			$this->teleport($target);
			$this->networkPropertiesDirty = true;
			return;
		}
		$navigator->moveTo($target, $this->getWalkSpeed(), 1.0);
		if(!$navigator->tick($tickDiff)){
			$navigator->moveTo($target, $this->getWalkSpeed(), 1.0);
		}
	}

	private function wakeUp() : void{
		if($this->sleeping){
			$this->sleeping = false;
			$this->networkPropertiesDirty = true;
		}
	}

	private function isBedValid(Vector3 $pos) : bool{
		$world = $this->getWorld();
		return $world->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4) && $world->getBlock($pos) instanceof Bed;
	}

	private int $bedSearchCooldown = 0;
	private ?Vector3 $meetingPoint = null;
	private int $meetingPointAge = 0;

	private function claimBed() : void{
		if($this->bedSearchCooldown-- > 0){
			return;
		}
		$this->bedSearchCooldown = 30; // searches every 30 brain ticks (~30 s) at most
		$world = $this->getWorld();
		$worldId = $world->getId();
		foreach($this->findBlocksNear(24, 4, "bed") as $bed){
			$hash = World::blockHash($bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ());
			$claimant = self::$claimedBeds[$worldId][$hash] ?? null;
			if($claimant === null || $claimant === $this->getId() || $world->getEntity($claimant) === null){
				self::$claimedBeds[$worldId][$hash] = $this->getId();
				$this->bed = $bed;
				return;
			}
		}
	}

	/** Work at the job site; farmers harvest and replant crops. */
	private function tickWork(bool $scan, int $tickDiff) : void{
		$navigator = $this->getNavigator();
		if($this->profession === VillagerTrades::FARMER && $this->harvestTarget === null && $scan){
			$this->harvestTarget = $this->findBlocksNear(9, 2, "crop", 1)[0] ?? null;
		}
		if($this->harvestTarget !== null){
			$target = $this->harvestTarget;
			if($this->getPosition()->distanceSquared($target->add(0.5, 0, 0.5)) <= 3.0){
				$block = $this->getWorld()->getBlock($target);
				if($block instanceof Crops && $block->getAge() >= $block::MAX_AGE){
					// harvest: drop the produce, replant at age 0 (vanilla farmers keep the seeds)
					foreach($block->getDrops(VanillaItems::AIR()) as $drop){
						if(!($drop->getTypeId() === VanillaItems::WHEAT_SEEDS()->getTypeId() || $drop->getTypeId() === VanillaItems::BEETROOT_SEEDS()->getTypeId())){
							$this->getWorld()->dropItem($target->add(0.5, 0.5, 0.5), $drop);
						}
					}
					$this->getWorld()->setBlock($target, (clone $block)->setAge(0));
					$this->broadcastAnimation(new \pocketmine\entity\animation\ArmSwingAnimation($this));
				}
				$this->harvestTarget = null;
				$navigator->stop();
				return;
			}
			$navigator->moveTo($target->add(0.5, 0, 0.5), $this->getWalkSpeed(), 1.2);
			if(!$navigator->tick($tickDiff)){
				$this->harvestTarget = null;
			}
			return;
		}
		$site = $this->jobSite;
		if($site === null){
			return;
		}
		$distance = $this->getPosition()->distanceSquared($site->add(0.5, 0, 0.5));
		if($distance > 4){
			$navigator->moveTo($site->add(0.5, 0, 0.5), $this->getWalkSpeed(), 1.5);
			$navigator->tick($tickDiff);
			return;
		}
		$navigator->stop();
		// look at the workstation
		$dx = $site->x + 0.5 - $this->getPosition()->x;
		$dz = $site->z + 0.5 - $this->getPosition()->z;
		$this->setRotation(rad2deg(atan2(-$dx, $dz)), 20.0);
		$this->workTicks += $tickDiff;
		if($this->workTicks >= 200){
			$this->workTicks = 0;
			// working at the job site restocks trades (vanilla: up to twice a day)
			foreach($this->offers as $offer){
				$offer->restock();
			}
		}
	}

	/**
	 * Iron golem spawning and breeding:
	 * - 5+ villagers within 16 blocks and no golem within 16: sometimes a golem appears.
	 * - With free beds in the village, adult villagers occasionally have a baby.
	 */
	private function tickVillageLife() : void{
		if($this->baby){
			return;
		}
		$world = $this->getWorld();
		$this->golemTicks += 20;
		$this->breedTicks += 20;
		if($this->golemTicks >= 600){
			$this->golemTicks = 0;
			$villagers = 0;
			$golem = false;
			foreach($world->getNearbyEntities($this->getBoundingBox()->expandedCopy(16, 8, 16), $this) as $entity){
				if($entity instanceof Villager){
					++$villagers;
				}elseif($entity instanceof IronGolemMob){
					$golem = true;
				}
			}
			if(!$golem && $villagers >= 4 && mt_rand(0, 4) === 0){
				$spot = $this->findRandomStandPos(8, 6);
				if($spot !== null){
					(new IronGolemMob(Location::fromObject($spot, $world)))->spawnToAll();
				}
			}
		}
		if($this->breedTicks >= 1200){
			$this->breedTicks = 0;
			if(mt_rand(0, 7) !== 0){
				return;
			}
			$villagers = 1;
			$partner = null;
			foreach($world->getNearbyEntities($this->getBoundingBox()->expandedCopy(32, 8, 32), $this) as $entity){
				if($entity instanceof Villager){
					++$villagers;
					if(!$entity->isBaby() && $partner === null && $entity->getPosition()->distanceSquared($this->getPosition()) < 64){
						$partner = $entity;
					}
				}
			}
			if($partner === null){
				return;
			}
			$beds = count($this->findBlocksNear(24, 4, "bed", 64));
			if($beds > $villagers){
				$baby = new Villager(Location::fromObject($this->getPosition(), $world));
				$baby->setBaby(true);
				$baby->spawnToAll();
				$this->getWorld()->addParticle($this->getPosition()->add(0, 2, 0), new \pocketmine\world\particle\HeartParticle());
			}
		}
	}

	/** Babies grow up after 20 minutes. */
	private int $growTicks = 0;

	public function setBaby(bool $baby) : void{
		$this->baby = $baby;
		$this->growTicks = $baby ? 24000 : 0;
		$this->setScale($baby ? 0.5 : 1.0);
		$this->networkPropertiesDirty = true;
	}

	/** @var array<string, array<int, bool>> kind => state id => matches */
	private static array $kindCache = [];

	private static function stateIs(string $kind, int $stateId) : bool{
		if(isset(self::$kindCache[$kind][$stateId])){
			return self::$kindCache[$kind][$stateId];
		}
		$block = \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::$kindCache[$kind][$stateId] = match($kind){
			"bed" => $block instanceof Bed && $block->isHeadPart(),
			"bell" => $block instanceof Bell,
			"crop" => $block instanceof Crops && $block->getAge() >= $block::MAX_AGE,
			default => false,
		};
	}

	/**
	 * Positions of blocks of a kind ("bed", "bell", "crop") around the
	 * villager, nearest first. Reads raw chunk data, so it is cheap.
	 * @return Vector3[]
	 */
	private function findBlocksNear(int $radius, int $vertical, string $kind, int $limit = 16) : array{
		$world = $this->getWorld();
		$base = $this->getPosition()->floor();
		$found = [];
		for($x = $base->getFloorX() - $radius; $x <= $base->getFloorX() + $radius; ++$x){
			for($z = $base->getFloorZ() - $radius; $z <= $base->getFloorZ() + $radius; ++$z){
				$chunk = $world->getChunk($x >> 4, $z >> 4);
				if($chunk === null){
					continue;
				}
				for($y = max($world->getMinY(), $base->getFloorY() - $vertical); $y <= min($world->getMaxY() - 1, $base->getFloorY() + $vertical); ++$y){
					if(self::stateIs($kind, $chunk->getBlockStateId($x & 15, $y, $z & 15))){
						$found[] = new Vector3($x, $y, $z);
					}
				}
			}
		}
		usort($found, fn(Vector3 $a, Vector3 $b) => $a->distanceSquared($base) <=> $b->distanceSquared($base));
		return array_slice($found, 0, $limit);
	}

	/** Vanilla: villagers killed by zombies turn into zombie villagers (normal 50 %, hard 100 %). */
	protected function onDeath() : void{
		$cause = $this->getLastDamageCause();
		if($cause instanceof EntityDamageByEntityEvent){
			$damager = $cause->getDamager();
			$difficulty = $this->getWorld()->getDifficulty();
			if(($damager instanceof Zombie || $damager instanceof ZombieVillagerMob || $damager instanceof HuskMob || $damager instanceof DrownedMob)
				&& ($difficulty === World::DIFFICULTY_HARD || ($difficulty === World::DIFFICULTY_NORMAL && mt_rand(0, 1) === 0))){
				$zombie = new ZombieVillagerMob(Location::fromObject($this->getPosition(), $this->getWorld(), $this->location->yaw, 0.0));
				$zombie->spawnToAll();
				$this->flagForDespawn();
				return;
			}
		}
		parent::onDeath();
	}

	protected function onDispose() : void{
		if($this->bed !== null && $this->location->isValid()){
			unset(self::$claimedBeds[$this->getWorld()->getId()][World::blockHash($this->bed->getFloorX(), $this->bed->getFloorY(), $this->bed->getFloorZ())]);
		}
		if($this->jobSite !== null && $this->location->isValid()){
			unset(self::$claimedJobSites[$this->getWorld()->getId()][World::blockHash($this->jobSite->getFloorX(), $this->jobSite->getFloorY(), $this->jobSite->getFloorZ())]);
		}
		parent::onDispose();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SLEEPING, $this->sleeping);
		$properties->setLong(EntityMetadataProperties::TRADING_PLAYER_EID, $this->getTradingPlayer()?->getId() ?? -1);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->profession);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->skin);
		$properties->setInt(EntityMetadataProperties::TRADE_TIER, $this->level);
		$properties->setInt(EntityMetadataProperties::MAX_TRADE_TIER, 4);
		$properties->setInt(EntityMetadataProperties::TRADE_XP, $this->tradeExperience);
	}
}
