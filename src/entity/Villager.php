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
use function count;
use function mt_rand;

/**
 * BetterPMMP villager (Bedrock villager_v2): professions taken from job site
 * blocks, trading with vanilla trade tables, five levels with experience,
 * restocking at the job site and biome-dependent appearance.
 */
class Villager extends Mob implements Ageable{
	//legacy (villager v1) profession ids kept for API compatibility
	public const PROFESSION_FARMER = 0;
	public const PROFESSION_LIBRARIAN = 1;
	public const PROFESSION_PRIEST = 2;
	public const PROFESSION_BLACKSMITH = 3;
	public const PROFESSION_BUTCHER = 4;

	private const TAG_PROFESSION = "Profession"; //TAG_Int (legacy)
	private const TAG_DATA = "BetterPMMPVillager";

	private const JOB_SEARCH_INTERVAL = 100;
	private const JOB_SEARCH_RADIUS = 8;
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
			->setInt("TradeSeed", $this->tradeSeed);
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
		$base = $this->location->floor();
		$r = self::JOB_SEARCH_RADIUS;
		for($dy = -2; $dy <= 3; ++$dy){
			for($dx = -$r; $dx <= $r; ++$dx){
				for($dz = -$r; $dz <= $r; ++$dz){
					$x = $base->getFloorX() + $dx;
					$y = $base->getFloorY() + $dy;
					$z = $base->getFloorZ() + $dz;
					$hash = World::blockHash($x, $y, $z);
					$claimant = self::$claimedJobSites[$worldId][$hash] ?? null;
					if($claimant !== null && $claimant !== $this->getId() && $world->getEntity($claimant) !== null){
						continue;
					}
					$profession = $this->jobSiteProfession($world, $x, $y, $z);
					if($profession === null){
						continue;
					}
					//an experienced villager only accepts a job site of its own profession
					if($this->profession !== VillagerTrades::UNEMPLOYED && $profession !== $this->profession){
						continue;
					}
					self::$claimedJobSites[$worldId][$hash] = $this->getId();
					$this->jobSite = new Vector3($x, $y, $z);
					if($this->profession === VillagerTrades::UNEMPLOYED){
						$this->setProfession($profession);
					}
					return;
				}
			}
		}
	}

	protected function onDispose() : void{
		if($this->jobSite !== null && $this->location->isValid()){
			unset(self::$claimedJobSites[$this->getWorld()->getId()][World::blockHash($this->jobSite->getFloorX(), $this->jobSite->getFloorY(), $this->jobSite->getFloorZ())]);
		}
		parent::onDispose();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->profession);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->skin);
		$properties->setInt(EntityMetadataProperties::TRADE_TIER, $this->level);
		$properties->setInt(EntityMetadataProperties::MAX_TRADE_TIER, 4);
		$properties->setInt(EntityMetadataProperties::TRADE_XP, $this->tradeExperience);
	}
}
