<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use pocketmine\event\entity\EntityPreExplodeEvent;

/** Shared native representation for Bedrock entities not yet specialized. */
abstract class GenericVanillaMob extends Mob{
	protected const ENTITY_ID = "";
	protected const DISPLAY_NAME = "Mob";
	protected const SPAWN_EGG_GETTER = "";
	protected const WIDTH = 0.6;
	protected const HEIGHT = 1.8;
	public static function getNetworkTypeId() : string{ return static::ENTITY_ID; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ $stats = \pocketmine\entity\ai\MobStats::get(static::ENTITY_ID); return $stats !== null ? new EntitySizeInfo($stats[5], $stats[4]) : new EntitySizeInfo(static::HEIGHT, static::WIDTH); }
	protected function initEntity(CompoundTag $nbt) : void{
		$stats = \pocketmine\entity\ai\MobStats::get(static::ENTITY_ID);
		if($stats !== null){
			$this->setMaxHealth($stats[0]);
			$this->setMovementSpeed($stats[1]);
		}
		parent::initEntity($nbt);
	}
	protected function getInitialGravity() : float{ return static::GRAVITY; }
	protected const GRAVITY = 0.08;
	public function getName() : string{ return static::DISPLAY_NAME; }
	public function getPickedItem() : ?Item{ $getter = static::SPAWN_EGG_GETTER; return VanillaItems::$getter(); }
	/** @return Item[] */
	public function getDrops() : array{
		$drops = [];
		switch(static::ENTITY_ID){
			case EntityIds::BLAZE: self::appendRandomDrop($drops, VanillaItems::BLAZE_ROD(), 0, 1); break;
			case EntityIds::BOGGED:
			case EntityIds::PARCHED:
			case EntityIds::SKELETON:
			case EntityIds::STRAY:
			case EntityIds::WITHER_SKELETON: self::appendRandomDrop($drops, VanillaItems::BONE(), 0, 2); self::appendRandomDrop($drops, VanillaItems::ARROW(), 0, 2); if(static::ENTITY_ID === EntityIds::WITHER_SKELETON && mt_rand(1, 1000) <= 25){ $drops[] = VanillaBlocks::MOB_HEAD()->setMobHeadType(\pocketmine\block\utils\MobHeadType::WITHER_SKELETON)->asItem(); } break;
			case EntityIds::CREEPER: self::appendRandomDrop($drops, VanillaItems::GUNPOWDER(), 0, 2); break;
			case EntityIds::COD:
			case EntityIds::DOLPHIN:
			case EntityIds::POLAR_BEAR:
			case EntityIds::PUFFERFISH:
			case EntityIds::TROPICALFISH: self::appendRandomDrop($drops, VanillaItems::RAW_FISH(), 0, 1); break;
			case EntityIds::SALMON: self::appendRandomDrop($drops, VanillaItems::RAW_SALMON(), 0, 1); break;
			case EntityIds::DROWNED:
			case EntityIds::HUSK:
			case EntityIds::ZOMBIE:
			case EntityIds::ZOMBIE_VILLAGER: self::appendRandomDrop($drops, VanillaItems::ROTTEN_FLESH(), 0, 2); if(static::ENTITY_ID === EntityIds::DROWNED && mt_rand(0, 99) < 5){ self::appendRandomDrop($drops, VanillaItems::COPPER_INGOT(), 1, 1); } break;
			case EntityIds::ENDERMAN: self::appendRandomDrop($drops, VanillaItems::ENDER_PEARL(), 0, 1); break;
			case EntityIds::GHAST: self::appendRandomDrop($drops, VanillaItems::GHAST_TEAR(), 0, 1); self::appendRandomDrop($drops, VanillaItems::GUNPOWDER(), 0, 2); break;
			case EntityIds::GLOW_SQUID: self::appendRandomDrop($drops, VanillaItems::GLOW_INK_SAC(), 1, 3); break;
			case EntityIds::GUARDIAN:
			case EntityIds::ELDER_GUARDIAN: self::appendRandomDrop($drops, VanillaItems::PRISMARINE_SHARD(), 0, 2); if(mt_rand(0, 3) === 0){ self::appendRandomDrop($drops, VanillaItems::PRISMARINE_CRYSTALS(), 1, 1); } break;
			case EntityIds::GOAT: self::appendRandomDrop($drops, VanillaItems::RAW_MUTTON(), 0, 2); break;
			case EntityIds::HOGLIN:
			case EntityIds::ZOGLIN: self::appendRandomDrop($drops, VanillaItems::RAW_PORKCHOP(), 1, 3); break;
			case EntityIds::CAMEL:
			case EntityIds::HORSE:
			case EntityIds::LLAMA:
			case EntityIds::TRADER_LLAMA:
			case EntityIds::DONKEY:
			case EntityIds::MULE:
			case EntityIds::SKELETON_HORSE:
			case EntityIds::ZOMBIE_HORSE: self::appendRandomDrop($drops, VanillaItems::LEATHER(), 0, 2); break;
			case EntityIds::IRON_GOLEM: self::appendRandomDrop($drops, VanillaItems::IRON_INGOT(), 3, 5); break;
			case EntityIds::MAGMA_CUBE: self::appendRandomDrop($drops, VanillaItems::MAGMA_CREAM(), 0, 1); break;
			case EntityIds::MOOSHROOM: self::appendRandomDrop($drops, VanillaItems::RAW_BEEF(), 1, 3); self::appendRandomDrop($drops, VanillaItems::LEATHER(), 0, 2); break;
			case EntityIds::PHANTOM: self::appendRandomDrop($drops, VanillaItems::PHANTOM_MEMBRANE(), 0, 1); break;
			case EntityIds::PANDA: self::appendRandomDrop($drops, VanillaItems::BAMBOO(), 0, 1); break;
			case EntityIds::PARROT: self::appendRandomDrop($drops, VanillaItems::FEATHER(), 1, 2); break;
			case EntityIds::PIGLIN:
			case EntityIds::PIGLIN_BRUTE:
			case EntityIds::ZOMBIE_PIGMAN: self::appendRandomDrop($drops, VanillaItems::GOLD_NUGGET(), 0, 2); break;
			case EntityIds::RABBIT: self::appendRandomDrop($drops, VanillaItems::RAW_RABBIT(), 0, 1); self::appendRandomDrop($drops, VanillaItems::RABBIT_HIDE(), 0, 1); if(mt_rand(0, 9) === 0){ self::appendRandomDrop($drops, VanillaItems::RABBIT_FOOT(), 1, 1); } break;
			case EntityIds::SHULKER: self::appendRandomDrop($drops, VanillaItems::SHULKER_SHELL(), 0, 1); break;
			case EntityIds::SLIME: self::appendRandomDrop($drops, VanillaItems::SLIMEBALL(), 0, 2); break;
			case EntityIds::STRIDER: self::appendRandomDrop($drops, VanillaItems::STRING(), 0, 2); break;
			case EntityIds::SPIDER:
			case EntityIds::CAVE_SPIDER: self::appendRandomDrop($drops, VanillaItems::STRING(), 0, 2); if(mt_rand(0, 2) === 0){ self::appendRandomDrop($drops, VanillaItems::SPIDER_EYE(), 1, 1); } break;
			case EntityIds::ZOMBIE_NAUTILUS: self::appendRandomDrop($drops, VanillaItems::NAUTILUS_SHELL(), 0, 1); break;
			case EntityIds::WITHER: self::appendRandomDrop($drops, VanillaItems::NETHER_STAR(), 1, 1); break;
			case EntityIds::EVOCATION_ILLAGER:
			case EntityIds::PILLAGER:
			case EntityIds::VINDICATOR: self::appendRandomDrop($drops, VanillaItems::EMERALD(), 0, 1); break;
			case EntityIds::WITCH: $witchDrops = [VanillaItems::REDSTONE_DUST(), VanillaItems::GLOWSTONE_DUST(), VanillaItems::SUGAR(), VanillaItems::STICK(), VanillaItems::GLASS_BOTTLE()]; $drops[] = $witchDrops[mt_rand(0, count($witchDrops) - 1)]; break;
			case EntityIds::ENDER_DRAGON: break;
			default: break;
		}
		return $drops;
	}
	private static function appendRandomDrop(array &$drops, Item $item, int $minCount, int $maxCount) : void{ $count = mt_rand($minCount, $maxCount); if($count > 0){ $drops[] = $item->setCount($count); } }
	public function getXpDropAmount() : int{ return match(static::ENTITY_ID){ EntityIds::ENDER_DRAGON, EntityIds::WITHER => 50, EntityIds::BLAZE, EntityIds::BOGGED, EntityIds::CAVE_SPIDER, EntityIds::CREEPER, EntityIds::DROWNED, EntityIds::ENDERMAN, EntityIds::EVOCATION_ILLAGER, EntityIds::GHAST, EntityIds::GUARDIAN, EntityIds::MAGMA_CUBE, EntityIds::PHANTOM, EntityIds::PIGLIN, EntityIds::PIGLIN_BRUTE, EntityIds::PILLAGER, EntityIds::RAVAGER, EntityIds::SKELETON, EntityIds::SLIME, EntityIds::SPIDER, EntityIds::VINDICATOR, EntityIds::WITCH, EntityIds::WITHER_SKELETON, EntityIds::ZOMBIE, EntityIds::ZOMBIE_PIGMAN => mt_rand(1, 5), default => mt_rand(1, 3) }; }
}

abstract class GenericAnimalMob extends GenericVanillaMob implements BreedableAnimal{
	use AnimalBreedingTrait;
	protected function initEntity(\pocketmine\nbt\tag\CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initBreedingState($nbt); }
	public function saveNBT() : \pocketmine\nbt\tag\CompoundTag{ return $this->saveBreedingState(parent::saveNBT()); }
	protected function entityBaseTick(int $tickDiff = 1) : bool{ $hasUpdate = parent::entityBaseTick($tickDiff); if($this->isAlive()){ $this->tickBreedingState($tickDiff); } return $hasUpdate; }
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $this->syncBreedingNetworkData($properties); }
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return true; }
	protected function tickWandering(int $tickDiff) : void{ if($this->isPanicking() || !$this->tickAnimalGoals($tickDiff)){ parent::tickWandering($tickDiff); } }
}

