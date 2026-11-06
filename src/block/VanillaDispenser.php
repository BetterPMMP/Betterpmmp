<?php

/*
 * BetterPMMP - vanilla dispenser and dropper.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\inventory\DispenserInventory;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Dispenser as DispenserTile;
use pocketmine\block\utils\RedstonePower;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Egg as EggEntity;
use pocketmine\entity\projectile\ExperienceBottle as ExperienceBottleEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Snowball as SnowballEntity;
use pocketmine\entity\projectile\SplashPotion as SplashPotionEntity;
use pocketmine\item\Arrow;
use pocketmine\item\Bucket;
use pocketmine\item\Egg;
use pocketmine\item\ExperienceBottle;
use pocketmine\item\Fertilizer;
use pocketmine\item\FireCharge;
use pocketmine\item\FlintSteel;
use pocketmine\item\Item;
use pocketmine\item\LiquidBucket;
use pocketmine\item\Snowball;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\ClickSound;
use function count;
use function mt_rand;

/**
 * Fires on a rising redstone edge after 4 game ticks. The dropper only drops
 * (or inserts into the container in front of it); the dispenser also shoots
 * projectiles, places and picks up liquids, lights fire, primes TNT and uses
 * bone meal.
 */
class VanillaDispenser extends GenericBedrockBlock{

	private const DELAY = 4;

	public function isDropper() : bool{
		return $this->getBedrockName() === "minecraft:dropper";
	}

	public function getFacing() : int{
		$value = (int) $this->getStateData()->getStates()["facing_direction"]->getValue();
		return $value >= 0 && $value <= 5 ? $value : Facing::DOWN;
	}

	/** @return $this */
	public function setFacing(int $facing) : self{
		return $this->setStateIndex($this->withProperties(["facing_direction" => $facing])->getStateIndex());
	}

	public function isTriggered() : bool{
		return $this->getStateData()->getStates()["triggered_bit"]->getValue() !== 0;
	}

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		return $this->setStateIndex($this->withProperties(["triggered_bit" => $triggered])->getStateIndex());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		// the front faces the player
		$this->setFacing($player !== null ? Facing::opposite(VanillaPiston::lookingDirection($player)) : $face);
		$this->setTriggered(false);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function inventory() : ?DispenserInventory{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof DispenserTile ? $tile->getInventory() : null;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$inventory = $this->inventory();
		if($player !== null && $inventory !== null){
			$player->setCurrentWindow($inventory);
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		if($powered && !$this->isTriggered()){
			$this->position->getWorld()->setBlock($this->position, $this->setTriggered(true), false);
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::DELAY);
		}elseif(!$powered && $this->isTriggered()){
			$this->position->getWorld()->setBlock($this->position, $this->setTriggered(false), false);
		}
	}

	public function onScheduledUpdate() : void{
		$inventory = $this->inventory();
		if($inventory === null){
			return;
		}
		$slots = [];
		foreach($inventory->getContents() as $slot => $item){
			if(!$item->isNull()){
				$slots[] = $slot;
			}
		}
		$world = $this->position->getWorld();
		if(count($slots) === 0){
			$world->addSound($this->position, new ClickSound(1.2));
			return;
		}
		$slot = $slots[mt_rand(0, count($slots) - 1)];
		$item = $inventory->getItem($slot);
		$result = $this->isDropper() ? $this->drop($item) : $this->dispense($item);
		if($result !== null){
			$inventory->setItem($slot, $result[0]);
			if($result[1] !== null){
				// e.g. the empty bucket left over after placing water
				$rest = $inventory->addItem($result[1]);
				foreach($rest as $leftover){
					$this->dropItem($leftover);
				}
			}
			$world->addSound($this->position, new ClickSound(1.0));
		}else{
			$world->addSound($this->position, new ClickSound(1.2));
		}
	}

	private function frontPosition() : Vector3{
		return $this->position->getSide($this->getFacing());
	}

	private function dropItem(Item $item) : void{
		[$dx, $dy, $dz] = Facing::OFFSET[$this->getFacing()];
		$center = $this->position->add(0.5 + $dx * 0.7, 0.5 + $dy * 0.7 - ($dy === 0 ? 0.15 : 0), 0.5 + $dz * 0.7);
		$speed = 0.1 + mt_rand(0, 100) / 1000;
		$motion = new Vector3(
			$dx * $speed + (mt_rand(-100, 100) / 100) * 0.0172275 * 6,
			($dy === 0 ? 0.2 : $dy * $speed) + (mt_rand(-100, 100) / 100) * 0.0172275 * 6,
			$dz * $speed + (mt_rand(-100, 100) / 100) * 0.0172275 * 6
		);
		$this->position->getWorld()->dropItem($center, $item, $motion, 0);
	}

