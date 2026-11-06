<?php

/*
 * BetterPMMP - taming wolves, cats and parrots.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\particle\HeartParticle;
use Ramsey\Uuid\Uuid;
use function max;
use function mt_rand;

/**
 * Vanilla taming:
 *  - wolf: bones (1 in 3), cat: raw cod or salmon (1 in 3), parrot: seeds (1 in 10)
 *  - on success hearts, the pet sits down and belongs to the player; on failure smoke
 *  - the owner makes it sit or stand up, feeds it to heal (wolf: meat, cat: fish),
 *    dyes the wolf's collar; tamed wolves and cats breed with their food
 *  - a standing pet follows its owner (from 10 blocks, stops at 2) and teleports
 *    to them from 12 blocks; tamed wolves (40 health) attack what the owner hits
 *    or is hit by, except creepers and other pets of the owner
 *  - tamed pets never despawn
 */
trait TameableTrait{

	private bool $tamed = false;
	private bool $sitting = false;
	private ?string $ownerUuid = null;
	private DyeColor $collar = DyeColor::RED;
	private int $petAttackCooldown = 0;
	private int $petOwnerScan = 0;

	/** @return Item[] */
	abstract protected function getTamingItems() : array;
	abstract protected function getTameChance() : int;
	protected function getTamedMaxHealth() : int{ return (int) $this->getMaxHealth(); }
	protected function petAttackDamage() : float{ return 0.0; }

	public function isTamed() : bool{ return $this->tamed; }
	public function isSitting() : bool{ return $this->sitting; }
	public function setSitting(bool $sitting) : void{ $this->sitting = $sitting; $this->networkPropertiesDirty = true; }
	public function getOwnerUuid() : ?string{ return $this->ownerUuid; }

	public function isOwnedBy(Player $player) : bool{
		return $this->tamed && $this->ownerUuid === $player->getUniqueId()->toString();
	}

	public function getOwner() : ?Player{
		if($this->ownerUuid === null){
			return null;
		}
		$owner = $this->getWorld()->getServer()->getPlayerByUUID(Uuid::fromString($this->ownerUuid));
		return $owner !== null && $owner->isOnline() ? $owner : null;
	}

	public function tameBy(Player $player) : void{
		$this->tamed = true;
		$this->ownerUuid = $player->getUniqueId()->toString();
		$this->setOwningEntity($player);
		$this->setSitting(true);
		$this->setMaxHealth($this->getTamedMaxHealth());
		$this->setHealth($this->getMaxHealth());
		if($this instanceof Mob){
			$this->setPersistent();
		}
		$this->networkPropertiesDirty = true;
	}

	protected function initTameable(CompoundTag $nbt) : void{
		$this->tamed = $nbt->getByte("BetterPMMPTamed", 0) === 1;
		$owner = $nbt->getString("BetterPMMPOwner", "");
		$this->ownerUuid = $owner !== "" ? $owner : null;
		$this->sitting = $nbt->getByte("Sitting", 0) === 1;
		$this->collar = DyeColorIdMap::getInstance()->fromId($nbt->getByte("CollarColor", DyeColorIdMap::getInstance()->toId(DyeColor::RED))) ?? DyeColor::RED;
		if($this->tamed){
			$health = $this->getHealth();
			$this->setMaxHealth($this->getTamedMaxHealth());
			$this->setHealth($health);
		}
	}

	protected function saveTameable(CompoundTag $nbt) : CompoundTag{
		return $nbt->setByte("BetterPMMPTamed", $this->tamed ? 1 : 0)
			->setString("BetterPMMPOwner", $this->ownerUuid ?? "")
			->setByte("Sitting", $this->sitting ? 1 : 0)
			->setByte("CollarColor", DyeColorIdMap::getInstance()->toId($this->collar));
	}

