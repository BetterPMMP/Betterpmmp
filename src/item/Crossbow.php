<?php

/*
 * BetterPMMP - crossbow.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\ExtraEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function cos;
use function deg2rad;
use function max;
use function sin;

/**
 * Vanilla crossbow: hold use to load (1.25 s, Quick Charge -0.25 s per
 * level), the loaded arrow is kept in the item ("chargedItem", so the client
 * shows the loaded crossbow), the next use fires it at 3.15 blocks per tick.
 * Multishot fires three arrows (±10°) for one, loading takes the ammo.
 */
class Crossbow extends SimpleDurableItem implements Releasable{

	public const TAG_CHARGED = "chargedItem";
	private const SPEED = 3.15;

	private bool $justFired = false;

	public function getChargeTicks() : int{
		return max(0, 25 - 5 * $this->getEnchantmentLevel(ExtraEnchantments::get("quick_charge")));
	}

	public function isCharged() : bool{
		return $this->getNamedTag()->getTag(self::TAG_CHARGED) instanceof CompoundTag;
	}

	private function setCharged(?Item $projectile) : void{
		$tag = $this->getNamedTag();
		if($projectile === null){
			$tag->removeTag(self::TAG_CHARGED);
		}else{
			$tag->setTag(self::TAG_CHARGED, $projectile->nbtSerialize());
		}
		$this->setNamedTag($tag);
	}

	public function canStartUsingItem(Player $player) : bool{
		if($this->justFired){
			$this->justFired = false;
			return false;
		}
		if($this->isCharged()){
			return false;
		}
		return !$player->hasFiniteResources() || $player->getOffHandInventory()->contains($arrow = VanillaItems::ARROW()) || $player->getInventory()->contains($arrow);
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		if($this->isCharged()){
			$this->fire($player);
			$this->justFired = true;
			return ItemUseResult::SUCCESS;
		}
		if($this->canStartUsingItem($player)){
			$quick = $this->getEnchantmentLevel(ExtraEnchantments::get("quick_charge")) > 0;
			$player->getWorld()->addSound($player->getPosition(), new VanillaSound($quick ? LevelSoundEvent::CROSSBOW_QUICK_CHARGE_START : LevelSoundEvent::CROSSBOW_LOADING_START));
		}
		return ItemUseResult::NONE;
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		if($this->isCharged() || $player->getItemUseDuration() < $this->getChargeTicks()){
			return ItemUseResult::FAIL;
		}
		$arrow = VanillaItems::ARROW();
		if($player->hasFiniteResources()){
			$inventory = match(true){
				$player->getOffHandInventory()->contains($arrow) => $player->getOffHandInventory(),
				$player->getInventory()->contains($arrow) => $player->getInventory(),
				default => null
			};
			if($inventory === null){
				return ItemUseResult::FAIL;
			}
			$inventory->removeItem($arrow);
		}
		$this->setCharged($arrow);
		$quick = $this->getEnchantmentLevel(ExtraEnchantments::get("quick_charge")) > 0;
		$player->getWorld()->addSound($player->getPosition(), new VanillaSound($quick ? LevelSoundEvent::CROSSBOW_QUICK_CHARGE_END : LevelSoundEvent::CROSSBOW_LOADING_END));
		return ItemUseResult::SUCCESS;
	}

	private function fire(Player $player) : void{
		$this->setCharged(null);
		$multishot = $this->getEnchantmentLevel(ExtraEnchantments::get("multishot")) > 0;
		foreach($multishot ? [0.0, -10.0, 10.0] : [0.0] as $index => $offset){
			$location = $player->getLocation();
			$yaw = $location->yaw + $offset;
			$pitch = $location->pitch;
			$y = -sin(deg2rad($pitch));
			$xz = cos(deg2rad($pitch));
			$direction = new Vector3(-$xz * sin(deg2rad($yaw)), $y, $xz * cos(deg2rad($yaw)));
			$entity = new ArrowEntity(Location::fromObject($player->getEyePos(), $player->getWorld(), ($yaw > 180 ? 360 : 0) - $yaw, -$pitch), $player, false);
			$entity->setMotion($direction->multiply(self::SPEED));
			if($index > 0 || !$player->hasFiniteResources()){
				$entity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
			}
			$ev = new ProjectileLaunchEvent($entity);
			$ev->call();
			if($ev->isCancelled()){
				$entity->flagForDespawn();
				continue;
			}
			$entity->spawnToAll();
		}
		$player->getWorld()->addSound($player->getPosition(), new VanillaSound(LevelSoundEvent::CROSSBOW_SHOOT));
		if($player->hasFiniteResources()){
			$this->applyDamage($multishot ? 3 : 1);
		}
	}
}