	/**
	 * @return array{Item, Item|null}|null new slot contents and an extra item, or null on failure
	 */
	private function drop(Item $item) : ?array{
		$single = (clone $item)->setCount(1);
		$rest = (clone $item)->setCount($item->getCount() - 1);
		$tile = $this->position->getWorld()->getTile($this->frontPosition());
		if($tile instanceof Container){
			$target = $tile->getInventory();
			if(!$target->canAddItem($single)){
				return null;
			}
			$target->addItem($single);
			return [$rest, null];
		}
		$this->dropItem($single);
		return [$rest, null];
	}

	/**
	 * @return array{Item, Item|null}|null
	 */
	private function dispense(Item $item) : ?array{
		$world = $this->position->getWorld();
		$front = $this->frontPosition();
		$frontBlock = $world->getBlock($front);
		$rest = (clone $item)->setCount($item->getCount() - 1);
		$facing = $this->getFacing();

		$projectile = match(true){
			$item instanceof Arrow => fn(Location $l) => new ArrowEntity($l, null, false),
			$item instanceof Snowball => fn(Location $l) => new SnowballEntity($l, null),
			$item instanceof Egg => fn(Location $l) => new EggEntity($l, null),
			$item instanceof ExperienceBottle => fn(Location $l) => new ExperienceBottleEntity($l, null),
			$item instanceof SplashPotion => fn(Location $l) => new SplashPotionEntity($l, null, $item->getType()),
			default => null
		};
		if($projectile !== null){
			[$dx, $dy, $dz] = Facing::OFFSET[$facing];
			$location = Location::fromObject($this->position->add(0.5 + $dx * 0.7, 0.5 + $dy * 0.7, 0.5 + $dz * 0.7), $world);
			/** @var Projectile $entity */
			$entity = $projectile($location);
			$power = $item instanceof Arrow ? 1.1 : 1.1;
			$entity->setMotion((new Vector3($dx, $dy + ($dy === 0 ? 0.1 : 0), $dz))->normalize()->multiply($power));
			if($entity instanceof ArrowEntity){
				$entity->setPickupMode(ArrowEntity::PICKUP_ANY);
			}
			$entity->spawnToAll();
			return [$rest, null];
		}

		if($item instanceof LiquidBucket){
			if(!$frontBlock->canBeReplaced()){
				return $this->drop($item);
			}
			$liquid = $item->getLiquid();
			$world->setBlock($front, $liquid->getFlowingForm());
			$world->addSound($front->add(0.5, 0.5, 0.5), $liquid->getBucketEmptySound());
			return [VanillaItems::BUCKET(), null];
		}
		if($item instanceof Bucket){
			if($frontBlock instanceof Liquid && $frontBlock->isSource()){
				$filled = match(true){
					$frontBlock instanceof Water => VanillaItems::WATER_BUCKET(),
					$frontBlock instanceof Lava => VanillaItems::LAVA_BUCKET(),
					default => null
				};
				if($filled !== null){
					$world->setBlock($front, VanillaBlocks::AIR());
					return [$rest, $filled];
				}
			}
			return $this->drop($item);
		}
		if($item instanceof FlintSteel){
			if($frontBlock instanceof TNT){
				$frontBlock->ignite();
			}elseif($frontBlock->getTypeId() === BlockTypeIds::AIR){
				$world->setBlock($front, VanillaBlocks::FIRE());
			}else{
				return null;
			}
			$used = clone $item;
			$used->applyDamage(1);
			return [$used, null];
		}
		if($item instanceof FireCharge){
			if($frontBlock->getTypeId() === BlockTypeIds::AIR){
				$world->setBlock($front, VanillaBlocks::FIRE());
				return [$rest, null];
			}
			return null;
		}
		if($item instanceof Fertilizer){
			$used = clone $item;
			if($frontBlock->onInteract($used, Facing::opposite($facing), new Vector3(0.5, 0.5, 0.5))){
				return [$used, null];
			}
			return null;
		}
		if($item->getBlock() instanceof TNT && $frontBlock->canBeReplaced()){
			$world->setBlock($front, VanillaBlocks::TNT());
			$tnt = $world->getBlock($front);
			if($tnt instanceof TNT){
				$tnt->ignite();
			}
			return [$rest, null];
		}
		$this->dropItem((clone $item)->setCount(1));
		return [$rest, null];
	}
}