	protected function syncTameable(EntityMetadataCollection $properties) : void{
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->tamed);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
		if($this->tamed && $this->usesCollar()){
			$properties->setByte(EntityMetadataProperties::COLOR, DyeColorIdMap::getInstance()->toId($this->collar));
		}
	}

	protected function usesCollar() : bool{ return false; }

	private static function matches(Item $item, array $list) : bool{
		foreach($list as $candidate){
			if($item->equals($candidate, false, false)){
				return true;
			}
		}
		return false;
	}

	private function useOne(Player $player, Item $held) : void{
		if($player->hasFiniteResources()){
			$held->pop();
			$player->getInventory()->setItemInHand($held);
		}
	}

	private function broadcastActorEvent(int $event) : void{
		foreach($this->getViewers() as $viewer){
			$viewer->getNetworkSession()->sendDataPacket(ActorEventPacket::create($this->getId(), $event, 0));
		}
	}

	/** Returns null when the interaction is not about taming (the caller goes on with breeding etc.). */
	protected function tameableInteract(Player $player) : ?bool{
		if(!$this->isAlive()){
			return null;
		}
		$held = $player->getInventory()->getItemInHand();
		if(!$this->tamed){
			if(!self::matches($held, $this->getTamingItems())){
				return null;
			}
			$this->useOne($player, $held);
			if(mt_rand(1, $this->getTameChance()) === 1){
				$this->tameBy($player);
				$this->broadcastActorEvent(ActorEvent::TAME_SUCCESS);
				$this->getWorld()->addParticle($this->getPosition()->add(0, $this->getEyeHeight(), 0), new HeartParticle());
			}else{
				$this->broadcastActorEvent(ActorEvent::TAME_FAIL);
			}
			return true;
		}
		if(!$this->isOwnedBy($player)){
			return null;
		}
		$this->setOwningEntity($player);
		if($this instanceof BreedableAnimal && self::matches($held, $this->getBreedingFoods())){
			if($this->getHealth() < $this->getMaxHealth()){
				$this->useOne($player, $held);
				$this->heal(new \pocketmine\event\entity\EntityRegainHealthEvent($this, 4, \pocketmine\event\entity\EntityRegainHealthEvent::CAUSE_EATING));
				return true;
			}
			return null; // full health: breeding
		}
		if($this->usesCollar() && $held instanceof Dye){
			if($held->getColor() !== $this->collar){
				$this->collar = $held->getColor();
				$this->useOne($player, $held);
				$this->networkPropertiesDirty = true;
			}
			return true;
		}
		$this->setSitting(!$this->sitting);
		if($this instanceof Mob){
			$this->getNavigator()->stop();
		}
		return true;
	}

	/** The button text for touch controls (sit / stand / tame). */
	public function getInteractButtonText(Player $player) : string{
		if($this->tamed){
			return $this->isOwnedBy($player) ? ($this->sitting ? "action.interact.stand" : "action.interact.sit") : "";
		}
		return self::matches($player->getInventory()->getItemInHand(), $this->getTamingItems()) ? "action.interact.tame" : "";
	}

	/** Pet behaviour for one tick; returns true when it handled the movement. */
	protected function tickPet(int $tickDiff) : bool{
		if(!$this->tamed){
			return false;
		}
		if($this->sitting){
			if($this instanceof Mob){
				$this->getNavigator()->stop();
			}
			$motion = $this->getMotion();
			$this->setMotion(new Vector3(0, $motion->y, 0));
			return true;
		}
		$owner = $this->getOwner();
		if($owner === null || $owner->getWorld() !== $this->getWorld() || $owner->isSpectator()){
			return false;
		}
		if($this->getOwningEntityId() !== $owner->getId()){
			$this->setOwningEntity($owner);
		}
		$this->petAttackCooldown = max(0, $this->petAttackCooldown - $tickDiff);

		// tamed wolves defend their owner
		if($this->petAttackDamage() > 0){
			$target = PetTargets::get($owner);
			if($target !== null && $target->getWorld() === $this->getWorld() && !$target instanceof GenericCreeperMob
				&& $target->getPosition()->distanceSquared($this->getPosition()) < 16 * 16){
				$distance = $target->getPosition()->distanceSquared($this->getPosition());
				if($distance < 2.5 * 2.5 && $this->petAttackCooldown === 0){
					$this->petAttackCooldown = 20;
					$target->attack(new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->petAttackDamage()));
				}
				$this->moveToward($target->getPosition(), true);
				return true;
			}
		}

		$distance = $owner->getPosition()->distanceSquared($this->getPosition());
		if($distance > 12 * 12 && $owner->isOnGround()){
			// teleport next to the owner
			$this->teleport($owner->getPosition()->add(mt_rand(-1, 1), 0, mt_rand(-1, 1)));
			return true;
		}
		if($distance > 10 * 10 || ($distance > 2 * 2 && $this instanceof Mob && $this->getNavigator()->isMoving())){
			$this->moveToward($owner->getPosition(), false);
			return true;
		}
		if($this instanceof Mob && $this->getNavigator()->isMoving()){
			$this->getNavigator()->stop();
		}
		return $distance < 6 * 6;
	}

	private function moveToward(Vector3 $pos, bool $chasing) : void{
		if($this instanceof GenericFreeMotionMob){
			$d = $pos->add(0, 1, 0)->subtractVector($this->getPosition());
			$len = max(0.001, $d->length());
			$this->setMotion($d->multiply(0.25 / $len));
			return;
		}
		if($this instanceof Mob){
			$navigator = $this->getNavigator();
			$navigator->moveTo($pos, $this->getWalkSpeed(true), 1.5);
			$navigator->tick(1);
		}
	}
}
