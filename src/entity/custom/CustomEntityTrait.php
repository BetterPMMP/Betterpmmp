<?php

/*
 * BetterPMMP - what all custom entities share: network id, name, size, health and drops from the type.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\custom;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\entity\Attribute;
use pocketmine\player\Player;
use function array_map;
use function array_values;
use function mt_rand;

trait CustomEntityTrait{
	private CustomEntityType $type;

	public function __construct(Location $location, ?CompoundTag $nbt, CustomEntityType $type){
		$this->type = $type; //needed by getInitialSizeInfo() during the parent constructor
		parent::__construct($location, $nbt);
	}

	public function getCustomType() : CustomEntityType{
		return $this->type;
	}

	public function getIdentifier() : string{
		return $this->type->identifier;
	}

	public function getName() : string{
		return $this->type->name;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo($this->type->height, $this->type->width);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth($this->type->health);
		//new entities start with full health, saved ones keep theirs (at most the maximum)
		$this->setHealth($nbt->getTag("Health") === null ? $this->type->health : min($this->getHealth(), $this->type->health));
		$this->setMovementSpeed($this->type->speed);
	}

	public function saveNBT() : CompoundTag{
		//all custom entities share a class, the id comes from the type
		return parent::saveNBT()->setString("identifier", $this->type->identifier);
	}

	protected function baseSpeedAttribute() : float{
		return $this->type->speed;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->type->fireproof && in_array($source->getCause(), [EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK, EntityDamageEvent::CAUSE_LAVA], true)){
			$source->cancel();
		}
		parent::attack($source);
	}

	public function getDrops() : array{
		$drops = [];
		foreach($this->type->drops as [$name, $min, $max]){
			$item = StringToItemParser::getInstance()->parse($name);
			$count = mt_rand($min, $max);
			if($item !== null && $count > 0){
				$drops[] = $item->setCount($count);
			}
		}
		return $drops;
	}

	public function getPickedItem() : ?\pocketmine\item\Item{
		return null; //no spawn egg
	}

	public function getXpDropAmount() : int{
		return $this->type->experience;
	}

	protected function sendSpawnPacket(Player $player) : void{
		$player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
			$this->getId(),
			$this->getId(),
			$this->type->identifier,
			$this->getOffsetPosition($this->location->asVector3()),
			$this->getMotion(),
			$this->location->pitch,
			$this->location->yaw,
			$this->location->yaw,
			$this->location->yaw,
			array_map(fn(Attribute $attr) => new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue()), array_values($this->attributeMap->getAll())),
			$this->getAllNetworkData(),
			new PropertySyncData([], []),
			[],
			null
		));
	}
}
