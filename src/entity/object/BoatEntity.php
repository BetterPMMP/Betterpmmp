<?php

/*
 * BetterPMMP - boats and rafts.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\Water;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\riding\Rideable;
use pocketmine\entity\riding\RideableTrait;
use pocketmine\entity\riding\RidingManager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function count;
use function cos;
use function deg2rad;
use function floor;
use function max;
use function min;
use function sin;
use function sqrt;

/**
 * Vanilla boats: float on water, carry two riders (one with chest boats),
 * are steered by the driver (the client moves the boat; without that the
 * server applies the driver's input), and break into their item when hit.
 */
class BoatEntity extends Entity implements Rideable{
	use RideableTrait;

	public const WOODS = ["oak", "spruce", "birch", "jungle", "acacia", "dark_oak", "mangrove", "bamboo", "cherry", "pale_oak"];

	private int $variant = 0;
	private bool $chest = false;
	private float $hitDamage = 0.0;

	public static function getNetworkTypeId() : string{ return EntityIds::BOAT; }

	public function __construct(Location $location, ?CompoundTag $nbt = null, int $variant = 0, bool $chest = false){
		$this->variant = $variant;
		$this->chest = $chest;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.455, 1.4); }
	protected function getInitialDragMultiplier() : float{ return 0.1; }
	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->variant = $nbt->getInt("Variant", $this->variant);
		$this->chest = $nbt->getByte("BetterPMMPChestBoat", $this->chest ? 1 : 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt("Variant", $this->variant);
		$nbt->setByte("BetterPMMPChestBoat", $this->chest ? 1 : 0);
		return $nbt;
	}

	protected function sendSpawnPacket(Player $player) : void{
		// chest boats are a separate entity type on the client
		if($this->chest){
			$player->getNetworkSession()->sendDataPacket(\pocketmine\network\mcpe\protocol\AddActorPacket::create(
				$this->getId(), $this->getId(), EntityIds::CHEST_BOAT, $this->location->asVector3(), $this->getMotion(),
				$this->location->pitch, $this->location->yaw, $this->location->yaw, $this->location->yaw,
				[], $this->getAllNetworkData(), new \pocketmine\network\mcpe\protocol\types\entity\PropertySyncData([], []), [], null
			));
			return;
		}
		parent::sendSpawnPacket($player);
	}

	public function getMaxRiders() : int{ return $this->chest ? 1 : 2; }

	public function getSeatPosition(int $seat) : Vector3{
		if(count(RidingManager::getRiders($this)) < 2){
			return new Vector3(0, 1.02, 0);
		}
		return $seat === 0 ? new Vector3(0.2, 1.02, 0) : new Vector3(-0.6, 1.02, 0);
	}

	public function isControlledByDriver() : bool{ return true; }

	public function canBeCollidedWith() : bool{ return true; }

	public function isFireProof() : bool{ return false; }

	public function getItemName() : string{
		$wood = self::WOODS[$this->variant] ?? "oak";
		if($wood === "bamboo"){
			return $this->chest ? "bamboo_chest_raft" : "bamboo_raft";
		}
		return $wood . ($this->chest ? "_chest_boat" : "_boat");
	}

	public function asItem() : ?Item{
		return StringToItemParser::getInstance()->parse($this->getItemName());
	}

	public function getInteractButtonText(Player $player) : string{
		return count(RidingManager::getRiders($this)) < $this->getMaxRiders() && RidingManager::getVehicle($player) !== $this ? "action.interact.ride.boat" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() || RidingManager::getVehicle($player) === $this){
			return false;
		}
		return RidingManager::mount($player, $this);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent){
			return;
		}
		$damager = $source->getDamager();
		if($damager instanceof Player && $damager->isCreative()){
			$this->flagForDespawn();
			return;
		}
		$this->hitDamage += $source->getFinalDamage() * 10;
		if($this->hitDamage > 40){
			$item = $this->asItem();
			if($item !== null){
				$this->getWorld()->dropItem($this->location, $item);
			}
			$this->flagForDespawn();
		}
	}

	private function isInWater() : bool{
		$world = $this->getWorld();
		$pos = $this->location;
		return $world->getBlockAt((int) floor($pos->x), (int) floor($pos->y + 0.1), (int) floor($pos->z)) instanceof Water;
	}

	private function isAboveWater() : bool{
		$world = $this->getWorld();
		$pos = $this->location;
		return $world->getBlockAt((int) floor($pos->x), (int) floor($pos->y - 0.1), (int) floor($pos->z)) instanceof Water;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->hitDamage = max(0.0, $this->hitDamage - $tickDiff);
		if($this->isClientDriven()){
			$this->setMotion(Vector3::zero());
			return true;
		}
		$motion = $this->getMotion();
		$vy = $motion->y;
		if($this->isInWater()){
			$vy = min(0.1, $vy + 0.06); // float up to the surface
		}elseif($this->isAboveWater()){
			$vy = max(0.0, $vy);
		}
		$vx = $motion->x;
		$vz = $motion->z;
		if(RidingManager::getDriver($this) instanceof Player && ($this->driverForward !== 0.0 || $this->driverStrafe !== 0.0)){
			// server-side steering from the driver's input
			$water = $this->isInWater() || $this->isAboveWater();
			$accel = $water ? 0.04 : 0.01;
			$yaw = deg2rad($this->driverYaw);
			$vx += (-sin($yaw) * $this->driverForward + cos($yaw) * $this->driverStrafe) * $accel;
			$vz += (cos($yaw) * $this->driverForward + sin($yaw) * $this->driverStrafe) * $accel;
			$max = $water ? 0.4 : 0.08;
			$speed = sqrt($vx * $vx + $vz * $vz);
			if($speed > $max){
				$vx *= $max / $speed;
				$vz *= $max / $speed;
			}
			$this->setRotation($this->driverYaw, 0.0);
		}
		$this->setMotion(new Vector3($vx, $vy, $vz));
		return true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setByte(EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, 0);
		$properties->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::STACKABLE, true);
	}

	/**
	 * Places a boat from an item at the first water surface or ground the
	 * player is looking at (vanilla raytrace including liquids).
	 */
	public static function placeFromItem(Player $player, int $variant, bool $chest) : bool{
		$world = $player->getWorld();
		$start = $player->getEyePos();
		$dir = $player->getDirectionVector();
		for($d = 0.5; $d <= 5.0; $d += 0.25){
			$p = $start->addVector($dir->multiply($d));
			$block = $world->getBlockAt((int) floor($p->x), (int) floor($p->y), (int) floor($p->z));
			if($block instanceof Water || $block->isSolid()){
				$y = floor($p->y) + 1;
				if($block instanceof Water){
					// float on the surface of the water column
					while($world->getBlockAt((int) floor($p->x), (int) $y, (int) floor($p->z)) instanceof Water){
						++$y;
					}
				}
				$boat = new BoatEntity(Location::fromObject(new Vector3($p->x, $y, $p->z), $world, $player->getLocation()->getYaw(), 0.0), null, $variant, $chest);
				$boat->spawnToAll();
				return true;
			}
		}
		return false;
	}
}
