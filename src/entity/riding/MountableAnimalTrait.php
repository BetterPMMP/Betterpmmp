<?php

/*
 * BetterPMMP - rideable animals.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\entity\Entity;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\particle\SmokeParticle;
use function cos;
use function deg2rad;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function sin;

/**
 * Vanilla riding for animals. The using class implements {@link Rideable}
 * and defines mountKind(): "horse" (horse, donkey, mule: tamed by riding,
 * steered with a saddle), "skeleton_horse" (tame), "pig" / "strider" (saddle,
 * steered with carrot / warped fungus on a stick), "camel" (saddle, 2 seats).
 */
trait MountableAnimalTrait{
	use RideableTrait;

	private bool $saddled = false;
	private bool $tamed = false;
	private int $temper = 0;
	private int $tameCheckTicks = 0;
	private bool $wasControlled = false;
	/** BetterPMMP: horse armor in the body slot */
	private ?Item $horseArmor = null;

	private const HORSE_ARMOR_POINTS = ["leather" => 3, "copper" => 4, "iron" => 5, "golden" => 7, "diamond" => 11, "netherite" => 19];

	private static function horseArmorPoints(?Item $item) : int{
		if(!$item instanceof \pocketmine\item\GenericBedrockItem){
			return 0;
		}
		foreach(self::HORSE_ARMOR_POINTS as $material => $points){
			if($item->getBedrockName() === "minecraft:{$material}_horse_armor"){
				return $points;
			}
		}
		return 0;
	}

	public function getBodyArmor() : Item{
		return $this->horseArmor ?? \pocketmine\item\VanillaItems::AIR();
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + self::horseArmorPoints($this->horseArmor);
	}

	abstract protected function mountKind() : string;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->saddled = $nbt->getByte("Saddled", 0) !== 0;
		$this->tamed = $nbt->getByte("Tamed", $this->mountKind() === "skeleton_horse" ? 1 : 0) !== 0;
		$this->temper = $nbt->getInt("Temper", 0);
		$armor = $nbt->getCompoundTag("BetterPMMPHorseArmor");
		if($armor !== null){
			try{ $this->horseArmor = Item::nbtDeserialize($armor); }catch(\Exception){ $this->horseArmor = null; }
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte("Saddled", $this->saddled ? 1 : 0);
		$nbt->setByte("Tamed", $this->tamed ? 1 : 0);
		$nbt->setInt("Temper", $this->temper);
		if($this->horseArmor !== null){
			$nbt->setTag("BetterPMMPHorseArmor", $this->horseArmor->nbtSerialize());
		}
		return $nbt;
	}

	public function isSaddled() : bool{ return $this->saddled; }
	public function isTamed() : bool{ return $this->tamed; }

	public function getMaxRiders() : int{ return $this->mountKind() === "camel" ? 2 : 1; }

	public function getSeatPosition(int $seat) : Vector3{
		return match($this->mountKind()){
			"pig" => new Vector3(0, 1.85, 0),
			"strider" => new Vector3(0, 2.82, -0.2),
			"camel" => new Vector3(0, 3.125, $seat === 0 ? 0.5 : -0.5),
			"skeleton_horse" => new Vector3(0, 2.25, -0.2),
			default => new Vector3(0, 2.32, -0.2),
		};
	}

	private function stickFor() : ?string{
		return match($this->mountKind()){
			"pig" => "minecraft:carrot_on_a_stick",
			"strider" => "minecraft:warped_fungus_on_a_stick",
			default => null,
		};
	}

	public function isControlledByDriver() : bool{
		$driver = RidingManager::getDriver($this);
		if(!$driver instanceof Player || !$this->saddled){
			return false;
		}
		$stick = $this->stickFor();
		if($stick !== null){
			$held = $driver->getInventory()->getItemInHand();
			return $held->getTypeId() === ExtraVanillaItems::get($stick)?->getTypeId();
		}
		return $this->tamed || $this->mountKind() === "camel";
	}

	private static function isSaddle(Item $item) : bool{
		return $item->getTypeId() === ExtraVanillaItems::get("minecraft:saddle")?->getTypeId();
	}

