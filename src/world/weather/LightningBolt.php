<?php

/*
 * BetterPMMP - lightning bolt.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\weather;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\LightningRod;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\GenericCreeperMob;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\Pig;
use pocketmine\entity\Villager;
use pocketmine\entity\WitchMob;
use pocketmine\entity\ZombiePigmanMob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\World;

/**
 * Vanilla lightning: thunder for everyone in the world, 5 damage and 8 s of
 * fire to everything within 3 blocks, fire on the ground (Normal and Hard),
 * creepers become charged, pigs zombified piglins, villagers witches, items
 * burn. A lightning rod takes the strike: no fire, redstone signal.
 */
class LightningBolt extends Entity implements NeverSavedWithChunkEntity{

	private const LIFETIME = 10;
	private bool $struck = false;

	public static function getNetworkTypeId() : string{ return EntityIds::LIGHTNING_BOLT; }

	public function __construct(Location $location, private ?Entity $cause = null, ?CompoundTag $nbt = null){
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.0, 0.0); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.0; }
	public function canBeCollidedWith() : bool{ return false; }
	public function canSaveWithChunk() : bool{ return false; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		parent::entityBaseTick($tickDiff);
		if(!$this->struck){
			$this->struck = true;
			$this->strike();
		}
		if($this->ticksLived >= self::LIFETIME){
			$this->flagForDespawn();
		}
		return true;
	}

	private function strike() : void{
		$world = $this->getWorld();
		$pos = $this->location;
		$pk = LevelSoundEventPacket::nonActorSound(LevelSoundEvent::THUNDER, $pos, true);
		foreach($world->getPlayers() as $player){
			$player->getNetworkSession()->sendDataPacket($pk);
		}
		$world->addSound($pos, new \pocketmine\world\sound\VanillaSound(LevelSoundEvent::EXPLODE));

		$below = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY() - 1, $pos->getFloorZ());
		if($below instanceof LightningRod){
			$below->onLightningStrike();
		}elseif($world->getDifficulty() >= World::DIFFICULTY_NORMAL){
			$fireAt = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if($fireAt->getTypeId() === BlockTypeIds::AIR && $below->isSolid()){
				$world->setBlock($fireAt->getPosition(), VanillaBlocks::FIRE());
			}
		}

		$box = new AxisAlignedBB($pos->x - 3, $pos->y - 3, $pos->z - 3, $pos->x + 3, $pos->y + 6 + 3, $pos->z + 3);
		foreach($world->getNearbyEntities($box, $this) as $entity){
			if($entity instanceof ItemEntity){
				$entity->flagForDespawn();
				continue;
			}
			if(!$entity instanceof Living || !$entity->isAlive()){
				continue;
			}
			if($this->convert($entity)){
				continue;
			}
			$ev = $this->cause !== null
				? new EntityDamageByEntityEvent($this->cause, $entity, EntityDamageEvent::CAUSE_CUSTOM, 5)
				: new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_CUSTOM, 5);
			$entity->attack($ev);
			$entity->setOnFire(8);
		}
	}

	/** Lightning transformations; returns true if the mob was replaced. */
	private function convert(Living $entity) : bool{
		if($entity instanceof GenericCreeperMob){
			$entity->setPowered();
			return false;
		}
		$class = match(true){
			$entity instanceof Pig => ZombiePigmanMob::class,
			$entity instanceof Villager => WitchMob::class,
			default => null
		};
		if($class === null){
			return false;
		}
		$new = new $class($entity->getLocation());
		if($entity->getNameTag() !== ""){
			$new->setNameTag($entity->getNameTag());
		}
		$entity->flagForDespawn();
		$new->spawnToAll();
		return true;
	}
}