abstract class GenericFreeMotionMob extends GenericVanillaMob{
	private int $freeMotionTicks = 0;
	private int $fleeMotionTicks = 0;
	private float $fleeX = 0.0;
	private float $fleeY = 0.08;
	private float $fleeZ = 0.0;
	protected const GRAVITY = 0.0;
	protected function tickWandering(int $tickDiff) : void{
		if($this->fleeMotionTicks > 0){ $this->fleeMotionTicks = max(0, $this->fleeMotionTicks - $tickDiff); $horizontal = min(0.22, max(0.12, $this->getMovementSpeed() * 1.6)); $this->setMotion(new \pocketmine\math\Vector3($this->fleeX * $horizontal, $this->fleeY, $this->fleeZ * $horizontal)); $this->setRotation(rad2deg(atan2(-$this->fleeX, $this->fleeZ)), -rad2deg(atan2($this->fleeY, $horizontal))); return; }
		$this->freeMotionTicks -= $tickDiff; if($this->freeMotionTicks > 0){ return; } $this->freeMotionTicks = mt_rand(15, 45);
		$yaw = mt_rand(0, 359); $pitch = mt_rand(-20, 20); $horizontal = min(0.16, max(0.04, $this->getMovementSpeed())); $motion = $this->getMotion();
		$dx = sin(deg2rad((float) $yaw)); $dz = cos(deg2rad((float) $yaw)); $dy = sin(deg2rad((float) $pitch)) * 0.12;
		$this->setMotion(new \pocketmine\math\Vector3($dx * $horizontal, $dy + ($motion->y * 0.15), $dz * $horizontal)); $this->setRotation((float) $yaw, (float) -$pitch);
	}
	protected function fleeFromAttacker(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($source->isCancelled() || !($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent)){ return; } $attacker = $source->getDamager(); if($attacker === null){ return; }
		$dx = $this->getPosition()->x - $attacker->getPosition()->x; $dy = $this->getPosition()->y - $attacker->getPosition()->y; $dz = $this->getPosition()->z - $attacker->getPosition()->z; $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz)); if($length < 0.0001){ $dx = 1.0; $dy = 0.2; $dz = 0.0; $length = 1.0; }
		$this->fleeX = $dx / $length; $this->fleeY = max(0.08, ($dy / $length) * 0.2); $this->fleeZ = $dz / $length; $this->fleeMotionTicks = 60; $this->freeMotionTicks = 0; $horizontal = min(0.22, max(0.12, $this->getMovementSpeed() * 1.6)); $this->setMotion(new \pocketmine\math\Vector3($this->fleeX * $horizontal, $this->fleeY, $this->fleeZ * $horizontal));
	}
	protected function isWaterAt(float $x, float $y, float $z) : bool{ $world = $this->getWorld(); $blockX = (int) floor($x); $blockY = (int) floor($y); $blockZ = (int) floor($z); if($blockY < $world->getMinY() || $blockY + 1 >= $world->getMaxY() || !$world->isChunkLoaded($blockX >> 4, $blockZ >> 4)){ return false; } return $world->getBlockAt($blockX, $blockY, $blockZ)->getTypeId() === \pocketmine\block\BlockTypeIds::WATER && !$world->getBlockAt($blockX, $blockY + 1, $blockZ)->isSolid(); }
	protected function constrainToWater() : void{
		$position = $this->getPosition(); $motion = $this->getMotion(); if($this->isWaterAt($position->x, $position->y, $position->z) && $this->isWaterAt($position->x + $motion->x * 3, $position->y + $motion->y * 3, $position->z + $motion->z * 3)){ return; }
		$best = null; $bestScore = -1.0e9; foreach([[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]] as [$dx, $dy, $dz]){ if(!$this->isWaterAt($position->x + $dx, $position->y + $dy, $position->z + $dz)){ continue; } $score = ($dx * $motion->x) + ($dy * $motion->y) + ($dz * $motion->z) + (mt_rand(0, 100) / 10000); if($score > $bestScore){ $bestScore = $score; $best = [$dx, $dy, $dz]; } }
		if($best === null){ $this->setMotion(new \pocketmine\math\Vector3(0.0, 0.0, 0.0)); return; } [$dx, $dy, $dz] = $best; $speed = min(0.16, max(0.08, $this->getMovementSpeed())); $this->setMotion(new \pocketmine\math\Vector3($dx * $speed, $dy * $speed, $dz * $speed)); if($dx !== 0 || $dz !== 0){ $this->setRotation(rad2deg(atan2(-$dx, $dz)), -rad2deg(atan2($dy, sqrt(($dx * $dx) + ($dz * $dz))))); }
	}
}

abstract class GenericFlyingAnimalMob extends GenericFreeMotionMob{
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return true; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{ parent::attack($source); if($this->isAlive()){ $this->fleeFromAttacker($source); } }
}

abstract class GenericSwimmingAnimalMob extends GenericFreeMotionMob{
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return true; }
	protected function tickWandering(int $tickDiff) : void{ parent::tickWandering($tickDiff); $this->constrainToWater(); }
	public function canBreathe() : bool{ return $this->isUnderwater(); }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{ parent::attack($source); if($this->isAlive()){ $this->fleeFromAttacker($source); } }
}

abstract class GenericHostileFlyingMob extends GenericFreeMotionMob{
	protected function tickWandering(int $tickDiff) : void{ $target = $this->findTarget(); if($target === null){ parent::tickWandering($tickDiff); return; } $this->moveTowardTarget($target); }
	protected function findTarget() : ?\pocketmine\player\Player{ $target = null; $closest = 14 * 14; foreach($this->getWorld()->getPlayers() as $player){ if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){ continue; } $distance = $this->getPosition()->distanceSquared($player->getPosition()); if($distance < $closest){ $target = $player; $closest = $distance; } } return $target; }
	protected function moveTowardTarget(\pocketmine\player\Player $target) : void{ $dx = $target->getPosition()->x - $this->getPosition()->x; $dy = $target->getPosition()->y - $this->getPosition()->y; $dz = $target->getPosition()->z - $this->getPosition()->z; $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz)); if($length <= 1.5 && $this->attackTime <= 0){ $this->attackTime = 20; $target->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3)); return; } if($length > 0.001){ $this->setMotion(new \pocketmine\math\Vector3($dx / $length * 0.15, $dy / $length * 0.12, $dz / $length * 0.15)); } }
}

abstract class GenericHostileSwimmingMob extends GenericHostileFlyingMob{ protected function tickWandering(int $tickDiff) : void{ parent::tickWandering($tickDiff); $this->constrainToWater(); } public function canBreathe() : bool{ return $this->isUnderwater(); } }

abstract class GenericHostileMob extends GenericVanillaMob{
	use \pocketmine\entity\ai\CombatAI;
	protected function findTarget(int $tickDiff = 1) : ?Living{ return $this->updateCombatTarget($tickDiff); }
	protected function hasLineOfSight(\pocketmine\math\Vector3 $start, \pocketmine\math\Vector3 $end) : bool{ $world = $this->getWorld(); $startBlock = $start->floor(); $endBlock = $end->floor(); foreach(\pocketmine\math\VoxelRayTrace::betweenPoints($start, $end) as $blockPos){ if($blockPos->equals($startBlock) || $blockPos->equals($endBlock)){ continue; } $x = $blockPos->getFloorX(); $z = $blockPos->getFloorZ(); if(!$world->isChunkLoaded($x >> 4, $z >> 4) || $world->getBlockAt($x, $blockPos->getFloorY(), $z)->isSolid()){ return false; } } return true; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{ parent::attack($source); if($this->isAlive()){ $this->onCombatHurt($source); } }
	protected function tickWandering(int $tickDiff) : void{
		$target = $this->findTarget($tickDiff);
		if($target === null){ parent::tickWandering($tickDiff); return; }
		$this->tickMelee($target, $tickDiff);
	}
}

abstract class GenericSpiderMob extends GenericHostileMob{
	protected function tickWandering(int $tickDiff) : void{
		if(!$this->canClimbWalls()){ $this->setCanClimbWalls(true); }
		$target = $this->findTarget($tickDiff);
		if($target === null){ parent::tickWandering($tickDiff); return; }
		$this->tickMelee($target, $tickDiff);
		// spiders climb straight up walls that block their way
		if($this->isCollidedHorizontally){ $motion = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3($motion->x, 0.2, $motion->z)); }
	}
}

abstract class GenericCreeperMob extends GenericHostileMob{
	private const FUSE_DURATION_TICKS = 30;
	private int $fuseTicks = -1;
	protected function tickWandering(int $tickDiff) : void{
		$target = $this->findTarget($tickDiff); if($target === null){ if($this->fuseTicks !== -1){ $this->fuseTicks = -1; $this->networkPropertiesDirty = true; } parent::tickWandering($tickDiff); return; }
		$distanceSquared = $this->getPosition()->distanceSquared($target->getPosition()); $visible = $this->hasLineOfSight($this->getEyePos(), $target->getEyePos());
		if($this->fuseTicks >= 0){ if($distanceSquared > 49 || !$visible){ $this->fuseTicks = -1; $this->networkPropertiesDirty = true; }else{ $this->fuseTicks += $tickDiff; $this->networkPropertiesDirty = true; if($this->fuseTicks >= self::FUSE_DURATION_TICKS){ $this->detonate(); return; } $motion = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3(0, $motion->y, 0)); return; } }
		if($distanceSquared <= 9 && $visible){ $this->fuseTicks = 0; $this->networkPropertiesDirty = true; $motion = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3(0, $motion->y, 0)); return; }
		parent::tickWandering($tickDiff);
	}
	private function detonate() : void{
		$event = new \pocketmine\event\entity\EntityPreExplodeEvent($this, 3.0); $event->call(); if($event->isCancelled()){ $this->fuseTicks = -1; $this->networkPropertiesDirty = true; return; }
		$this->flagForDespawn(); $explosion = new \pocketmine\world\Explosion(\pocketmine\world\Position::fromObject($this->getPosition()->add(0, 0.85, 0), $this->getWorld()), $event->getRadius(), $this, $event->getFireChance()); if($event->isBlockBreaking()){ $explosion->explodeA(); } $explosion->explodeB();
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->fuseTicks >= 0); $properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuseTicks < 0 ? 0 : max(0, self::FUSE_DURATION_TICKS - $this->fuseTicks)); }
}

abstract class GenericRangedHostileMob extends GenericHostileMob{
	/** The weapon shown in the mob's hand. */
	protected function heldWeapon() : Item{ return VanillaItems::BOW(); }
	private int $strafeTicks = 0;
	private int $strafeDirection = 1;
	public function spawnTo(Player $player) : void{
		if(!$player->isConnected()){
			return;
		}
		parent::spawnTo($player);
		if($player->getWorld() !== $this->getWorld() || !isset($this->getViewers()[spl_object_id($player)])){
			return;
		}
		$session = $player->getNetworkSession();
		$session->sendDataPacket(MobEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($this->heldWeapon())),
			0,
			0,
			ContainerIds::INVENTORY
		));
	}

	protected function tickWandering(int $tickDiff) : void{
		$target = $this->findTarget($tickDiff); if($target === null){ parent::tickWandering($tickDiff); return; }
		$dx = $target->getPosition()->x - $this->getPosition()->x; $dz = $target->getPosition()->z - $this->getPosition()->z; $horizontal = sqrt(($dx * $dx) + ($dz * $dz));
		if($horizontal > 0.001){ $this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0); } $lineOfSight = $this->hasLineOfSight($this->getEyePos(), $target->getEyePos());
		// RangedBowAttackGoal: close in until within 15 blocks with sight, back off when too close, strafe otherwise
		$navigator = $this->getNavigator();
		if(!$lineOfSight || $horizontal > 12.0){ $navigator->moveTo($target->getPosition(), $this->getWalkSpeed(true), 2.0); $navigator->tick($tickDiff); }
		else{ $navigator->stop(); if($horizontal < 5.0 && $this->isOnGround()){ $this->moveGroundToward(-$dx, -$dz, $this->getWalkSpeed(true)); } elseif($this->isOnGround()){ $this->strafeTicks += $tickDiff; if($this->strafeTicks >= 20){ $this->strafeTicks = 0; if(mt_rand(0, 9) < 3){ $this->strafeDirection = -$this->strafeDirection; } } $this->moveGroundToward(-$dz * $this->strafeDirection, $dx * $this->strafeDirection, $this->getWalkSpeed() * 0.6); } }
		if($lineOfSight && $horizontal >= 5.0 && $horizontal <= 14.0 && $this->attackTime <= 0){
			$origin = $this->getEyePos(); $targetEye = $target->getEyePos(); $aimX = $targetEye->x - $origin->x; $aimY = $targetEye->y - $origin->y + ($horizontal * 0.04); $aimZ = $targetEye->z - $origin->z; $yaw = rad2deg(atan2(-$aimX, $aimZ)); $pitch = rad2deg(-atan2($aimY, $horizontal));
			$arrow = new \pocketmine\entity\projectile\Arrow(new \pocketmine\entity\Location($origin->x, $origin->y, $origin->z, $this->getWorld(), $yaw, $pitch), $this, false); $arrow->setBaseDamage(1.5); $arrow->setMotion((new \pocketmine\math\Vector3($aimX, $aimY, $aimZ))->normalize()->multiply(1.65)); $arrow->spawnToAll(); $this->attackTime = mt_rand(30, 45);
		}
	}
}

class AgentMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::AGENT;
	protected const DISPLAY_NAME = "Agent";
	protected const SPAWN_EGG_GETTER = "AGENT_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class AllayMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::ALLAY;
	protected const DISPLAY_NAME = "Allay";
	protected const SPAWN_EGG_GETTER = "ALLAY_SPAWN_EGG";
	protected const HEIGHT = 0.35;
	protected const WIDTH = 0.6;
}

class ArmadilloMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::ARMADILLO;
	protected const DISPLAY_NAME = "Armadillo";
	protected const SPAWN_EGG_GETTER = "ARMADILLO_SPAWN_EGG";
	protected const HEIGHT = 0.7;
	protected const WIDTH = 0.65;
}

class AxolotlMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::AXOLOTL;
	protected const DISPLAY_NAME = "Axolotl";
	protected const SPAWN_EGG_GETTER = "AXOLOTL_SPAWN_EGG";
	protected const HEIGHT = 0.75;
	protected const WIDTH = 0.42;
}

class BatMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::BAT;
	protected const DISPLAY_NAME = "Bat";
	protected const SPAWN_EGG_GETTER = "BAT_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.9;
}

class BeeMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::BEE;
	protected const DISPLAY_NAME = "Bee";
	protected const SPAWN_EGG_GETTER = "BEE_SPAWN_EGG";
	protected const HEIGHT = 0.55;
	protected const WIDTH = 0.6;
}

class BlazeMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::BLAZE;
	protected const DISPLAY_NAME = "Blaze";
	protected const SPAWN_EGG_GETTER = "BLAZE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class BoggedMob extends GenericRangedHostileMob{
	protected const ENTITY_ID = EntityIds::BOGGED;
	protected const DISPLAY_NAME = "Bogged";
	protected const SPAWN_EGG_GETTER = "BOGGED_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class BreezeMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::BREEZE;
	protected const DISPLAY_NAME = "Breeze";
	protected const SPAWN_EGG_GETTER = "BREEZE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class CamelMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "camel"; }
	protected const ENTITY_ID = EntityIds::CAMEL;
	protected const DISPLAY_NAME = "Camel";
	protected const SPAWN_EGG_GETTER = "CAMEL_SPAWN_EGG";
	protected const HEIGHT = 1.7;
	protected const WIDTH = 2.375;
}

class CamelHuskMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::CAMEL_HUSK;
	protected const DISPLAY_NAME = "Camel Husk";
	protected const SPAWN_EGG_GETTER = "CAMEL_HUSK_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class CatMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::CAT;
	protected const DISPLAY_NAME = "Cat";
	protected const SPAWN_EGG_GETTER = "CAT_SPAWN_EGG";
	protected const HEIGHT = 0.6;
	protected const WIDTH = 0.7;
}

class CaveSpiderMob extends GenericSpiderMob{
	protected const ENTITY_ID = EntityIds::CAVE_SPIDER;
	protected const DISPLAY_NAME = "CaveSpider";
	protected const SPAWN_EGG_GETTER = "CAVE_SPIDER_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.7;
}

class CodMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::COD;
	protected const DISPLAY_NAME = "Cod";
	protected const SPAWN_EGG_GETTER = "COD_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.3;
}

class CreakingMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::CREAKING;
	protected const DISPLAY_NAME = "Creaking";
	protected const SPAWN_EGG_GETTER = "CREAKING_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class CreeperMob extends GenericCreeperMob{
	protected const ENTITY_ID = EntityIds::CREEPER;
	protected const DISPLAY_NAME = "Creeper";
	protected const SPAWN_EGG_GETTER = "CREEPER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class DolphinMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::DOLPHIN;
	protected const DISPLAY_NAME = "Dolphin";
	protected const SPAWN_EGG_GETTER = "DOLPHIN_SPAWN_EGG";
	protected const HEIGHT = 0.9;
	protected const WIDTH = 0.6;
}

class DonkeyMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "horse"; }
	protected const ENTITY_ID = EntityIds::DONKEY;
	protected const DISPLAY_NAME = "Donkey";
	protected const SPAWN_EGG_GETTER = "DONKEY_SPAWN_EGG";
	protected const HEIGHT = 1.4;
	protected const WIDTH = 1.6;
}

class DrownedMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::DROWNED;
	protected const DISPLAY_NAME = "Drowned";
	protected const SPAWN_EGG_GETTER = "DROWNED_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ElderGuardianMob extends GenericHostileSwimmingMob{
	protected const ENTITY_ID = EntityIds::ELDER_GUARDIAN;
	protected const DISPLAY_NAME = "Elder Guardian";
	protected const SPAWN_EGG_GETTER = "ELDER_GUARDIAN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class EnderDragonMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::ENDER_DRAGON;
	protected const DISPLAY_NAME = "Ender Dragon";
	protected const SPAWN_EGG_GETTER = "ENDER_DRAGON_SPAWN_EGG";
	protected const HEIGHT = 8.0;
	protected const WIDTH = 16.0;

	private const PHASE_HOLDING = 0;
	private const PHASE_STRAFE = 1;
	private const PHASE_CHARGE = 2;
	private const PHASE_LANDING = 3;
	private const PHASE_PERCHED = 4;
	private const PHASE_TAKEOFF = 5;

	private int $phase = self::PHASE_HOLDING;
	private int $phaseTicks = 0;
	private int $waypoint = 0;
	private float $waypointY = 80.0;
	private ?Player $chargeTarget = null;
	private float $perchDamage = 0.0;
	private int $healTicks = 0;
	private int $contactTicks = 0;
	private int $dragonBreathTicks = 0;
	private int $dyingTicks = 0;
	private float $velX = 0.0;
	private float $velY = 0.0;
	private float $velZ = 0.0;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(200);
		if(!$nbt->getTag("Health")){
			$this->setHealth(200);
		}
		$this->keepMovement = true; // flies through the pillars like vanilla
		$this->waypoint = mt_rand(0, 11);
	}

	public function canSaveWithChunk() : bool{ return true; }
	public function getXpDropAmount() : int{ return 0; }
	public function isFireProof() : bool{ return true; }
	public function canBreathe() : bool{ return true; }
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return false; }

	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		if(in_array($cause, [\pocketmine\event\entity\EntityDamageEvent::CAUSE_FALL, \pocketmine\event\entity\EntityDamageEvent::CAUSE_SUFFOCATION, \pocketmine\event\entity\EntityDamageEvent::CAUSE_DROWNING, \pocketmine\event\entity\EntityDamageEvent::CAUSE_FIRE, \pocketmine\event\entity\EntityDamageEvent::CAUSE_FIRE_TICK, \pocketmine\event\entity\EntityDamageEvent::CAUSE_LAVA], true)){
			$source->cancel();
			return;
		}
		// vanilla: explosions only hurt the dragon when they come from players' beds/crystals near it; ignore stray TNT
		if(($cause === \pocketmine\event\entity\EntityDamageEvent::CAUSE_BLOCK_EXPLOSION || $cause === \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_EXPLOSION) && !($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && $source->getDamager() instanceof Player)){
			$source->setBaseDamage($source->getBaseDamage() * 0.25);
		}
		parent::attack($source);
		if(!$source->isCancelled() && $this->phase === self::PHASE_PERCHED){
			$this->perchDamage += $source->getFinalDamage();
			if($this->perchDamage > 25){
				$this->setPhase(self::PHASE_TAKEOFF);
			}
		}
	}

	private function fight() : ?\pocketmine\world\EndDragonFight{
		return \pocketmine\world\EndDragonFight::isEndWorld($this->getWorld()) ? \pocketmine\world\EndDragonFight::get($this->getWorld()) : null;
	}

	private function setPhase(int $phase) : void{
		$this->phase = $phase;
		$this->phaseTicks = 0;
		$this->perchDamage = 0.0;
		if($phase === self::PHASE_HOLDING){
			$this->waypointY = 70 + mt_rand(0, 20);
		}
	}

	/** Nearest player on the main island the dragon may attack. */
	private function nearestPlayer(float $range) : ?Player{
		$best = null;
		$bestDistance = $range * $range;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){
				continue;
			}
			$p = $player->getPosition();
			if($p->x * $p->x + $p->z * $p->z > 75 * 75){
				continue; // the dragon stays over the island (its chunks are kept loaded)
			}
			$distance = $player->getPosition()->distanceSquared($this->getPosition());
			if($distance < $bestDistance){
				$best = $player;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	/** Smoothly steers towards a point (vanilla dragons turn in wide arcs). */
	private function flyTowards(float $x, float $y, float $z, float $speed, float $turn = 0.06) : float{
		$pos = $this->getPosition();
		$dx = $x - $pos->x;
		$dy = $y - $pos->y;
		$dz = $z - $pos->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance > 0.001){
			$this->velX += ($dx / $distance * $speed - $this->velX) * $turn;
			$this->velY += ($dy / $distance * $speed * 0.6 - $this->velY) * $turn;
			$this->velZ += ($dz / $distance * $speed - $this->velZ) * $turn;
		}
		$this->setMotion(new \pocketmine\math\Vector3($this->velX, $this->velY, $this->velZ));
		if(abs($this->velX) + abs($this->velZ) > 0.01){
			// the dragon model faces backwards compared to other mobs (vanilla adds 180 degrees)
			$this->setRotation(rad2deg(atan2(-$this->velX, $this->velZ)) + 180.0, rad2deg(atan2($this->velY, sqrt($this->velX ** 2 + $this->velZ ** 2))));
		}
		return $distance;
	}

	protected function tickWandering(int $tickDiff) : void{
		if(!$this->isAlive()){
			return;
		}
		$this->phaseTicks += $tickDiff;
		$crystals = $this->fight()?->getPillarCrystals() ?? [];
		$this->tickCrystalHealing($crystals, $tickDiff);
		$this->tickContactDamage($tickDiff);
		$pos = $this->getPosition();

		switch($this->phase){
			case self::PHASE_HOLDING:
				// circle the island through 12 waypoints (radius 60, inner ring 40 with few crystals)
				$radius = count($crystals) > 4 ? 60.0 : 40.0;
				$angle = 2 * M_PI * $this->waypoint / 12;
				$tx = cos($angle) * $radius;
				$tz = sin($angle) * $radius;
				if($this->flyTowards($tx, $this->waypointY, $tz, 0.65) < 10){
					$this->waypoint = ($this->waypoint + 1) % 12;
					$this->waypointY = 70 + mt_rand(0, 20);
					// vanilla: landing gets more likely the fewer crystals are left
					$player = $this->nearestPlayer(150);
					if($player !== null && mt_rand(0, count($crystals) + 3) === 0){
						$this->setPhase(self::PHASE_LANDING);
					}elseif($player !== null && mt_rand(0, 2) === 0){
						$this->chargeTarget = $player;
						$this->setPhase(mt_rand(0, 1) === 0 ? self::PHASE_CHARGE : self::PHASE_STRAFE);
					}
				}
				break;
			case self::PHASE_STRAFE:
			case self::PHASE_CHARGE:
				$target = $this->chargeTarget;
				if($target === null || !$target->isAlive() || $target->getWorld() !== $this->getWorld() || $this->phaseTicks > 200){
					$this->setPhase(self::PHASE_HOLDING);
					break;
				}
				$tp = $target->getPosition();
				if($this->phase === self::PHASE_STRAFE){
					// fly past the player and spit a dragon fireball (lingering breath cloud)
					$distance = $this->flyTowards($tp->x, $tp->y + 12, $tp->z, 0.6);
					if($distance < 32 && $this->phaseTicks % 40 < $tickDiff){
						$this->spawnBreath($tp->asVector3(), 3.0, 120);
						$this->setPhase(self::PHASE_HOLDING);
					}
				}else{
					if($this->flyTowards($tp->x, $tp->y + 1, $tp->z, 0.8, 0.12) < 3){
						$this->setPhase(self::PHASE_HOLDING);
					}
				}
				break;
			case self::PHASE_LANDING:
				$distance = $this->flyTowards(0.5, $pos->y > 80 ? 75 : 67, 0.5, 0.45, 0.1);
				if($distance < 2.5 || ($pos->x ** 2 + $pos->z ** 2 < 4 && $pos->y < 69)){
					$this->setPhase(self::PHASE_PERCHED);
					$this->velX = $this->velY = $this->velZ = 0.0;
				}elseif($this->phaseTicks > 400){
					$this->setPhase(self::PHASE_HOLDING);
				}
				break;
			case self::PHASE_PERCHED:
				$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
				$player = $this->nearestPlayer(20);
				if($player !== null){
					$dx = $player->getPosition()->x - $pos->x;
					$dz = $player->getPosition()->z - $pos->z;
					$this->setRotation(rad2deg(atan2(-$dx, $dz)) + 180.0, 0.0);
				}
				$this->dragonBreathTicks += $tickDiff;
				if($this->dragonBreathTicks >= 60 && $player !== null){
					$this->dragonBreathTicks = 0;
					// breath attack in front of the head
					$dir = $player->getPosition()->subtractVector($pos); $dir = new \pocketmine\math\Vector3($dir->x, 0, $dir->z);
					if($dir->lengthSquared() > 0.01){
						$dir = $dir->normalize();
					}
					$this->spawnBreath($pos->add($dir->x * 5, 0, $dir->z * 5), 3.0, 60);
				}
				if($this->phaseTicks > 200){
					$this->setPhase(self::PHASE_TAKEOFF);
				}
				break;
			case self::PHASE_TAKEOFF:
				if($this->flyTowards(0.5, 95, 0.5 + 30, 0.5, 0.1) < 10 || $this->phaseTicks > 200){
					$this->setPhase(self::PHASE_HOLDING);
				}
				break;
		}
	}

	/** End crystals within 32 blocks heal the dragon by 1 health every 10 ticks. */
	private function tickCrystalHealing(array $crystals, int $tickDiff) : void{
		$this->healTicks += $tickDiff;
		if($this->healTicks < 10){
			return;
		}
		$this->healTicks = 0;
		$nearest = null;
		$best = 32.0 * 32.0;
		foreach($crystals as $crystal){
			$distance = $crystal->getPosition()->distanceSquared($this->getPosition());
			if($distance < $best){
				$best = $distance;
				$nearest = $crystal;
			}
		}
		foreach($crystals as $crystal){
			$crystal->setBeamTarget($crystal === $nearest ? $this->getPosition()->floor() : null);
		}
		if($nearest !== null && $this->getHealth() < $this->getMaxHealth()){
			$this->setHealth(min($this->getMaxHealth(), $this->getHealth() + 1));
		}
	}

	/** Wings knock players back, the head bites. */
	private function tickContactDamage(int $tickDiff) : void{
		$this->contactTicks += $tickDiff;
		if($this->contactTicks < 10 || $this->phase === self::PHASE_PERCHED){
			return;
		}
		$this->contactTicks = 0;
		$pos = $this->getPosition();
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){
				continue;
			}
			$p = $player->getPosition();
			$dx = $p->x - $pos->x;
			$dz = $p->z - $pos->z;
			if($dx * $dx + $dz * $dz > 36 || abs($p->y - $pos->y - 1) > 4){
				continue;
			}
			$head = $dx * $dx + $dz * $dz < 9;
			$damage = \pocketmine\entity\ai\MobStats::scaleDamage($head ? 10.0 : 5.0, $this->getWorld()->getDifficulty());
			if($damage > 0){
				$player->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $player, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage, [], 0.0));
			}
			$length = max(0.1, sqrt($dx * $dx + $dz * $dz));
			$player->setMotion(new \pocketmine\math\Vector3($dx / $length * 1.5, 0.4, $dz / $length * 1.5));
		}
	}

	/** A lingering cloud of dragon's breath (instant damage). */
	private function spawnBreath(\pocketmine\math\Vector3 $at, float $radius, int $duration) : void{
		$cloud = new \pocketmine\entity\object\AreaEffectCloud(\pocketmine\entity\Location::fromObject($at, $this->getWorld()));
		$cloud->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::INSTANT_DAMAGE(), 1, 0));
		$cloud->setRadius($radius);
		$cloud->setMaxAge($duration);
		$cloud->spawnToAll();
	}

	protected function onDeath() : void{
		foreach($this->getWorld()->getEntities() as $entity){
			if($entity instanceof \pocketmine\entity\object\EndCrystal){
				$entity->setBeamTarget(null);
			}
		}
		$this->broadcastAnimation(new \pocketmine\entity\animation\DragonDeathAnimation($this));
		parent::onDeath();
	}

	/** Vanilla death: the dragon rises for 10 seconds while dropping experience, then the portal opens. */
	protected function onDeathUpdate(int $tickDiff) : bool{
		$this->dyingTicks += $tickDiff;
		$this->setMotion(new \pocketmine\math\Vector3(0, 0.1, 0));
		$fight = $this->fight();
		$total = $fight !== null && $fight->wasPreviouslyKilled() ? 500 : 12000;
		if($this->dyingTicks > 150 && $this->dyingTicks % 5 < $tickDiff){
			$this->getWorld()->dropExperience($this->getPosition(), (int) ($total * 0.08));
		}
		if($this->dyingTicks >= 200){
			$this->getWorld()->dropExperience($this->getPosition(), (int) ($total * 0.2));
			$fight?->onDragonKilled($this);
			return true;
		}
		return false;
	}
}

class EndermanMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::ENDERMAN;
	protected const DISPLAY_NAME = "Enderman";
	protected const SPAWN_EGG_GETTER = "ENDERMAN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	private int $waterTicks = 0;
	private int $chaseTeleportTicks = 0;

	/** Vanilla: projectiles never hit endermen, they teleport away instead; other damage makes them teleport sometimes. */
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($source->getCause() === \pocketmine\event\entity\EntityDamageEvent::CAUSE_PROJECTILE){
			$source->cancel();
			if($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living){
				$this->angerAt($damager);
			}
			for($i = 0; $i < 64 && !$this->teleportRandomly(); ++$i){}
			return;
		}
		parent::attack($source);
		if(!$source->isCancelled() && $this->isAlive() && mt_rand(0, 9) < 7){
			for($i = 0; $i < 64 && !$this->teleportRandomly(); ++$i){}
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$result = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $result;
		}
		// water hurts endermen and makes them flee
		if($this->isUnderwater() || $this->getWorld()->getBlock($this->getPosition())->getTypeId() === \pocketmine\block\BlockTypeIds::WATER){
			$this->waterTicks += $tickDiff;
			if($this->waterTicks >= 20){
				$this->waterTicks = 0;
				$this->attack(new \pocketmine\event\entity\EntityDamageEvent($this, \pocketmine\event\entity\EntityDamageEvent::CAUSE_DROWNING, 1));
				for($i = 0; $i < 64 && !$this->teleportRandomly(); ++$i){}
			}
		}
		// chasing: teleport towards a target that is far away or out of reach
		$target = $this->getCombatTarget();
		if($target !== null && ($this->chaseTeleportTicks += $tickDiff) >= 30){
			$this->chaseTeleportTicks = 0;
			if($target->getPosition()->distanceSquared($this->getPosition()) > 256 && mt_rand(0, 2) === 0){
				for($i = 0; $i < 16 && !$this->teleportTowards($target->getPosition()); ++$i){}
			}
		}
		return $result;
	}

	/** Vanilla teleportRandomly: a spot up to 32 blocks away horizontally and vertically. */
	public function teleportRandomly() : bool{
		$pos = $this->getPosition();
		return $this->teleportTo($pos->x + mt_rand(-32, 32), $pos->y + mt_rand(-32, 32), $pos->z + mt_rand(-32, 32));
	}

	private function teleportTowards(\pocketmine\math\Vector3 $target) : bool{
		$pos = $this->getPosition();
		$dir = $target->subtractVector($pos);
		if($dir->lengthSquared() < 0.01){
			return false;
		}
		$dir = $dir->normalize();
		return $this->teleportTo($pos->x + $dir->x * 16 + mt_rand(-4, 4), $pos->y + mt_rand(-8, 8), $pos->z + $dir->z * 16 + mt_rand(-4, 4));
	}

	/** Moves down to solid ground below the spot and teleports there if 3 blocks of air and no water. */
	private function teleportTo(float $x, float $y, float $z) : bool{
		$world = $this->getWorld();
		$bx = (int) floor($x);
		$bz = (int) floor($z);
		if(!$world->isChunkLoaded($bx >> 4, $bz >> 4)){
			return false;
		}
		$by = (int) floor($y);
		$by = max($world->getMinY() + 1, min($world->getMaxY() - 4, $by));
		while($by > $world->getMinY() + 1 && !$world->getBlockAt($bx, $by - 1, $bz)->isSolid()){
			--$by;
		}
		$floor = $world->getBlockAt($bx, $by - 1, $bz);
		if(!$floor->isSolid()){
			return false;
		}
		for($h = 0; $h < 3; ++$h){
			$b = $world->getBlockAt($bx, $by + $h, $bz);
			if($b->isSolid() || $b instanceof \pocketmine\block\Liquid){
				return false;
			}
		}
		$from = $this->getPosition()->asVector3();
		$world->addSound($from, new \pocketmine\world\sound\EndermanTeleportSound());
		$this->teleport(new \pocketmine\math\Vector3($bx + 0.5, $by, $bz + 0.5));
		$this->getNavigator()->stop();
		$world->addSound($this->getPosition(), new \pocketmine\world\sound\EndermanTeleportSound());
		return true;
	}
}

class EndermiteMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::ENDERMITE;
	protected const DISPLAY_NAME = "Endermite";
	protected const SPAWN_EGG_GETTER = "ENDERMITE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class EvokerMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::EVOCATION_ILLAGER;
	protected const DISPLAY_NAME = "Evoker";
	protected const SPAWN_EGG_GETTER = "EVOKER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class FoxMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::FOX;
	protected const DISPLAY_NAME = "Fox";
	protected const SPAWN_EGG_GETTER = "FOX_SPAWN_EGG";
	protected const HEIGHT = 0.7;
	protected const WIDTH = 0.7;
}

class FrogMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::FROG;
	protected const DISPLAY_NAME = "Frog";
	protected const SPAWN_EGG_GETTER = "FROG_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.5;
}

class FrostbiteMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::FROSTBITE;
	protected const DISPLAY_NAME = "Frostbite";
	protected const SPAWN_EGG_GETTER = "FROSTBITE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class GhastMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::GHAST;
	protected const DISPLAY_NAME = "Ghast";
	protected const SPAWN_EGG_GETTER = "GHAST_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class GlowSquidMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::GLOW_SQUID;
	protected const DISPLAY_NAME = "Glow Squid";
	protected const SPAWN_EGG_GETTER = "GLOW_SQUID_SPAWN_EGG";
	protected const HEIGHT = 0.8;
	protected const WIDTH = 0.8;
}

class GoatMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::GOAT;
	protected const DISPLAY_NAME = "Goat";
	protected const SPAWN_EGG_GETTER = "GOAT_SPAWN_EGG";
	protected const HEIGHT = 0.9;
	protected const WIDTH = 1.3;
}

class GuardianMob extends GenericHostileSwimmingMob{
	protected const ENTITY_ID = EntityIds::GUARDIAN;
	protected const DISPLAY_NAME = "Guardian";
	protected const SPAWN_EGG_GETTER = "GUARDIAN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class HappyGhastMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::HAPPY_GHAST;
	protected const DISPLAY_NAME = "Happy Ghast";
	protected const SPAWN_EGG_GETTER = "HAPPY_GHAST_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class HoglinMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::HOGLIN;
	protected const DISPLAY_NAME = "Hoglin";
	protected const SPAWN_EGG_GETTER = "HOGLIN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class HorseMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "horse"; }
	protected const ENTITY_ID = EntityIds::HORSE;
	protected const DISPLAY_NAME = "Horse";
	protected const SPAWN_EGG_GETTER = "HORSE_SPAWN_EGG";
	protected const HEIGHT = 1.4;
	protected const WIDTH = 1.6;
}

class HuskMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::HUSK;
	protected const DISPLAY_NAME = "Husk";
	protected const SPAWN_EGG_GETTER = "HUSK_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class IronGolemMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::IRON_GOLEM;
	protected const DISPLAY_NAME = "Iron Golem";
	protected const SPAWN_EGG_GETTER = "IRON_GOLEM_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	private ?Living $golemTarget = null;
	private int $golemScan = 0;
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		parent::attack($source);
		if($this->isAlive() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living && !($damager instanceof Player && ($damager->isCreative() || $damager->isSpectator()))){
			$this->golemTarget = $damager;
		}
	}
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return false; }
	protected function tickWandering(int $tickDiff) : void{
		// vanilla: iron golems attack every hostile mob except creepers within 16 blocks, and whoever hurts them
		$this->golemScan -= $tickDiff;
		if($this->golemTarget !== null && (!$this->golemTarget->isAlive() || $this->golemTarget->isClosed() || $this->golemTarget->getWorld() !== $this->getWorld() || $this->golemTarget->getPosition()->distanceSquared($this->getPosition()) > 32 * 32)){
			$this->golemTarget = null;
		}
		if($this->golemTarget === null && $this->golemScan <= 0){
			$this->golemScan = 10;
			$closest = 16.0 * 16.0;
			foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(16, 8, 16), $this) as $entity){
				if(!($entity instanceof GenericHostileMob || $entity instanceof Zombie) || $entity instanceof CreeperMob || !$entity->isAlive()){
					continue;
				}
				$distance = $this->getPosition()->distanceSquared($entity->getPosition());
				if($distance < $closest){
					$this->golemTarget = $entity;
					$closest = $distance;
				}
			}
		}
		$target = $this->golemTarget;
		if($target === null){ parent::tickWandering($tickDiff); return; }
		$navigator = $this->getNavigator();
		$navigator->moveTo($target->getPosition(), $this->getWalkSpeed(true), 1.0);
		$navigator->tick($tickDiff);
		$reach = \pocketmine\entity\ai\MobStats::reachSq($this->getSize()->getWidth(), $target->getSize()->getWidth());
		if($this->getPosition()->distanceSquared($target->getPosition()) <= $reach && $this->attackTime <= 0){
			$this->attackTime = 20;
			$this->broadcastAnimation(new \pocketmine\entity\animation\ArmSwingAnimation($this));
			$damage = 7 + mt_rand(0, 14);
			if($target instanceof Player){
				$damage = \pocketmine\entity\ai\MobStats::scaleDamage($damage, $this->getWorld()->getDifficulty());
			}
			$event = new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
			$target->attack($event);
			if(!$event->isCancelled()){ $target->addMotion(0, 0.4, 0); }
		}
	}
}

class LlamaMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::LLAMA;
	protected const DISPLAY_NAME = "Llama";
	protected const SPAWN_EGG_GETTER = "LLAMA_SPAWN_EGG";
	protected const HEIGHT = 0.9;
	protected const WIDTH = 1.87;
}

class MagmaCubeMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::MAGMA_CUBE;
	protected const DISPLAY_NAME = "Magma Cube";
	protected const SPAWN_EGG_GETTER = "MAGMA_CUBE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class MooshroomMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::MOOSHROOM;
	protected const DISPLAY_NAME = "Mooshroom";
	protected const SPAWN_EGG_GETTER = "MOOSHROOM_SPAWN_EGG";
	protected const HEIGHT = 1.4;
	protected const WIDTH = 0.9;
}

class MuleMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "horse"; }
	protected const ENTITY_ID = EntityIds::MULE;
	protected const DISPLAY_NAME = "Mule";
	protected const SPAWN_EGG_GETTER = "MULE_SPAWN_EGG";
	protected const HEIGHT = 1.4;
	protected const WIDTH = 1.6;
}

class NautilusMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::NAUTILUS;
	protected const DISPLAY_NAME = "Nautilus";
	protected const SPAWN_EGG_GETTER = "NAUTILUS_SPAWN_EGG";
	protected const HEIGHT = 1;
	protected const WIDTH = 1;
}

class NpcMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::NPC;
	protected const DISPLAY_NAME = "Npc";
	protected const SPAWN_EGG_GETTER = "NPC_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class OcelotMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::OCELOT;
	protected const DISPLAY_NAME = "Ocelot";
	protected const SPAWN_EGG_GETTER = "OCELOT_SPAWN_EGG";
	protected const HEIGHT = 0.6;
	protected const WIDTH = 0.7;
}

class PandaMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::PANDA;
	protected const DISPLAY_NAME = "Panda";
	protected const SPAWN_EGG_GETTER = "PANDA_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ParchedMob extends GenericRangedHostileMob{
	protected const ENTITY_ID = EntityIds::PARCHED;
	protected const DISPLAY_NAME = "Parched";
	protected const SPAWN_EGG_GETTER = "PARCHED_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ParrotMob extends GenericFlyingAnimalMob{
	protected const ENTITY_ID = EntityIds::PARROT;
	protected const DISPLAY_NAME = "Parrot";
	protected const SPAWN_EGG_GETTER = "PARROT_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.9;
}

class PhantomMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::PHANTOM;
	protected const DISPLAY_NAME = "Phantom";
	protected const SPAWN_EGG_GETTER = "PHANTOM_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class PiglinMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::PIGLIN;
	protected const DISPLAY_NAME = "Piglin";
	protected const SPAWN_EGG_GETTER = "PIGLIN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class PiglinBruteMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::PIGLIN_BRUTE;
	protected const DISPLAY_NAME = "Piglin Brute";
	protected const SPAWN_EGG_GETTER = "PIGLIN_BRUTE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class PillagerMob extends GenericRangedHostileMob{
	protected const ENTITY_ID = EntityIds::PILLAGER;
	protected const DISPLAY_NAME = "Pillager";
	protected const SPAWN_EGG_GETTER = "PILLAGER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	protected function heldWeapon() : Item{ return \pocketmine\item\ExtraVanillaItems::get("minecraft:crossbow") ?? VanillaItems::BOW(); }
}

class PolarBearMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::POLAR_BEAR;
	protected const DISPLAY_NAME = "Polar Bear";
	protected const SPAWN_EGG_GETTER = "POLAR_BEAR_SPAWN_EGG";
	protected const HEIGHT = 1.3;
	protected const WIDTH = 1.4;
}

class PufferfishMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::PUFFERFISH;
	protected const DISPLAY_NAME = "Pufferfish";
	protected const SPAWN_EGG_GETTER = "PUFFERFISH_SPAWN_EGG";
	protected const HEIGHT = 0.7;
	protected const WIDTH = 0.7;
}

class RabbitMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::RABBIT;
	protected const DISPLAY_NAME = "Rabbit";
	protected const SPAWN_EGG_GETTER = "RABBIT_SPAWN_EGG";
	protected const HEIGHT = 0.4;
	protected const WIDTH = 0.5;
}

class RavagerMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::RAVAGER;
	protected const DISPLAY_NAME = "Ravager";
	protected const SPAWN_EGG_GETTER = "RAVAGER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SalmonMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::SALMON;
	protected const DISPLAY_NAME = "Salmon";
	protected const SPAWN_EGG_GETTER = "SALMON_SPAWN_EGG";
	protected const HEIGHT = 0.7;
	protected const WIDTH = 0.4;
}

class ShulkerMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::SHULKER;
	protected const DISPLAY_NAME = "Shulker";
	protected const SPAWN_EGG_GETTER = "SHULKER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SilverfishMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::SILVERFISH;
	protected const DISPLAY_NAME = "Silverfish";
	protected const SPAWN_EGG_GETTER = "SILVERFISH_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SkeletonMob extends GenericRangedHostileMob{
	protected const ENTITY_ID = EntityIds::SKELETON;
	protected const DISPLAY_NAME = "Skeleton";
	protected const SPAWN_EGG_GETTER = "SKELETON_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SkeletonHorseMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "skeleton_horse"; }
	protected const ENTITY_ID = EntityIds::SKELETON_HORSE;
	protected const DISPLAY_NAME = "Skeleton Horse";
	protected const SPAWN_EGG_GETTER = "SKELETON_HORSE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SlimeMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::SLIME;
	protected const DISPLAY_NAME = "Slime";
	protected const SPAWN_EGG_GETTER = "SLIME_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SnifferMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::SNIFFER;
	protected const DISPLAY_NAME = "Sniffer";
	protected const SPAWN_EGG_GETTER = "SNIFFER_SPAWN_EGG";
	protected const HEIGHT = 1.9;
	protected const WIDTH = 1.75;
}

class SnowGolemMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::SNOW_GOLEM;
	protected const DISPLAY_NAME = "Snow Golem";
	protected const SPAWN_EGG_GETTER = "SNOW_GOLEM_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SpiderMob extends GenericSpiderMob{
	protected const ENTITY_ID = EntityIds::SPIDER;
	protected const DISPLAY_NAME = "Spider";
	protected const SPAWN_EGG_GETTER = "SPIDER_SPAWN_EGG";
	protected const HEIGHT = 0.9;
	protected const WIDTH = 0.9;
}

class StrayMob extends GenericRangedHostileMob{
	protected const ENTITY_ID = EntityIds::STRAY;
	protected const DISPLAY_NAME = "Stray";
	protected const SPAWN_EGG_GETTER = "STRAY_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class StriderMob extends GenericAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\MountableAnimalTrait;
	protected function mountKind() : string{ return "strider"; }
	protected const ENTITY_ID = EntityIds::STRIDER;
	protected const DISPLAY_NAME = "Strider";
	protected const SPAWN_EGG_GETTER = "STRIDER_SPAWN_EGG";
	protected const HEIGHT = 1.7;
	protected const WIDTH = 1.7;
}

class SulfurCubeMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::SULFUR_CUBE;
	protected const DISPLAY_NAME = "Sulfur Cube";
	protected const SPAWN_EGG_GETTER = "SULFUR_CUBE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class TadpoleMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::TADPOLE;
	protected const DISPLAY_NAME = "Tadpole";
	protected const SPAWN_EGG_GETTER = "TADPOLE_SPAWN_EGG";
	protected const HEIGHT = 0.4;
	protected const WIDTH = 0.5;
}

class TraderLlamaMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::TRADER_LLAMA;
	protected const DISPLAY_NAME = "Trader Llama";
	protected const SPAWN_EGG_GETTER = "TRADER_LLAMA_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class TropicalFishMob extends GenericSwimmingAnimalMob{
	protected const ENTITY_ID = EntityIds::TROPICALFISH;
	protected const DISPLAY_NAME = "Tropical Fish";
	protected const SPAWN_EGG_GETTER = "TROPICAL_FISH_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.4;
}

class TurtleMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::TURTLE;
	protected const DISPLAY_NAME = "Turtle";
	protected const SPAWN_EGG_GETTER = "TURTLE_SPAWN_EGG";
	protected const HEIGHT = 1.2;
	protected const WIDTH = 0.4;
}

class VexMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::VEX;
	protected const DISPLAY_NAME = "Vex";
	protected const SPAWN_EGG_GETTER = "VEX_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class VindicatorMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::VINDICATOR;
	protected const DISPLAY_NAME = "Vindicator";
	protected const SPAWN_EGG_GETTER = "VINDICATOR_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class WanderingTraderMob extends GenericAnimalMob implements \pocketmine\entity\trade\Trader{
	protected const ENTITY_ID = EntityIds::WANDERING_TRADER;
	protected const DISPLAY_NAME = "Wandering Trader";
	protected const SPAWN_EGG_GETTER = "WANDERING_TRADER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;

	/** Vanilla wandering trader offers: [item, count, emeralds, max uses]. */
	private const COMMON = [
		["sea_pickle", 1, 2, 5], ["slime_ball", 1, 4, 5], ["glowstone", 1, 2, 5], ["nautilus_shell", 1, 5, 5],
		["fern", 1, 1, 12], ["sugar_cane", 1, 1, 8], ["pumpkin", 1, 1, 4], ["kelp", 1, 3, 12], ["cactus", 1, 3, 8],
		["dandelion", 1, 1, 12], ["poppy", 1, 1, 12], ["blue_orchid", 1, 1, 8], ["allium", 1, 1, 12], ["azure_bluet", 1, 1, 12],
		["red_tulip", 1, 1, 12], ["orange_tulip", 1, 1, 12], ["white_tulip", 1, 1, 12], ["pink_tulip", 1, 1, 12],
		["oxeye_daisy", 1, 1, 12], ["cornflower", 1, 1, 12], ["lily_of_the_valley", 1, 1, 7], ["wheat_seeds", 1, 1, 12],
		["beetroot_seeds", 1, 1, 12], ["pumpkin_seeds", 1, 1, 12], ["melon_seeds", 1, 1, 12], ["acacia_sapling", 1, 5, 8],
		["birch_sapling", 1, 5, 8], ["dark_oak_sapling", 1, 5, 8], ["jungle_sapling", 1, 5, 8], ["oak_sapling", 1, 5, 8],
		["spruce_sapling", 1, 5, 8], ["cherry_sapling", 1, 5, 8], ["mangrove_propagule", 1, 5, 8], ["red_dye", 3, 1, 12],
		["white_dye", 3, 1, 12], ["blue_dye", 3, 1, 12], ["pink_dye", 3, 1, 12], ["black_dye", 3, 1, 12], ["green_dye", 3, 1, 12],
		["light_gray_dye", 3, 1, 12], ["magenta_dye", 3, 1, 12], ["yellow_dye", 3, 1, 12], ["gray_dye", 3, 1, 12],
		["purple_dye", 3, 1, 12], ["light_blue_dye", 3, 1, 12], ["lime_dye", 3, 1, 12], ["orange_dye", 3, 1, 12],
		["brown_dye", 3, 1, 12], ["cyan_dye", 3, 1, 12], ["brain_coral_block", 1, 3, 8], ["bubble_coral_block", 1, 3, 8],
		["fire_coral_block", 1, 3, 8], ["horn_coral_block", 1, 3, 8], ["tube_coral_block", 1, 3, 8], ["vines", 1, 1, 12],
		["brown_mushroom", 1, 1, 12], ["red_mushroom", 1, 1, 12], ["lily_pad", 2, 1, 5], ["small_dripleaf", 2, 1, 5],
		["sand", 8, 1, 8], ["red_sand", 4, 1, 6], ["pointed_dripstone", 2, 1, 5], ["rooted_dirt", 2, 1, 5], ["moss_block", 2, 1, 5],
	];
	private const RARE = [
		["tropical_fish_bucket", 1, 5, 4], ["pufferfish_bucket", 1, 5, 4], ["packed_ice", 1, 3, 6], ["blue_ice", 1, 6, 6],
		["gunpowder", 1, 1, 8], ["podzol", 3, 3, 6],
	];

	/** @var \pocketmine\entity\trade\TradeOffer[] */
	private array $offers = [];
	private ?Player $tradingPlayer = null;
	private int $lifeTicks = 0;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		foreach($nbt->getListTag("BetterPMMPOffers") ?? [] as $tag){
			if($tag instanceof CompoundTag && ($offer = \pocketmine\entity\trade\TradeOffer::fromNbt($tag)) !== null){
				$this->offers[] = $offer;
			}
		}
		$this->lifeTicks = $nbt->getInt("BetterPMMPLife", 0);
		if(count($this->offers) === 0){
			$this->rollOffers();
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$list = new \pocketmine\nbt\tag\ListTag();
		foreach($this->offers as $i => $offer){
			$list->push($offer->toNbt($i + 1));
		}
		$nbt->setTag("BetterPMMPOffers", $list);
		$nbt->setInt("BetterPMMPLife", $this->lifeTicks);
		return $nbt;
	}

	private function rollOffers() : void{
		$parser = \pocketmine\item\StringToItemParser::getInstance();
		$emerald = VanillaItems::EMERALD();
		$pick = function(array $table, int $count) use ($parser, $emerald) : void{
			$keys = array_keys($table);
			shuffle($keys);
			$added = 0;
			foreach($keys as $key){
				if($added >= $count){
					break;
				}
				[$name, $amount, $price, $uses] = $table[$key];
				$item = $parser->parse($name);
				if($item === null){
					continue;
				}
				$this->offers[] = new \pocketmine\entity\trade\TradeOffer((clone $emerald)->setCount($price), null, $item->setCount($amount), $uses, 1, 0);
				++$added;
			}
		};
		$pick(self::COMMON, 5);
		$pick(self::RARE, 1);
	}

	public function getOffers() : array{ return $this->offers; }
	public function getLevel() : int{ return 0; }
	public function isBaby() : bool{ return false; }

	public function setTradingPlayer(?Player $player) : void{
		$this->tradingPlayer = $player;
		$this->networkPropertiesDirty = true;
		$this->sendData(null);
	}

	public function onTrade(Player $player, \pocketmine\entity\trade\TradeOffer $offer, int $times) : void{
		foreach($this->getWorld()->getPlayers() as $viewer){
			$window = $viewer->getCurrentWindow();
			if($window instanceof \pocketmine\inventory\TradeInventory && $window->getVillager() === $this){
				$viewer->getNetworkSession()->getInvManager()?->syncTradeWindow($window);
			}
		}
	}

	public function getInteractButtonText(Player $player) : string{
		return "action.interact.trade";
	}

	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		if(!$this->isAlive() || ($this->tradingPlayer !== null && $this->tradingPlayer !== $player && $this->tradingPlayer->isConnected())){
			return false;
		}
		$this->getNavigator()->stop();
		$this->tradingPlayer = $player;
		$this->networkPropertiesDirty = true;
		$this->sendData(null);
		$player->setCurrentWindow(new \pocketmine\inventory\TradeInventory($this));
		return true;
	}

	protected function tickWandering(int $tickDiff) : void{
		if($this->tradingPlayer !== null && $this->tradingPlayer->isConnected()){
			$this->getNavigator()->stop();
			return;
		}
		parent::tickWandering($tickDiff);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$result = parent::entityBaseTick($tickDiff);
		// vanilla: the trader leaves after 40-60 minutes
		if(($this->lifeTicks += $tickDiff) >= 48000 && $this->tradingPlayer === null){
			$this->flagForDespawn();
		}
		return $result;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setLong(EntityMetadataProperties::TRADING_PLAYER_EID, $this->tradingPlayer !== null && $this->tradingPlayer->isConnected() ? $this->tradingPlayer->getId() : -1);
	}
}

class WardenMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::WARDEN;
	protected const DISPLAY_NAME = "Warden";
	protected const SPAWN_EGG_GETTER = "WARDEN_SPAWN_EGG";
	protected const HEIGHT = 2.9;
	protected const WIDTH = 0.9;
	private int $sonicBoomChargeTicks = 0;
	private int $sonicBoomCooldownTicks = 0;

	protected function tickWandering(int $tickDiff) : void{
		$this->sonicBoomCooldownTicks = max(0, $this->sonicBoomCooldownTicks - $tickDiff);
		$target = $this->findTarget();
		if($target === null){
			$this->sonicBoomChargeTicks = 0;
			parent::tickWandering($tickDiff);
			return;
		}

		$distanceSquared = $this->getPosition()->distanceSquared($target->getPosition());
		if($distanceSquared > 9.0 && $distanceSquared <= 400.0){
			$this->sonicBoomChargeTicks += $tickDiff;
			$motion = $this->getMotion();
			$this->setMotion(new \pocketmine\math\Vector3(0, $motion->y, 0));
			if($this->sonicBoomChargeTicks >= 40 && $this->sonicBoomCooldownTicks === 0){
				$target->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, 10));
				$this->sonicBoomCooldownTicks = 100;
				$this->sonicBoomChargeTicks = 0;
			}
			return;
		}
		$this->sonicBoomChargeTicks = 0;
		parent::tickWandering($tickDiff);
	}
}

class WitchMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::WITCH;
	protected const DISPLAY_NAME = "Witch";
	protected const SPAWN_EGG_GETTER = "WITCH_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class WitherMob extends GenericHostileFlyingMob{
	protected const ENTITY_ID = EntityIds::WITHER;
	protected const DISPLAY_NAME = "Wither";
	protected const SPAWN_EGG_GETTER = "WITHER_SPAWN_EGG";
	protected const HEIGHT = 3.5;
	protected const WIDTH = 0.9;

	/** Bedrock: the Wither charges for about 10 seconds after being summoned. */
	public const SUMMON_CHARGE_TICKS = 220;
	private const TAG_CHARGE = "BetterPMMPWitherCharge";

	private int $chargeTicks = 0;
	private int $regenTicks = 0;
	private int $shootTicks = 0;
	private int $bossBarTicks = 0;
	private ?BossBarTracker $bossBar = null;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(600); //Bedrock Edition value
		$this->chargeTicks = $nbt->getInt(self::TAG_CHARGE, 0);
		if($this->chargeTicks <= 0 && $nbt->getTag("Health") === null){
			$this->setHealth(600);
		}
		$this->bossBar = new BossBarTracker($this, "Wither", BossBarColor::PURPLE, BossBarOverlay::PROGRESS, 64);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_CHARGE, $this->chargeTicks);
		return $nbt;
	}

	/** Starts the summoning charge: invulnerable, health fills up, then a big explosion. */
	public function startCharging() : void{
		$this->chargeTicks = self::SUMMON_CHARGE_TICKS;
		$this->setHealth(1);
	}

	public function isCharging() : bool{
		return $this->chargeTicks > 0;
	}

	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->chargeTicks > 0 && $source->getCause() !== \pocketmine\event\entity\EntityDamageEvent::CAUSE_VOID){
			$source->cancel();
		}
		parent::attack($source);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$result = parent::entityBaseTick($tickDiff);
		if(($this->bossBarTicks += $tickDiff) >= 10){
			$this->bossBarTicks = 0;
			$this->bossBar?->sync();
		}
		if(!$this->isAlive()){
			return $result;
		}
		if($this->chargeTicks > 0){
			$this->chargeTicks -= $tickDiff;
			$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
			$this->setHealth(min($this->getMaxHealth(), $this->getMaxHealth() * (1 - max(0, $this->chargeTicks) / self::SUMMON_CHARGE_TICKS)));
			if($this->chargeTicks <= 0){
				$this->chargeTicks = 0;
				$this->setHealth($this->getMaxHealth());
				$explosion = new Explosion(Position::fromObject($this->getPosition()->add(0, 1.5, 0), $this->getWorld()), 7, $this);
				$explosion->explodeA();
				$explosion->explodeB();
			}
			return true;
		}
		//natural regeneration: 1 health per second
		if(($this->regenTicks += $tickDiff) >= 20){
			$this->regenTicks = 0;
			if($this->getHealth() < $this->getMaxHealth()){
				$this->setHealth(min($this->getMaxHealth(), $this->getHealth() + 1));
			}
		}
		//ranged attack: wither skulls hit for 8 damage and inflict the Wither effect
		if(($this->shootTicks += $tickDiff) >= 40){
			$this->shootTicks = 0;
			$target = $this->findTarget();
			if($target !== null && $target->getPosition()->distanceSquared($this->getPosition()) <= 20 * 20){
				$event = new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_PROJECTILE, 8);
				$target->attack($event);
				if(!$event->isCancelled()){
					$target->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::WITHER(), 200, 1));
				}
			}
		}
		return true;
	}

	protected function findTarget() : ?Player{
		$target = null;
		$closest = 32 * 32;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){
				continue;
			}
			$distance = $this->getPosition()->distanceSquared($player->getPosition());
			if($distance < $closest){
				$target = $player;
				$closest = $distance;
			}
		}
		return $target;
	}

	protected function tickWandering(int $tickDiff) : void{
		if($this->chargeTicks > 0){
			return;
		}
		parent::tickWandering($tickDiff);
	}

	protected function onDeath() : void{
		$this->bossBar?->hideAll();
		parent::onDeath();
	}

	protected function onDispose() : void{
		$this->bossBar?->hideAll();
		parent::onDispose();
	}
}

class WitherSkeletonMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::WITHER_SKELETON;
	protected const DISPLAY_NAME = "Wither Skeleton";
	protected const SPAWN_EGG_GETTER = "WITHER_SKELETON_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	public function spawnTo(Player $player) : void{
		if(!$player->isConnected()){
			return;
		}
		parent::spawnTo($player);
		if($player->getWorld() !== $this->getWorld() || !isset($this->getViewers()[spl_object_id($player)])){
			return;
		}
		$session = $player->getNetworkSession();
		$session->sendDataPacket(MobEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet(VanillaItems::STONE_SWORD())),
			0,
			0,
			ContainerIds::INVENTORY
		));
	}
}

class WolfMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::WOLF;
	protected const DISPLAY_NAME = "Wolf";
	protected const SPAWN_EGG_GETTER = "WOLF_SPAWN_EGG";
	protected const HEIGHT = 0.6;
	protected const WIDTH = 0.85;
}

class ZoglinMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::ZOGLIN;
	protected const DISPLAY_NAME = "Zoglin";
	protected const SPAWN_EGG_GETTER = "ZOGLIN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ZombieHorseMob extends GenericAnimalMob{
	protected const ENTITY_ID = EntityIds::ZOMBIE_HORSE;
	protected const DISPLAY_NAME = "Zombie Horse";
	protected const SPAWN_EGG_GETTER = "ZOMBIE_HORSE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ZombieNautilusMob extends GenericHostileSwimmingMob{
	protected const ENTITY_ID = EntityIds::ZOMBIE_NAUTILUS;
	protected const DISPLAY_NAME = "Zombie Nautilus";
	protected const SPAWN_EGG_GETTER = "ZOMBIE_NAUTILUS_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ZombiePigmanMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::ZOMBIE_PIGMAN;
	protected const DISPLAY_NAME = "Zombie Pigman";
	protected const SPAWN_EGG_GETTER = "ZOMBIE_PIGMAN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	public function spawnTo(Player $player) : void{
		if(!$player->isConnected()){
			return;
		}
		parent::spawnTo($player);
		if($player->getWorld() !== $this->getWorld() || !isset($this->getViewers()[spl_object_id($player)])){
			return;
		}
		$session = $player->getNetworkSession();
		$session->sendDataPacket(MobEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet(VanillaItems::GOLDEN_SWORD())),
			0,
			0,
			ContainerIds::INVENTORY
		));
	}
}

class ZombieVillagerMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::ZOMBIE_VILLAGER;
	protected const DISPLAY_NAME = "Zombie Villager";
	protected const SPAWN_EGG_GETTER = "ZOMBIE_VILLAGER_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