	public function getInteractButtonText(Player $player) : string{
		if($this->isBaby() || RidingManager::getVehicle($player) === $this){
			return "";
		}
		$held = $player->getInventory()->getItemInHand();
		if(!$this->saddled && self::isSaddle($held) && ($this->tamed || in_array($this->mountKind(), ["pig", "strider", "camel"], true))){
			return "action.interact.saddle";
		}
		if(in_array($this->mountKind(), ["pig", "strider", "camel"], true) && !$this->saddled){
			return "";
		}
		return in_array($this->mountKind(), ["horse", "skeleton_horse"], true) ? "action.interact.ride.horse" : "action.interact.mount";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		$baby = $this->isBaby();
		if(!$baby && $this->tamed && $this->mountKind() === "horse" && self::horseArmorPoints($held) > 0){
			if($this->horseArmor !== null){
				$this->getWorld()->dropItem($this->getPosition(), $this->horseArmor);
			}
			$this->horseArmor = (clone $held)->setCount(1);
			if($player->hasFiniteResources()){
				$held->pop();
				$player->getInventory()->setItemInHand($held);
			}
			$this->broadcastEquipment();
			return true;
		}
		if(!$baby && !$this->saddled && self::isSaddle($held) && ($this->tamed || in_array($this->mountKind(), ["pig", "strider", "camel"], true))){
			$this->saddled = true;
			if($player->hasFiniteResources()){
				$held->pop();
				$player->getInventory()->setItemInHand($held);
			}
			$this->networkPropertiesDirty = true;
			return true;
		}
		// feeding an untamed horse makes it calmer (vanilla temper)
		if($this->mountKind() === "horse" && !$this->tamed){
			$temper = match($held->getTypeId()){
				\pocketmine\item\VanillaItems::SUGAR()->getTypeId(), \pocketmine\item\VanillaItems::WHEAT()->getTypeId(), \pocketmine\item\VanillaItems::APPLE()->getTypeId() => 3,
				\pocketmine\item\VanillaItems::GOLDEN_CARROT()->getTypeId() => 5,
				\pocketmine\item\VanillaItems::GOLDEN_APPLE()->getTypeId() => 10,
				default => 0,
			};
			if($temper > 0){
				$this->temper = min(100, $this->temper + $temper);
				if($player->hasFiniteResources()){
					$held->pop();
					$player->getInventory()->setItemInHand($held);
				}
				$this->getWorld()->addParticle($this->getPosition()->add(0, 1.5, 0), new HeartParticle());
				return true;
			}
		}
		if(parent::onInteract($player, $clickPos)){
			return true; // breeding / feeding
		}
		if($baby || $player->isSneaking() || RidingManager::getVehicle($player) === $this){
			return false;
		}
		if(in_array($this->mountKind(), ["pig", "strider", "camel"], true) && !$this->saddled){
			return false;
		}
		return RidingManager::mount($player, $this);
	}

	public function onRiderMount(Entity $rider, int $seat) : void{
		$this->networkPropertiesDirty = true;
		$this->getNavigator()->stop();
	}

	protected function tickWandering(int $tickDiff) : void{
		$driver = RidingManager::getDriver($this);
		if($driver === null){
			parent::tickWandering($tickDiff);
			return;
		}
		$this->getNavigator()->stop();
		if($this->mountKind() === "horse" && !$this->tamed && $driver instanceof Player){
			$this->tickTaming($driver, $tickDiff);
			return;
		}
		// the driver may switch to / from the steering stick
		$controlled = $this->isControlledByDriver();
		if($controlled !== $this->wasControlled){
			$this->wasControlled = $controlled;
			$this->networkPropertiesDirty = true;
		}
		if($this->isClientDriven()){
			return;
		}
		if($this->isControlledByDriver() && ($this->driverForward !== 0.0 || $this->driverStrafe !== 0.0)){
			$yaw = deg2rad($this->driverYaw);
			$speed = $this->getWalkSpeed(true) * ($this->mountKind() === "horse" ? 1.6 : 1.0);
			$motion = $this->getMotion();
			$vx = (-sin($yaw) * $this->driverForward + cos($yaw) * $this->driverStrafe) * $speed;
			$vz = (cos($yaw) * $this->driverForward + sin($yaw) * $this->driverStrafe) * $speed;
			$vy = $motion->y;
			if($this->driverJumping && $this->isOnGround() && $this->mountKind() !== "pig"){
				$vy = 0.6;
			}
			$this->setMotion(new Vector3($vx, $vy, $vz));
			$this->setRotation($this->driverYaw, 0.0);
		}
	}

	/** Vanilla taming: each try succeeds with temper/100, otherwise the horse throws the rider off. */
	private function tickTaming(Player $driver, int $tickDiff) : void{
		$this->tameCheckTicks += $tickDiff;
		if($this->tameCheckTicks < 40){
			return;
		}
		$this->tameCheckTicks = 0;
		if(mt_rand(0, 99) < $this->temper){
			$this->tamed = true;
			$this->networkPropertiesDirty = true;
			for($i = 0; $i < 7; ++$i){
				$this->getWorld()->addParticle($this->getPosition()->add(mt_rand(-10, 10) / 10, 1.5, mt_rand(-10, 10) / 10), new HeartParticle());
			}
			return;
		}
		$this->temper = min(100, $this->temper + 5);
		RidingManager::dismount($driver);
		for($i = 0; $i < 7; ++$i){
			$this->getWorld()->addParticle($this->getPosition()->add(mt_rand(-10, 10) / 10, 1.5, mt_rand(-10, 10) / 10), new SmokeParticle());
		}
		$this->setMotion(new Vector3(0, 0.3, 0));
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->saddled);
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->tamed);
		$controlled = $this->isControlledByDriver();
		$properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $controlled);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, $controlled && in_array($this->mountKind(), ["horse", "skeleton_horse"], true));
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		if($this->horseArmor !== null){
			$drops[] = $this->horseArmor;
		}
		if($this->saddled && ($saddle = ExtraVanillaItems::get("minecraft:saddle")) !== null){
			$drops[] = $saddle;
		}
		return $drops;
	}
}
