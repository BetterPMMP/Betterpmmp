<?php

/*
 * BetterPMMP - cushion (seat).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\riding\Rideable;
use pocketmine\entity\riding\RideableTrait;
use pocketmine\entity\riding\RidingManager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function array_search;
use function count;

/** A cushion placed on the ground: use it to sit down, hit it to pick it up again. */
class Cushion extends Entity implements Rideable{
	use RideableTrait;

	public const COLORS = ["white", "orange", "magenta", "light_blue", "yellow", "lime", "pink", "gray", "light_gray", "cyan", "purple", "blue", "brown", "green", "red", "black"];

	private string $color = "white";

	public static function getNetworkTypeId() : string{ return EntityIds::CUSHION; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.75); }
	protected function getInitialDragMultiplier() : float{ return 0.02; }
	protected function getInitialGravity() : float{ return 0.08; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->color = $nbt->getString("BetterPMMPColor", "white");
	}

	public function saveNBT() : CompoundTag{
		return parent::saveNBT()->setString("BetterPMMPColor", $this->color);
	}

	public function setColor(string $color) : void{
		$this->color = $color;
		$this->networkPropertiesDirty = true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$index = array_search($this->color, self::COLORS, true);
		$index = $index === false ? 0 : $index;
		$properties->setByte(EntityMetadataProperties::COLOR, $index);
		$properties->setInt(EntityMetadataProperties::VARIANT, $index);
	}

	public function getMaxRiders() : int{ return 1; }
	public function getSeatPosition(int $seat) : Vector3{ return new Vector3(0, 0.9, 0); }
	public function isControlledByDriver() : bool{ return false; }
	public function canBeCollidedWith() : bool{ return true; }

	public function getInteractButtonText(Player $player) : string{
		return count(RidingManager::getRiders($this)) === 0 ? "action.interact.sit" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() || count(RidingManager::getRiders($this)) > 0){
			return false;
		}
		return RidingManager::mount($player, $this);
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->isFlaggedForDespawn()){
			return;
		}
		$creative = $source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Player && $damager->isCreative();
		if(!$creative && ($item = ExtraVanillaItems::get("minecraft:" . $this->color . "_cushion")) !== null){
			$this->getWorld()->dropItem($this->location, $item);
		}
		$this->flagForDespawn();
	}
}