final class VanillaMobTypes{
	/** @return list<array{class: class-string<Entity>, id: string, egg: string, name: string, getter: string}> */
	public static function additional() : array{ return [
		["class" => AgentMob::class, "id" => EntityIds::AGENT, "egg" => "agent_spawn_egg", "name" => "Agent", "getter" => "AGENT_SPAWN_EGG"],
		["class" => AllayMob::class, "id" => EntityIds::ALLAY, "egg" => "allay_spawn_egg", "name" => "Allay", "getter" => "ALLAY_SPAWN_EGG"],
		["class" => ArmadilloMob::class, "id" => EntityIds::ARMADILLO, "egg" => "armadillo_spawn_egg", "name" => "Armadillo", "getter" => "ARMADILLO_SPAWN_EGG"],
		["class" => AxolotlMob::class, "id" => EntityIds::AXOLOTL, "egg" => "axolotl_spawn_egg", "name" => "Axolotl", "getter" => "AXOLOTL_SPAWN_EGG"],
		["class" => BatMob::class, "id" => EntityIds::BAT, "egg" => "bat_spawn_egg", "name" => "Bat", "getter" => "BAT_SPAWN_EGG"],
		["class" => BeeMob::class, "id" => EntityIds::BEE, "egg" => "bee_spawn_egg", "name" => "Bee", "getter" => "BEE_SPAWN_EGG"],
		["class" => BlazeMob::class, "id" => EntityIds::BLAZE, "egg" => "blaze_spawn_egg", "name" => "Blaze", "getter" => "BLAZE_SPAWN_EGG"],
		["class" => BoggedMob::class, "id" => EntityIds::BOGGED, "egg" => "bogged_spawn_egg", "name" => "Bogged", "getter" => "BOGGED_SPAWN_EGG"],
		["class" => BreezeMob::class, "id" => EntityIds::BREEZE, "egg" => "breeze_spawn_egg", "name" => "Breeze", "getter" => "BREEZE_SPAWN_EGG"],
		["class" => CamelMob::class, "id" => EntityIds::CAMEL, "egg" => "camel_spawn_egg", "name" => "Camel", "getter" => "CAMEL_SPAWN_EGG"],
		["class" => CamelHuskMob::class, "id" => EntityIds::CAMEL_HUSK, "egg" => "camel_husk_spawn_egg", "name" => "Camel Husk", "getter" => "CAMEL_HUSK_SPAWN_EGG"],
		["class" => CatMob::class, "id" => EntityIds::CAT, "egg" => "cat_spawn_egg", "name" => "Cat", "getter" => "CAT_SPAWN_EGG"],
		["class" => CaveSpiderMob::class, "id" => EntityIds::CAVE_SPIDER, "egg" => "cave_spider_spawn_egg", "name" => "CaveSpider", "getter" => "CAVE_SPIDER_SPAWN_EGG"],
		["class" => CodMob::class, "id" => EntityIds::COD, "egg" => "cod_spawn_egg", "name" => "Cod", "getter" => "COD_SPAWN_EGG"],
		["class" => CreakingMob::class, "id" => EntityIds::CREAKING, "egg" => "creaking_spawn_egg", "name" => "Creaking", "getter" => "CREAKING_SPAWN_EGG"],
		["class" => CreeperMob::class, "id" => EntityIds::CREEPER, "egg" => "creeper_spawn_egg", "name" => "Creeper", "getter" => "CREEPER_SPAWN_EGG"],
		["class" => DolphinMob::class, "id" => EntityIds::DOLPHIN, "egg" => "dolphin_spawn_egg", "name" => "Dolphin", "getter" => "DOLPHIN_SPAWN_EGG"],
		["class" => DonkeyMob::class, "id" => EntityIds::DONKEY, "egg" => "donkey_spawn_egg", "name" => "Donkey", "getter" => "DONKEY_SPAWN_EGG"],
		["class" => DrownedMob::class, "id" => EntityIds::DROWNED, "egg" => "drowned_spawn_egg", "name" => "Drowned", "getter" => "DROWNED_SPAWN_EGG"],
		["class" => ElderGuardianMob::class, "id" => EntityIds::ELDER_GUARDIAN, "egg" => "elder_guardian_spawn_egg", "name" => "Elder Guardian", "getter" => "ELDER_GUARDIAN_SPAWN_EGG"],
		["class" => EnderDragonMob::class, "id" => EntityIds::ENDER_DRAGON, "egg" => "ender_dragon_spawn_egg", "name" => "Ender Dragon", "getter" => "ENDER_DRAGON_SPAWN_EGG"],
		["class" => EndermanMob::class, "id" => EntityIds::ENDERMAN, "egg" => "enderman_spawn_egg", "name" => "Enderman", "getter" => "ENDERMAN_SPAWN_EGG"],
		["class" => EndermiteMob::class, "id" => EntityIds::ENDERMITE, "egg" => "endermite_spawn_egg", "name" => "Endermite", "getter" => "ENDERMITE_SPAWN_EGG"],
		["class" => EvokerMob::class, "id" => EntityIds::EVOCATION_ILLAGER, "egg" => "evoker_spawn_egg", "name" => "Evoker", "getter" => "EVOKER_SPAWN_EGG"],
		["class" => FoxMob::class, "id" => EntityIds::FOX, "egg" => "fox_spawn_egg", "name" => "Fox", "getter" => "FOX_SPAWN_EGG"],
		["class" => FrogMob::class, "id" => EntityIds::FROG, "egg" => "frog_spawn_egg", "name" => "Frog", "getter" => "FROG_SPAWN_EGG"],
		["class" => FrostbiteMob::class, "id" => EntityIds::FROSTBITE, "egg" => "frostbite_spawn_egg", "name" => "Frostbite", "getter" => "FROSTBITE_SPAWN_EGG"],
		["class" => GhastMob::class, "id" => EntityIds::GHAST, "egg" => "ghast_spawn_egg", "name" => "Ghast", "getter" => "GHAST_SPAWN_EGG"],
		["class" => GlowSquidMob::class, "id" => EntityIds::GLOW_SQUID, "egg" => "glow_squid_spawn_egg", "name" => "Glow Squid", "getter" => "GLOW_SQUID_SPAWN_EGG"],
		["class" => GoatMob::class, "id" => EntityIds::GOAT, "egg" => "goat_spawn_egg", "name" => "Goat", "getter" => "GOAT_SPAWN_EGG"],
		["class" => GuardianMob::class, "id" => EntityIds::GUARDIAN, "egg" => "guardian_spawn_egg", "name" => "Guardian", "getter" => "GUARDIAN_SPAWN_EGG"],
		["class" => HappyGhastMob::class, "id" => EntityIds::HAPPY_GHAST, "egg" => "happy_ghast_spawn_egg", "name" => "Happy Ghast", "getter" => "HAPPY_GHAST_SPAWN_EGG"],
		["class" => HoglinMob::class, "id" => EntityIds::HOGLIN, "egg" => "hoglin_spawn_egg", "name" => "Hoglin", "getter" => "HOGLIN_SPAWN_EGG"],
		["class" => HorseMob::class, "id" => EntityIds::HORSE, "egg" => "horse_spawn_egg", "name" => "Horse", "getter" => "HORSE_SPAWN_EGG"],
		["class" => HuskMob::class, "id" => EntityIds::HUSK, "egg" => "husk_spawn_egg", "name" => "Husk", "getter" => "HUSK_SPAWN_EGG"],
		["class" => IronGolemMob::class, "id" => EntityIds::IRON_GOLEM, "egg" => "iron_golem_spawn_egg", "name" => "Iron Golem", "getter" => "IRON_GOLEM_SPAWN_EGG"],
		["class" => LlamaMob::class, "id" => EntityIds::LLAMA, "egg" => "llama_spawn_egg", "name" => "Llama", "getter" => "LLAMA_SPAWN_EGG"],
		["class" => MagmaCubeMob::class, "id" => EntityIds::MAGMA_CUBE, "egg" => "magma_cube_spawn_egg", "name" => "Magma Cube", "getter" => "MAGMA_CUBE_SPAWN_EGG"],
		["class" => MooshroomMob::class, "id" => EntityIds::MOOSHROOM, "egg" => "mooshroom_spawn_egg", "name" => "Mooshroom", "getter" => "MOOSHROOM_SPAWN_EGG"],
		["class" => MuleMob::class, "id" => EntityIds::MULE, "egg" => "mule_spawn_egg", "name" => "Mule", "getter" => "MULE_SPAWN_EGG"],
		["class" => NautilusMob::class, "id" => EntityIds::NAUTILUS, "egg" => "nautilus_spawn_egg", "name" => "Nautilus", "getter" => "NAUTILUS_SPAWN_EGG"],
		["class" => NpcMob::class, "id" => EntityIds::NPC, "egg" => "npc_spawn_egg", "name" => "Npc", "getter" => "NPC_SPAWN_EGG"],
		["class" => OcelotMob::class, "id" => EntityIds::OCELOT, "egg" => "ocelot_spawn_egg", "name" => "Ocelot", "getter" => "OCELOT_SPAWN_EGG"],
		["class" => PandaMob::class, "id" => EntityIds::PANDA, "egg" => "panda_spawn_egg", "name" => "Panda", "getter" => "PANDA_SPAWN_EGG"],
		["class" => ParchedMob::class, "id" => EntityIds::PARCHED, "egg" => "parched_spawn_egg", "name" => "Parched", "getter" => "PARCHED_SPAWN_EGG"],
		["class" => ParrotMob::class, "id" => EntityIds::PARROT, "egg" => "parrot_spawn_egg", "name" => "Parrot", "getter" => "PARROT_SPAWN_EGG"],
		["class" => PhantomMob::class, "id" => EntityIds::PHANTOM, "egg" => "phantom_spawn_egg", "name" => "Phantom", "getter" => "PHANTOM_SPAWN_EGG"],
		["class" => PiglinMob::class, "id" => EntityIds::PIGLIN, "egg" => "piglin_spawn_egg", "name" => "Piglin", "getter" => "PIGLIN_SPAWN_EGG"],
		["class" => PiglinBruteMob::class, "id" => EntityIds::PIGLIN_BRUTE, "egg" => "piglin_brute_spawn_egg", "name" => "Piglin Brute", "getter" => "PIGLIN_BRUTE_SPAWN_EGG"],
		["class" => PillagerMob::class, "id" => EntityIds::PILLAGER, "egg" => "pillager_spawn_egg", "name" => "Pillager", "getter" => "PILLAGER_SPAWN_EGG"],
		["class" => PolarBearMob::class, "id" => EntityIds::POLAR_BEAR, "egg" => "polar_bear_spawn_egg", "name" => "Polar Bear", "getter" => "POLAR_BEAR_SPAWN_EGG"],
		["class" => PufferfishMob::class, "id" => EntityIds::PUFFERFISH, "egg" => "pufferfish_spawn_egg", "name" => "Pufferfish", "getter" => "PUFFERFISH_SPAWN_EGG"],
		["class" => RabbitMob::class, "id" => EntityIds::RABBIT, "egg" => "rabbit_spawn_egg", "name" => "Rabbit", "getter" => "RABBIT_SPAWN_EGG"],
		["class" => RavagerMob::class, "id" => EntityIds::RAVAGER, "egg" => "ravager_spawn_egg", "name" => "Ravager", "getter" => "RAVAGER_SPAWN_EGG"],
		["class" => SalmonMob::class, "id" => EntityIds::SALMON, "egg" => "salmon_spawn_egg", "name" => "Salmon", "getter" => "SALMON_SPAWN_EGG"],
		["class" => ShulkerMob::class, "id" => EntityIds::SHULKER, "egg" => "shulker_spawn_egg", "name" => "Shulker", "getter" => "SHULKER_SPAWN_EGG"],
		["class" => SilverfishMob::class, "id" => EntityIds::SILVERFISH, "egg" => "silverfish_spawn_egg", "name" => "Silverfish", "getter" => "SILVERFISH_SPAWN_EGG"],
		["class" => SkeletonMob::class, "id" => EntityIds::SKELETON, "egg" => "skeleton_spawn_egg", "name" => "Skeleton", "getter" => "SKELETON_SPAWN_EGG"],
		["class" => SkeletonHorseMob::class, "id" => EntityIds::SKELETON_HORSE, "egg" => "skeleton_horse_spawn_egg", "name" => "Skeleton Horse", "getter" => "SKELETON_HORSE_SPAWN_EGG"],
		["class" => SlimeMob::class, "id" => EntityIds::SLIME, "egg" => "slime_spawn_egg", "name" => "Slime", "getter" => "SLIME_SPAWN_EGG"],
		["class" => SnifferMob::class, "id" => EntityIds::SNIFFER, "egg" => "sniffer_spawn_egg", "name" => "Sniffer", "getter" => "SNIFFER_SPAWN_EGG"],
		["class" => SnowGolemMob::class, "id" => EntityIds::SNOW_GOLEM, "egg" => "snow_golem_spawn_egg", "name" => "Snow Golem", "getter" => "SNOW_GOLEM_SPAWN_EGG"],
		["class" => SpiderMob::class, "id" => EntityIds::SPIDER, "egg" => "spider_spawn_egg", "name" => "Spider", "getter" => "SPIDER_SPAWN_EGG"],
		["class" => StrayMob::class, "id" => EntityIds::STRAY, "egg" => "stray_spawn_egg", "name" => "Stray", "getter" => "STRAY_SPAWN_EGG"],
		["class" => StriderMob::class, "id" => EntityIds::STRIDER, "egg" => "strider_spawn_egg", "name" => "Strider", "getter" => "STRIDER_SPAWN_EGG"],
		["class" => SulfurCubeMob::class, "id" => EntityIds::SULFUR_CUBE, "egg" => "sulfur_cube_spawn_egg", "name" => "Sulfur Cube", "getter" => "SULFUR_CUBE_SPAWN_EGG"],
		["class" => TadpoleMob::class, "id" => EntityIds::TADPOLE, "egg" => "tadpole_spawn_egg", "name" => "Tadpole", "getter" => "TADPOLE_SPAWN_EGG"],
		["class" => TraderLlamaMob::class, "id" => EntityIds::TRADER_LLAMA, "egg" => "trader_llama_spawn_egg", "name" => "Trader Llama", "getter" => "TRADER_LLAMA_SPAWN_EGG"],
		["class" => TropicalFishMob::class, "id" => EntityIds::TROPICALFISH, "egg" => "tropical_fish_spawn_egg", "name" => "Tropical Fish", "getter" => "TROPICAL_FISH_SPAWN_EGG"],
		["class" => TurtleMob::class, "id" => EntityIds::TURTLE, "egg" => "turtle_spawn_egg", "name" => "Turtle", "getter" => "TURTLE_SPAWN_EGG"],
		["class" => VexMob::class, "id" => EntityIds::VEX, "egg" => "vex_spawn_egg", "name" => "Vex", "getter" => "VEX_SPAWN_EGG"],
		["class" => VindicatorMob::class, "id" => EntityIds::VINDICATOR, "egg" => "vindicator_spawn_egg", "name" => "Vindicator", "getter" => "VINDICATOR_SPAWN_EGG"],
		["class" => WanderingTraderMob::class, "id" => EntityIds::WANDERING_TRADER, "egg" => "wandering_trader_spawn_egg", "name" => "Wandering Trader", "getter" => "WANDERING_TRADER_SPAWN_EGG"],
		["class" => WardenMob::class, "id" => EntityIds::WARDEN, "egg" => "warden_spawn_egg", "name" => "Warden", "getter" => "WARDEN_SPAWN_EGG"],
		["class" => WitchMob::class, "id" => EntityIds::WITCH, "egg" => "witch_spawn_egg", "name" => "Witch", "getter" => "WITCH_SPAWN_EGG"],
		["class" => WitherMob::class, "id" => EntityIds::WITHER, "egg" => "wither_spawn_egg", "name" => "Wither", "getter" => "WITHER_SPAWN_EGG"],
		["class" => WitherSkeletonMob::class, "id" => EntityIds::WITHER_SKELETON, "egg" => "wither_skeleton_spawn_egg", "name" => "Wither Skeleton", "getter" => "WITHER_SKELETON_SPAWN_EGG"],
		["class" => WolfMob::class, "id" => EntityIds::WOLF, "egg" => "wolf_spawn_egg", "name" => "Wolf", "getter" => "WOLF_SPAWN_EGG"],
		["class" => ZoglinMob::class, "id" => EntityIds::ZOGLIN, "egg" => "zoglin_spawn_egg", "name" => "Zoglin", "getter" => "ZOGLIN_SPAWN_EGG"],
		["class" => ZombieHorseMob::class, "id" => EntityIds::ZOMBIE_HORSE, "egg" => "zombie_horse_spawn_egg", "name" => "Zombie Horse", "getter" => "ZOMBIE_HORSE_SPAWN_EGG"],
		["class" => ZombieNautilusMob::class, "id" => EntityIds::ZOMBIE_NAUTILUS, "egg" => "zombie_nautilus_spawn_egg", "name" => "Zombie Nautilus", "getter" => "ZOMBIE_NAUTILUS_SPAWN_EGG"],
		["class" => ZombiePigmanMob::class, "id" => EntityIds::ZOMBIE_PIGMAN, "egg" => "zombie_pigman_spawn_egg", "name" => "Zombie Pigman", "getter" => "ZOMBIE_PIGMAN_SPAWN_EGG"],
		["class" => ZombieVillagerMob::class, "id" => EntityIds::ZOMBIE_VILLAGER, "egg" => "zombie_villager_spawn_egg", "name" => "Zombie Villager", "getter" => "ZOMBIE_VILLAGER_SPAWN_EGG"],
	]; }
}
