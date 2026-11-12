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
	/** BetterPMMP: a water bucket used on a fish, axolotl or tadpole catches it (cod_bucket etc.). */
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$hand = $player->getInventory()->getItemInHand();
		$bucket = \pocketmine\item\FishBucket::bucketFor($this);
		if($bucket === null || !$hand->equals(VanillaItems::WATER_BUCKET(), true, false)){
			return parent::onInteract($player, $clickPos);
		}
		if($player->hasFiniteResources()){
			$hand->pop();
			$player->getInventory()->setItemInHand($hand->isNull() ? $bucket : $hand);
			if(!$hand->isNull()){
				$player->getInventory()->addItem($bucket);
			}
		}else{
			$player->getInventory()->addItem($bucket);
		}
		$this->getWorld()->addSound($this->location, new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::BUCKET_FILL_FISH));
		$this->flagForDespawn();
		return true;
	}
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return true; }
	protected function tickWandering(int $tickDiff) : void{ parent::tickWandering($tickDiff); $this->constrainToWater(); }
	public function canBreathe() : bool{ return $this->isUnderwater(); }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{ parent::attack($source); if($this->isAlive()){ $this->fleeFromAttacker($source); } }
}

abstract class GenericHostileFlyingMob extends GenericFreeMotionMob{
	/** BetterPMMP: nearest survival player within a range. */
	protected function nearestSurvivalPlayer(float $range) : ?Player{ $best = null; $closest = $range * $range; foreach($this->getWorld()->getPlayers() as $player){ if(!$player->isAlive() || $player->isCreative() || $player->isSpectator()){ continue; } $d = $this->getPosition()->distanceSquared($player->getPosition()); if($d < $closest){ $best = $player; $closest = $d; } } return $best; }
	/** BetterPMMP: no solid block between the mob's and the player's eyes. */
	protected function seesPlayer(Player $player) : bool{ $world = $this->getWorld(); $start = $this->getPosition()->add(0, $this->getSize()->getHeight() * 0.8, 0); $end = $player->getEyePos(); foreach(\pocketmine\math\VoxelRayTrace::betweenPoints($start, $end) as $p){ $x = $p->getFloorX(); $z = $p->getFloorZ(); if(!$world->isChunkLoaded($x >> 4, $z >> 4) || $world->getBlockAt($x, $p->getFloorY(), $z)->isSolid()){ return false; } } return true; }
	/** BetterPMMP: shoots a fireball at a player. */
	protected function shootFireball(Player $target, bool $large, float $speed, float $spread = 0.0) : void{
		$eye = $this->getPosition()->add(0, $this->getSize()->getHeight() * 0.6, 0);
		$aim = $target->getPosition()->add(0, $target->getSize()->getHeight() * 0.5, 0)->subtractVector($eye)->normalize();
		if($spread > 0){ $aim = $aim->add(mt_rand(-100, 100) / 100 * $spread, mt_rand(-100, 100) / 100 * $spread, mt_rand(-100, 100) / 100 * $spread)->normalize(); }
		$class = $large ? \pocketmine\entity\projectile\Fireball::class : \pocketmine\entity\projectile\SmallFireball::class;
		$ball = new $class(Location::fromObject($eye->addVector($aim->multiply($large ? 2.5 : 0.8)), $this->getWorld()), $this);
		$ball->setMotion($aim->multiply($speed));
		$ball->spawnToAll();
		$this->getWorld()->broadcastPacketToViewers($eye, \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::SHOOT, $eye, -1, static::ENTITY_ID, false, false, $this->getId(), null));
	}
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
	/** BetterPMMP: struck by lightning - charged creeper, double explosion radius */
	protected bool $powered = false;
	public function isPowered() : bool{ return $this->powered; }
	public function setPowered(bool $powered = true) : void{ $this->powered = $powered; $this->networkPropertiesDirty = true; }
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->powered = $nbt->getByte("powered", 0) === 1; }
	public function saveNBT() : CompoundTag{ $nbt = parent::saveNBT(); $nbt->setByte("powered", $this->powered ? 1 : 0); return $nbt; }
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
		$event = new \pocketmine\event\entity\EntityPreExplodeEvent($this, $this->powered ? 6.0 : 3.0); $event->call(); if($event->isCancelled()){ $this->fuseTicks = -1; $this->networkPropertiesDirty = true; return; }
		$this->flagForDespawn(); $explosion = new \pocketmine\world\Explosion(\pocketmine\world\Position::fromObject($this->getPosition()->add(0, 0.85, 0), $this->getWorld()), $event->getRadius(), $this, $event->getFireChance()); if($event->isBlockBreaking()){ $explosion->explodeA(); } $explosion->explodeB();
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setGenericFlag(EntityMetadataFlags::POWERED, $this->powered); $properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->fuseTicks >= 0); $properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuseTicks < 0 ? 0 : max(0, self::FUSE_DURATION_TICKS - $this->fuseTicks)); }
}

abstract class GenericRangedHostileMob extends GenericHostileMob{
	/** BetterPMMP: tipped arrows (bogged: poison, stray: slowness). */
	protected function decorateArrow(\pocketmine\entity\projectile\Arrow $arrow) : void{}
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
			$arrow = new \pocketmine\entity\projectile\Arrow(new \pocketmine\entity\Location($origin->x, $origin->y, $origin->z, $this->getWorld(), $yaw, $pitch), $this, false); $this->decorateArrow($arrow); $arrow->setBaseDamage(1.5); $arrow->setMotion((new \pocketmine\math\Vector3($aimX, $aimY, $aimZ))->normalize()->multiply(1.65)); $arrow->spawnToAll(); $this->attackTime = mt_rand(30, 45);
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
	/** BetterPMMP: vanilla armadillo - rolls up when threatened (takes half damage, stands still), sheds a scute every 5-10 min, brushing gives a scute */
	private int $rolledTicks = 0;
	private int $scuteTicks = 0;
	private function scute() : ?Item{ return \pocketmine\item\ExtraVanillaItems::get("minecraft:armadillo_scute"); }
	public function isRolledUp() : bool{ return $this->rolledTicks > 0; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->rolledTicks > 0){ $source->setModifier(-$source->getFinalDamage() / 2, \pocketmine\event\entity\EntityDamageEvent::MODIFIER_ARMOR); }
		parent::attack($source);
		if($this->isAlive()){ $this->rolledTicks = 200; }
	}
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($held instanceof \pocketmine\item\Brush && !$this->isBaby() && ($scute = $this->scute()) !== null){
			$this->getWorld()->dropItem($this->getPosition()->add(0, 0.5, 0), $scute);
			if($player->hasFiniteResources()){ $held->applyDamage(16); $player->getInventory()->setItemInHand($held); }
			$this->getWorld()->addSound($this->getPosition(), new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::MOB_ARMADILLO_BRUSH));
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && !$this->isBaby()){
			if($this->scuteTicks <= 0){ $this->scuteTicks = mt_rand(6000, 12000); }
			elseif(($this->scuteTicks -= $tickDiff) <= 0 && ($scute = $this->scute()) !== null){ $this->getWorld()->dropItem($this->getPosition(), $scute); }
		}
		return $update;
	}
	protected function tickWandering(int $tickDiff) : void{
		// a sprinting player or an undead mob nearby makes it roll up
		foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(7, 2, 7), $this) as $e){
			if(($e instanceof Player && $e->isSprinting() && !$e->isSpectator()) || $e instanceof Zombie || $e instanceof SkeletonMob){ $this->rolledTicks = max($this->rolledTicks, 100); }
		}
		if($this->rolledTicks > 0){
			$this->rolledTicks -= $tickDiff;
			$this->getNavigator()->stop();
			$m = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3(0, $m->y, 0));
			return;
		}
		parent::tickWandering($tickDiff);
	}
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

	/** BetterPMMP: vanilla bee - collects nectar from flowers, pollinates crops on the way home, fills its hive with honey, stings when angry (and dies later). */
	private ?\pocketmine\math\Vector3 $hive = null;
	private ?\pocketmine\math\Vector3 $flower = null;
	private bool $nectar = false;
	private int $pollinateTicks = 0;
	private int $searchCooldown = 0;
	private int $angerTicks = 0;
	private ?int $angerTarget = null;
	private bool $stung = false;
	private int $stingDeathTicks = -1;
	private int $attackCooldown = 0;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->nectar = $nbt->getByte("HasNectar", 0) === 1;
		$this->stung = $nbt->getByte("HasStung", 0) === 1;
		if($this->stung){ $this->stingDeathTicks = mt_rand(600, 1200); }
		$hive = $nbt->getListTag("HivePos");
		if($hive !== null && $hive->count() === 3){ $v = $hive->getAllValues(); $this->hive = new \pocketmine\math\Vector3((int) $v[0], (int) $v[1], (int) $v[2]); }
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte("HasNectar", $this->nectar ? 1 : 0);
		$nbt->setByte("HasStung", $this->stung ? 1 : 0);
		if($this->hive !== null){ $nbt->setTag("HivePos", new \pocketmine\nbt\tag\ListTag([new \pocketmine\nbt\tag\IntTag((int) $this->hive->x), new \pocketmine\nbt\tag\IntTag((int) $this->hive->y), new \pocketmine\nbt\tag\IntTag((int) $this->hive->z)])); }
		return $nbt;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->angerTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::POWERED, $this->nectar); // has_nectar look
	}

	public function hasNectar() : bool{ return $this->nectar; }
	public function getHivePosition() : ?\pocketmine\math\Vector3{ return $this->hive; }
	public function setHivePosition(?\pocketmine\math\Vector3 $pos) : void{ $this->hive = $pos?->floor(); }
	public function isAngry() : bool{ return $this->angerTicks > 0; }

	public function anger(Living $target, int $ticks = 500) : void{
		if($this->stung || ($target instanceof Player && ($target->isCreative() || $target->isSpectator()))){ return; }
		$this->angerTarget = $target->getId();
		$this->angerTicks = $ticks;
		$this->networkPropertiesDirty = true;
	}

	/** Angers every bee within 16 blocks (hive harvested or broken). */
	public static function angerAround(\pocketmine\math\Vector3 $pos, Living $target) : void{
		$world = $target->getWorld();
		foreach($world->getNearbyEntities(new \pocketmine\math\AxisAlignedBB($pos->x - 16, $pos->y - 16, $pos->z - 16, $pos->x + 16, $pos->y + 16, $pos->z + 16)) as $entity){
			if($entity instanceof self){ $entity->anger($target); }
		}
	}

	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living && $this->isAlive()){
			$this->anger($damager);
			self::angerAround($this->location, $damager);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->stingDeathTicks > 0 && ($this->stingDeathTicks -= $tickDiff) <= 0 && $this->isAlive()){
			$this->attack(new \pocketmine\event\entity\EntityDamageEvent($this, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, 1000));
		}
		if($this->angerTicks > 0 && ($this->angerTicks -= $tickDiff) <= 0){ $this->angerTicks = 0; $this->angerTarget = null; $this->networkPropertiesDirty = true; }
		return $update;
	}

	private function flyTo(\pocketmine\math\Vector3 $target, float $speed) : float{
		$d = $target->subtractVector($this->location);
		$length = $d->length();
		if($length > 0.05){
			$this->setMotion($d->multiply(min($speed, $length) / $length));
			$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), -rad2deg(atan2($d->y, sqrt($d->x * $d->x + $d->z * $d->z))));
		}
		return $length;
	}

	protected function tickWandering(int $tickDiff) : void{
		$world = $this->getWorld();
		$this->attackCooldown = max(0, $this->attackCooldown - $tickDiff);
		// angry: chase and sting
		if($this->angerTicks > 0 && $this->angerTarget !== null){
			$target = $world->getEntity($this->angerTarget);
			if($target instanceof Living && $target->isAlive() && $target->getPosition()->distanceSquared($this->location) < 48 * 48 && !($target instanceof Player && ($target->isCreative() || $target->isSpectator()))){
				$distance = $this->flyTo($target->getPosition()->add(0, $target->getSize()->getHeight() * 0.5, 0), 0.3);
				if($distance < 1.4 && $this->attackCooldown === 0 && !$this->stung){
					$this->attackCooldown = 20;
					$ev = new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2);
					$target->attack($ev);
					if(!$ev->isCancelled()){
						$seconds = $world->getDifficulty() >= \pocketmine\world\World::DIFFICULTY_HARD ? 18 : ($world->getDifficulty() === \pocketmine\world\World::DIFFICULTY_NORMAL ? 10 : 0);
						if($seconds > 0){ $target->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::POISON(), $seconds * 20)); }
						$this->stung = true;
						$this->stingDeathTicks = mt_rand(600, 1200);
						$this->angerTicks = 0; $this->angerTarget = null; $this->networkPropertiesDirty = true;
					}
				}
				return;
			}
			$this->angerTicks = 0; $this->angerTarget = null; $this->networkPropertiesDirty = true;
		}
		$this->searchCooldown = max(0, $this->searchCooldown - $tickDiff);
		$hiveBlock = $this->hive !== null && $world->isChunkLoaded($this->hive->getFloorX() >> 4, $this->hive->getFloorZ() >> 4) ? $world->getBlock($this->hive) : null;
		if($this->hive !== null && $hiveBlock !== null && !$hiveBlock instanceof \pocketmine\block\VanillaBeehive){ $this->hive = null; $hiveBlock = null; }
		if($this->hive === null && $this->searchCooldown === 0){
			$this->hive = $this->findBlock(fn(\pocketmine\block\Block $b) : bool => $b instanceof \pocketmine\block\VanillaBeehive, 10, 5);
			$this->searchCooldown = 100;
		}
		$time = $world->getTimeOfDay();
		$goHome = $this->nectar || $time >= \pocketmine\world\World::TIME_NIGHT || $world->getWeather()->isRaining();
		if($goHome && $this->hive !== null){
			if($this->nectar && $this->ticksLived % 30 === 0){ $this->pollinateBelow(); }
			$front = $this->hive->add(0.5, 0.5, 0.5);
			if($this->flyTo($front, 0.18) < 1.6){
				$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
				if($this->nectar && $hiveBlock instanceof \pocketmine\block\VanillaBeehive){
					$hiveBlock->addHoney();
					$world->addSound($this->hive, new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::BLOCK_BEEHIVE_WORK));
					$this->nectar = false;
					$this->networkPropertiesDirty = true;
				}
			}
			return;
		}
		if(!$this->nectar && !$goHome){
			if($this->flower === null && $this->searchCooldown === 0){
				$this->flower = $this->findBlock(fn(\pocketmine\block\Block $b) : bool => self::isFlower($b), 8, 3);
				$this->searchCooldown = $this->flower === null ? 120 : 20;
			}
			if($this->flower !== null){
				if(!self::isFlower($world->getBlock($this->flower))){ $this->flower = null; parent::tickWandering($tickDiff); return; }
				if($this->flyTo($this->flower->add(0.5, 0.6, 0.5), 0.15) < 0.8){
					$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
					$this->pollinateTicks += $tickDiff;
					if($this->pollinateTicks >= 200){ $this->nectar = true; $this->flower = null; $this->pollinateTicks = 0; $this->networkPropertiesDirty = true; }
				}
				return;
			}
		}
		parent::tickWandering($tickDiff);
	}

	private static function isFlower(\pocketmine\block\Block $block) : bool{
		if($block instanceof \pocketmine\block\Flower){ return true; }
		if($block instanceof \pocketmine\block\DoublePlant){ $id = $block->getTypeId(); return in_array($id, [\pocketmine\block\BlockTypeIds::SUNFLOWER, \pocketmine\block\BlockTypeIds::LILAC, \pocketmine\block\BlockTypeIds::ROSE_BUSH, \pocketmine\block\BlockTypeIds::PEONY], true); }
		if($block instanceof \pocketmine\block\GenericBedrockBlock){ $n = $block->getBedrockName(); return str_contains($n, "petals") || str_contains($n, "wildflowers") || str_contains($n, "eyeblossom") || str_contains($n, "torchflower") || str_contains($n, "pitcher_plant") || str_contains($n, "flowering_azalea"); }
		return in_array($block->getTypeId(), [\pocketmine\block\BlockTypeIds::FLOWERING_AZALEA_LEAVES, \pocketmine\block\BlockTypeIds::TORCHFLOWER, \pocketmine\block\BlockTypeIds::PITCHER_PLANT, \pocketmine\block\BlockTypeIds::PINK_PETALS], true);
	}

	/** Grows one crop below the bee (up to 3 blocks), like vanilla pollination. */
	private function pollinateBelow() : void{
		$world = $this->getWorld();
		for($dy = 1; $dy <= 3; ++$dy){
			$pos = $this->location->subtract(0, $dy, 0)->floor();
			$block = $world->getBlock($pos);
			if($block instanceof \pocketmine\block\Crops && $block->getAge() < \pocketmine\block\Crops::MAX_AGE){
				$world->setBlock($pos, $block->setAge($block->getAge() + 1));
				return;
			}
			if($block->isSolid()){ return; }
		}
	}

	/** @param \Closure(\pocketmine\block\Block) : bool $match */
	private function findBlock(\Closure $match, int $radius, int $height) : ?\pocketmine\math\Vector3{
		$world = $this->getWorld();
		$base = $this->location->floor();
		$best = null; $bestDistance = PHP_INT_MAX;
		for($x = -$radius; $x <= $radius; ++$x){ for($z = -$radius; $z <= $radius; ++$z){
			$bx = $base->getFloorX() + $x; $bz = $base->getFloorZ() + $z;
			if(!$world->isChunkLoaded($bx >> 4, $bz >> 4)){ continue; }
			for($y = -$height; $y <= $height; ++$y){
				$by = $base->getFloorY() + $y;
				if(!$world->isInWorld($bx, $by, $bz)){ continue; }
				$d = $x * $x + $y * $y + $z * $z;
				if($d < $bestDistance && $match($world->getBlockAt($bx, $by, $bz))){ $best = new \pocketmine\math\Vector3($bx, $by, $bz); $bestDistance = $d; }
			}
		} }
		return $best;
	}
}

class BlazeMob extends GenericHostileFlyingMob{
	/** BetterPMMP: vanilla blaze - keeps its distance and shoots bursts of three small fireballs (5 s pause), takes damage from snowballs and water */
	private int $burstCooldown = 60;
	private int $burstLeft = 0;
	private int $shotDelay = 0;
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($source instanceof \pocketmine\event\entity\EntityDamageByChildEntityEvent && $source->getChild() instanceof \pocketmine\entity\projectile\Snowball){ $source->setBaseDamage(3); }
		parent::attack($source);
	}
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && $this->ticksLived % 20 === 0 && ($this->isUnderwater() || $this->getWorld()->getWeather()->isRainingAt($this->getPosition()->getFloorX(), (int) ($this->getPosition()->y + 1), $this->getPosition()->getFloorZ()))){
			$this->attack(new \pocketmine\event\entity\EntityDamageEvent($this, \pocketmine\event\entity\EntityDamageEvent::CAUSE_DROWNING, 1));
		}
		return $update;
	}
	protected function tickWandering(int $tickDiff) : void{
		$target = $this->nearestSurvivalPlayer(32);
		if($target === null || !$this->seesPlayer($target)){ GenericFreeMotionMob::tickWandering($tickDiff); return; }
		$d = $target->getPosition()->subtractVector($this->getPosition());
		$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
		$distance = $d->length();
		if($distance > 10){ $this->setMotion($d->multiply(0.15 / max(0.1, $distance))); }
		elseif($distance < 5){ $this->setMotion($d->multiply(-0.1 / max(0.1, $distance))); }
		else{ $this->setMotion(new \pocketmine\math\Vector3(0, $d->y > 1 ? 0.05 : ($d->y < -2 ? -0.05 : 0), 0)); }
		$this->burstCooldown = max(0, $this->burstCooldown - $tickDiff);
		$this->shotDelay = max(0, $this->shotDelay - $tickDiff);
		if($this->burstLeft === 0 && $this->burstCooldown === 0){ $this->burstLeft = 3; $this->shotDelay = 20; $this->setOnFire(10); }
		if($this->burstLeft > 0 && $this->shotDelay === 0){
			$this->shootFireball($target, false, 0.9, 0.05);
			$this->shotDelay = 6;
			if(--$this->burstLeft === 0){ $this->burstCooldown = 100; }
		}
	}
	public function isFireProof() : bool{ return true; }
	protected const ENTITY_ID = EntityIds::BLAZE;
	protected const DISPLAY_NAME = "Blaze";
	protected const SPAWN_EGG_GETTER = "BLAZE_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class BoggedMob extends GenericRangedHostileMob{
	protected function decorateArrow(\pocketmine\entity\projectile\Arrow $arrow) : void{ $arrow->setHitEffect(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::POISON(), 80)); }
	protected const ENTITY_ID = EntityIds::BOGGED;
	protected const DISPLAY_NAME = "Bogged";
	protected const SPAWN_EGG_GETTER = "BOGGED_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

/**
 * BetterPMMP: vanilla breeze - hops around (up to ~3 blocks) and shoots wind
 * charges at players within 16 blocks every 2 s; projectiles bounce off it
 * (only wind charges and melee hurt), no fall damage, drops 1-2 breeze rods.
 */
class BreezeMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::BREEZE;
	protected const DISPLAY_NAME = "Breeze";
	protected const SPAWN_EGG_GETTER = "BREEZE_SPAWN_EGG";
	protected const HEIGHT = 1.77;
	protected const WIDTH = 0.6;
	private int $shootCooldown = 40;
	private int $hopCooldown = 20;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->setMaxHealth(30); if($this->getHealth() > 30){ $this->setHealth(30); } }
	protected function calculateFallDamage(float $fallDistance) : float{ return 0.0; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($source->getCause() === \pocketmine\event\entity\EntityDamageEvent::CAUSE_PROJECTILE && $source instanceof \pocketmine\event\entity\EntityDamageByChildEntityEvent
			&& !$source->getChild() instanceof \pocketmine\entity\projectile\WindCharge){
			$source->cancel(); // arrows, tridents and snowballs are deflected
			$child = $source->getChild();
			if($child !== null){ $m = $child->getMotion(); $child->setMotion(new \pocketmine\math\Vector3(-$m->x * 0.5, 0.3, -$m->z * 0.5)); }
		}
		parent::attack($source);
	}
	public function getDrops() : array{ return ($rod = \pocketmine\item\ExtraVanillaItems::get("minecraft:breeze_rod")) !== null ? [$rod->setCount(mt_rand(1, 2))] : []; }
	public function getXpDropAmount() : int{ return 10; }
	protected function tickWandering(int $tickDiff) : void{
		$target = $this->findTarget($tickDiff);
		$this->shootCooldown = max(0, $this->shootCooldown - $tickDiff);
		$this->hopCooldown = max(0, $this->hopCooldown - $tickDiff);
		if($target === null || $target->getPosition()->distanceSquared($this->getPosition()) > 16 * 16){
			if($this->hopCooldown === 0 && $this->isOnGround() && mt_rand(0, 3) === 0){ $this->hop(null); }
			return;
		}
		$d = $target->getPosition()->subtractVector($this->getPosition());
		$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0);
		if($this->hopCooldown === 0 && $this->isOnGround()){ $this->hop($target->getPosition()); }
		if($this->shootCooldown === 0 && $this->canSeeEntity($target)){
			$this->shootCooldown = 40;
			$eye = $this->getPosition()->add(0, 1.2, 0);
			$aim = $target->getPosition()->add(0, $target->getSize()->getHeight() * 0.5, 0)->subtractVector($eye);
			$charge = new \pocketmine\entity\projectile\BreezeWindCharge(Location::fromObject($eye->addVector($aim->normalize()->multiply(0.8)), $this->getWorld()), $this);
			$charge->setMotion($aim->normalize()->multiply(0.7));
			$charge->spawnToAll();
			$this->mobSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::SHOOT);
		}
	}
	/** jumps up to ~3 blocks, sideways around or toward the target */
	private function hop(?\pocketmine\math\Vector3 $toward) : void{
		$this->hopCooldown = 20 + mt_rand(0, 30);
		$angle = deg2rad(mt_rand(0, 359));
		$x = cos($angle) * 0.3; $z = sin($angle) * 0.3;
		if($toward !== null){
			$d = $toward->subtractVector($this->getPosition()); $len = max(0.1, sqrt($d->x * $d->x + $d->z * $d->z));
			$dist = $len; $sign = $dist > 8 ? 1 : ($dist < 4 ? -1 : 0);
			$x = $x * 0.5 + $d->x / $len * 0.3 * $sign; $z = $z * 0.5 + $d->z / $len * 0.3 * $sign;
		}
		$this->setMotion(new \pocketmine\math\Vector3($x, 0.7 + mt_rand(0, 3) / 10, $z));
		$this->mobSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::JUMP);
	}
	private function mobSound(string $sound) : void{
		$this->getWorld()->broadcastPacketToViewers($this->getPosition(), \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create($sound, $this->getPosition(), -1, EntityIds::BREEZE, false, false, $this->getId(), null));
	}
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

class CatMob extends GenericAnimalMob implements TameablePet{
	protected const ENTITY_ID = EntityIds::CAT;
	use TameableTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initTameable($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveTameable(parent::saveNBT()); }
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $this->syncTameable($properties); }
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{ return $this->tameableInteract($player) ?? parent::onInteract($player, $clickPos); }
	protected function tickWandering(int $tickDiff) : void{ if(!$this->tickPet($tickDiff)){ parent::tickWandering($tickDiff); } }
	protected function getTamingItems() : array{ return [VanillaItems::RAW_FISH(), VanillaItems::RAW_SALMON()]; }
	protected function getTameChance() : int{ return 3; }
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

/**
 * BetterPMMP: vanilla creaking - stands still while any player looks at it,
 * otherwise stalks and hits for 3. One called by a creaking heart cannot be
 * hurt (hits make the heart grow resin) and crumbles at day or when its heart
 * is broken; one from a spawn egg is an ordinary mob.
 */
class CreakingMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::CREAKING;
	protected const DISPLAY_NAME = "Creaking";
	protected const SPAWN_EGG_GETTER = "CREAKING_SPAWN_EGG";
	protected const HEIGHT = 2.7;
	protected const WIDTH = 0.9;
	private ?\pocketmine\math\Vector3 $heart = null;
	private bool $frozen = false;
	private int $hitCooldown = 0;
	public function linkHeart(?\pocketmine\math\Vector3 $heart) : void{ $this->heart = $heart; }
	/** a heart's creaking is not saved: the heart calls a new one */
	public function canSaveWithChunk() : bool{ return $this->heart === null && parent::canSaveWithChunk(); }
	protected function burnsInDaylight() : bool{ return false; }
	private function heartBlock() : ?\pocketmine\block\VanillaCreakingHeart{
		if($this->heart === null){ return null; }
		$block = $this->getWorld()->getBlock($this->heart);
		return $block instanceof \pocketmine\block\VanillaCreakingHeart ? $block : null;
	}
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->heart !== null && $source->getCause() !== \pocketmine\event\entity\EntityDamageEvent::CAUSE_VOID){
			$heart = $this->heartBlock();
			if($heart !== null){
				$source->cancel();
				if($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && $this->hitCooldown === 0){
					$this->hitCooldown = 10;
					$heart->onCreakingHurt();
				}
				return;
			}
		}
		parent::attack($source);
	}
	public function getDrops() : array{ return []; }
	public function getXpDropAmount() : int{ return $this->heart === null ? 5 : 0; }
	private function isWatched() : bool{
		$pos = $this->getPosition()->add(0, 1.5, 0);
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator() || $player->getPosition()->distanceSquared($pos) > 32 * 32){ continue; }
			$eye = $player->getEyePos();
			$to = $pos->subtractVector($eye);
			$len = $to->length();
			if($len < 0.01){ return true; }
			if($player->getDirectionVector()->dot($to->divide($len)) > 0.8 && $this->canSeeEntity($player)){ return true; }
		}
		return false;
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->hitCooldown = max(0, $this->hitCooldown - $tickDiff);
		if($this->heart !== null && $this->heartBlock() === null){ $this->heart = null; $this->kill(); return; }
		$watched = $this->isWatched();
		if($watched !== $this->frozen){ $this->frozen = $watched; }
		if($this->frozen){
			$this->getNavigator()->stop();
			$m = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3(0, $m->y, 0));
			return;
		}
		parent::tickWandering($tickDiff);
	}
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
	/** BetterPMMP: vanilla drowned - 15 % carry a trident and throw it from up to 20 blocks (every 2 s); the thrown trident cannot be picked up, 8.5 % drop theirs */
	private bool $hasTrident = false;
	private int $throwCooldown = 40;
	protected function burnsInDaylight() : bool{ return !$this->isUnderwater(); }
	public function canBreathe() : bool{ return true; }
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $tag = $nbt->getTag("BetterPMMPTrident"); $this->hasTrident = $tag instanceof \pocketmine\nbt\tag\ByteTag ? $tag->getValue() === 1 : mt_rand(0, 99) < 15; }
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setByte("BetterPMMPTrident", $this->hasTrident ? 1 : 0); }
	public function hasTrident() : bool{ return $this->hasTrident; }
	public function getDrops() : array{ $drops = parent::getDrops(); if($this->hasTrident && mt_rand(0, 999) < 85){ $drops[] = VanillaItems::TRIDENT()->setDamage(mt_rand(0, 200)); } return $drops; }
	public function spawnTo(Player $player) : void{
		parent::spawnTo($player);
		if($this->hasTrident && $player->isConnected() && isset($this->getViewers()[spl_object_id($player)])){
			$session = $player->getNetworkSession();
			$session->sendDataPacket(MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet(VanillaItems::TRIDENT())), 0, 0, ContainerIds::INVENTORY));
		}
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->throwCooldown = max(0, $this->throwCooldown - $tickDiff);
		$target = $this->hasTrident ? $this->findTarget($tickDiff) : null;
		if($target !== null && ($d = $target->getPosition()->subtractVector($this->getPosition()))->lengthSquared() > 9 && $d->lengthSquared() < 20 * 20 && $this->canSeeEntity($target)){
			$this->getNavigator()->stop();
			$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
			if($this->throwCooldown === 0){
				$this->throwCooldown = 40;
				$eye = $this->getPosition()->add(0, 1.5, 0);
				$aim = $target->getPosition()->add(0, $target->getSize()->getHeight() * 0.6, 0)->subtractVector($eye);
				$flat = sqrt($aim->x * $aim->x + $aim->z * $aim->z);
				$trident = new \pocketmine\entity\projectile\MobTrident(Location::fromObject($eye, $this->getWorld()), VanillaItems::TRIDENT(), $this);
				$trident->setMotion((new \pocketmine\math\Vector3($aim->x, $aim->y + $flat * 0.15, $aim->z))->normalize()->multiply(1.6));
				$trident->spawnToAll();
				$this->getWorld()->addSound($eye, new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::ITEM_TRIDENT_THROW));
			}
			return;
		}
		parent::tickWandering($tickDiff);
	}
	protected const ENTITY_ID = EntityIds::DROWNED;
	protected const DISPLAY_NAME = "Drowned";
	protected const SPAWN_EGG_GETTER = "DROWNED_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class ElderGuardianMob extends GenericHostileSwimmingMob{
	protected const LASER_CHARGE = 60;
	protected const LASER_DAMAGE = 8;
	private int $curseTicks = 100;
	/** BetterPMMP: every minute the elder guardian gives Mining Fatigue III (5 min) to players within 50 blocks */
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && ($this->curseTicks -= $tickDiff) <= 0){
			$this->curseTicks = 1200;
			foreach($this->getWorld()->getPlayers() as $player){
				if(!$player->isCreative() && !$player->isSpectator() && $player->getPosition()->distanceSquared($this->getPosition()) < 50 * 50){
					$player->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::MINING_FATIGUE(), 6000, 2));
					$player->getNetworkSession()->sendDataPacket(\pocketmine\network\mcpe\protocol\LevelEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelEvent::GUARDIAN_CURSE, 0, $player->getPosition()));
				}
			}
		}
		return $update;
	}
	/** BetterPMMP: vanilla guardian laser - locks on a visible player within 15 blocks, charges for 2 s (the beam is shown) and then hits; spikes hurt melee attackers */
	private int $laserTicks = 0;
	private int $laserCooldown = 40;
	private ?Player $laserTarget = null;
	private function setLaserTarget(?Player $target) : void{
		if($this->laserTarget !== $target){ $this->laserTarget = $target; $this->laserTicks = 0; $this->networkPropertiesDirty = true; }
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setLong(EntityMetadataProperties::TARGET_EID, $this->laserTarget !== null ? $this->laserTarget->getId() : -1); }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && !$source instanceof \pocketmine\event\entity\EntityDamageByChildEntityEvent && $this->laserTarget === null && ($d = $source->getDamager()) instanceof Living){
			$d->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $d, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, 2));
		}
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->laserCooldown = max(0, $this->laserCooldown - $tickDiff);
		$target = $this->nearestSurvivalPlayer(15);
		if($target === null || !$this->seesPlayer($target) || $this->laserCooldown > 0){
			$this->setLaserTarget(null);
			GenericFreeMotionMob::tickWandering($tickDiff);
			$this->constrainToWater();
			return;
		}
		$this->setLaserTarget($target);
		$d = $target->getPosition()->subtractVector($this->getPosition());
		$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
		$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
		if(($this->laserTicks += $tickDiff) >= static::LASER_CHARGE){
			$target->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, static::LASER_DAMAGE));
			$this->setLaserTarget(null);
			$this->laserCooldown = 60;
		}
	}
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
	use RaiderTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initRaider($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveRaider(parent::saveNBT()); }
	public function getDrops() : array{ return $this->raiderDrops(parent::getDrops()); }
	protected function tickWandering(int $tickDiff) : void{ if($this->getCombatTarget() !== null || !$this->tickRaidMarch($tickDiff)){ parent::tickWandering($tickDiff); } }
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
	/** BetterPMMP: vanilla frog - variant from the biome (temperate / cold / warm), eats small slimes (slimeball) and magma cubes (froglight of its colour), lays frogspawn on water instead of giving birth */
	private int $variant = -1;
	private int $tongueCooldown = 0;
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->variant = $nbt->getInt("Variant", -1);
		if($this->variant < 0){
			$pos = $this->getPosition();
			[$temperature] = \pocketmine\world\weather\Weather::biomeClimate($this->getWorld()->getBiomeId($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
			$this->variant = $temperature < 0.2 ? 1 : ($temperature > 0.9 ? 2 : 0);
		}
	}
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setInt("Variant", $this->variant); }
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setInt(EntityMetadataProperties::VARIANT, max(0, $this->variant)); }
	protected function layEggs(\pocketmine\entity\BreedableAnimal $partner) : bool{
		$world = $this->getWorld();
		$spawn = \pocketmine\block\ExtraVanillaBlocks::get("minecraft:frog_spawn");
		$pos = $this->getPosition();
		for($i = 0; $i < 40 && $spawn !== null; ++$i){
			$x = $pos->getFloorX() + mt_rand(-8, 8); $z = $pos->getFloorZ() + mt_rand(-8, 8); $y = $pos->getFloorY() + mt_rand(-2, 1);
			$water = $world->getBlockAt($x, $y, $z);
			if($water instanceof \pocketmine\block\Water && $water->isSource() && $world->getBlockAt($x, $y + 1, $z)->getTypeId() === \pocketmine\block\BlockTypeIds::AIR){
				$world->setBlockAt($x, $y + 1, $z, $spawn);
				return true;
			}
		}
		return false;
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->tongueCooldown = max(0, $this->tongueCooldown - $tickDiff);
		if($this->tongueCooldown === 0 && !$this->isBaby()){
			foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(3, 2, 3), $this) as $e){
				if(($e instanceof SlimeMob || $e instanceof MagmaCubeMob) && $e->isAlive()){
					$this->tongueCooldown = 100;
					$drop = $e instanceof MagmaCubeMob ? \pocketmine\item\StringToItemParser::getInstance()->parse(match($this->variant){ 1 => "verdant_froglight", 2 => "pearlescent_froglight", default => "ochre_froglight" }) : VanillaItems::SLIMEBALL();
					$pos = $e->getPosition();
					$e->flagForDespawn();
					if($drop !== null){ $this->getWorld()->dropItem($pos, $drop); }
					$this->getWorld()->broadcastPacketToViewers($this->getPosition(), \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::EAT, $this->getPosition(), -1, EntityIds::FROG, false, false, $this->getId(), null));
					return;
				}
			}
		}
		parent::tickWandering($tickDiff);
	}
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
	/** BetterPMMP: vanilla ghast - floats around and shoots an exploding fireball every 3 s at players it sees within 64 blocks; the fireball can be hit back */
	private int $fireCooldown = 60;
	protected function tickWandering(int $tickDiff) : void{
		$this->fireCooldown = max(0, $this->fireCooldown - $tickDiff);
		$target = $this->nearestSurvivalPlayer(64);
		if($target !== null && $this->seesPlayer($target)){
			$d = $target->getPosition()->subtractVector($this->getPosition());
			$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
			if($this->fireCooldown === 0){ $this->fireCooldown = 60; $this->shootFireball($target, true, 0.6); }
		}
		GenericFreeMotionMob::tickWandering($tickDiff);
	}
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
	/** BetterPMMP: vanilla goat - milked with a bucket, rams players now and then (knockback), and loses one of its two horns when it rams a log, stone, ore or packed ice */
	private int $ramCooldown = 600;
	private int $horns = 2;
	private ?\pocketmine\math\Vector3 $ramDir = null;
	private int $ramTicks = 0;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->horns = $nbt->getByte("BetterPMMPHorns", 2); }
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setByte("BetterPMMPHorns", $this->horns); }
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if(!$this->isBaby() && $held->getTypeId() === VanillaItems::BUCKET()->getTypeId()){
			if($player->hasFiniteResources()){
				$held->pop(); $player->getInventory()->setItemInHand($held->isNull() ? VanillaItems::MILK_BUCKET() : $held);
				if(!$held->isNull()){ $player->getInventory()->addItem(VanillaItems::MILK_BUCKET()); }
			}
			$this->getWorld()->broadcastPacketToViewers($this->getPosition(), \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::MILK, $this->getPosition(), -1, EntityIds::GOAT, false, false, $this->getId(), null));
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}
	protected function tickWandering(int $tickDiff) : void{
		if($this->ramTicks > 0 && $this->ramDir !== null){
			$this->ramTicks -= $tickDiff;
			$this->setMotion(new \pocketmine\math\Vector3($this->ramDir->x * 0.4, $this->getMotion()->y, $this->ramDir->z * 0.4));
			foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(0.4, 0.2, 0.4), $this) as $e){
				if($e instanceof Living && !$e instanceof GoatMob){
					$e->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $e, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->isBaby() ? 1 : 2));
					$e->knockBack($this->ramDir->x, $this->ramDir->z, 1.2);
					$this->ramTicks = 0;
				}
			}
			if($this->isCollidedHorizontally && $this->horns > 0){
				$front = $this->getPosition()->addVector($this->ramDir)->add(0, 0.5, 0);
				$block = $this->getWorld()->getBlock($front);
				$name = strtolower($block->getName());
				if(str_contains($name, "log") || str_contains($name, "stone") || str_contains($name, "ore") || str_contains($name, "packed ice")){
					$this->getWorld()->dropItem($front, VanillaItems::GOAT_HORN());
					--$this->horns;
				}
				$this->ramTicks = 0;
			}
			return;
		}
		$this->ramCooldown -= $tickDiff;
		if($this->ramCooldown <= 0 && !$this->isBaby()){
			$this->ramCooldown = mt_rand(600, 6000);
			foreach($this->getWorld()->getPlayers() as $p){
				if(!$p->isSpectator() && !$p->isCreative() && ($d = $p->getPosition()->subtractVector($this->getPosition()))->lengthSquared() < 16 * 16 && $d->lengthSquared() > 4){
					$this->ramDir = (new \pocketmine\math\Vector3($d->x, 0, $d->z))->normalize();
					$this->ramTicks = 40;
					$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
					return;
				}
			}
		}
		parent::tickWandering($tickDiff);
	}
	protected const ENTITY_ID = EntityIds::GOAT;
	protected const DISPLAY_NAME = "Goat";
	protected const SPAWN_EGG_GETTER = "GOAT_SPAWN_EGG";
	protected const HEIGHT = 0.9;
	protected const WIDTH = 1.3;
}

class GuardianMob extends GenericHostileSwimmingMob{
	protected const LASER_CHARGE = 40;
	protected const LASER_DAMAGE = 6;
	/** BetterPMMP: vanilla guardian laser - locks on a visible player within 15 blocks, charges for 2 s (the beam is shown) and then hits; spikes hurt melee attackers */
	private int $laserTicks = 0;
	private int $laserCooldown = 40;
	private ?Player $laserTarget = null;
	private function setLaserTarget(?Player $target) : void{
		if($this->laserTarget !== $target){ $this->laserTarget = $target; $this->laserTicks = 0; $this->networkPropertiesDirty = true; }
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setLong(EntityMetadataProperties::TARGET_EID, $this->laserTarget !== null ? $this->laserTarget->getId() : -1); }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && !$source instanceof \pocketmine\event\entity\EntityDamageByChildEntityEvent && $this->laserTarget === null && ($d = $source->getDamager()) instanceof Living){
			$d->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $d, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, 2));
		}
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->laserCooldown = max(0, $this->laserCooldown - $tickDiff);
		$target = $this->nearestSurvivalPlayer(15);
		if($target === null || !$this->seesPlayer($target) || $this->laserCooldown > 0){
			$this->setLaserTarget(null);
			GenericFreeMotionMob::tickWandering($tickDiff);
			$this->constrainToWater();
			return;
		}
		$this->setLaserTarget($target);
		$d = $target->getPosition()->subtractVector($this->getPosition());
		$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
		$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
		if(($this->laserTicks += $tickDiff) >= static::LASER_CHARGE){
			$target->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, static::LASER_DAMAGE));
			$this->setLaserTarget(null);
			$this->laserCooldown = 60;
		}
	}
	protected const ENTITY_ID = EntityIds::GUARDIAN;
	protected const DISPLAY_NAME = "Guardian";
	protected const SPAWN_EGG_GETTER = "GUARDIAN_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

/**
 * BetterPMMP: vanilla happy ghast (1.21.90). A ghastling grows up in 20
 * minutes (snowballs speed it up by 10 % and heal it). A grown happy ghast
 * takes a harness (any colour); with it up to four players ride on top and
 * the first one flies it in the looking direction (jump: up). Shears take the
 * harness off again. It follows players holding snowballs and never attacks.
 */
class HappyGhastMob extends GenericFlyingAnimalMob implements \pocketmine\entity\riding\Rideable{
	use \pocketmine\entity\riding\RideableTrait;
	protected const ENTITY_ID = EntityIds::HAPPY_GHAST;
	protected const DISPLAY_NAME = "Happy Ghast";
	protected const SPAWN_EGG_GETTER = "HAPPY_GHAST_SPAWN_EGG";
	protected const HEIGHT = 4.0;
	protected const WIDTH = 4.0;
	protected const GRAVITY = 0.0;
	private const GROW_TICKS = 24000;
	private bool $ghastling = false;
	private int $growTicks = 0;
	private ?Item $harness = null;
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(4.0, 4.0); }
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(20);
		$this->setGhastling($nbt->getByte("BetterPMMPGhastling", 0) === 1);
		$this->growTicks = $nbt->getInt("BetterPMMPGrowTicks", $this->ghastling ? self::GROW_TICKS : 0);
		$harness = $nbt->getCompoundTag("BetterPMMPHarness");
		if($harness !== null){ try{ $this->harness = Item::nbtDeserialize($harness); }catch(\Exception){ $this->harness = null; } }
		$this->setPersistent();
	}
	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT()->setByte("BetterPMMPGhastling", $this->ghastling ? 1 : 0)->setInt("BetterPMMPGrowTicks", $this->growTicks);
		if($this->harness !== null){ $nbt->setTag("BetterPMMPHarness", $this->harness->nbtSerialize()); }
		return $nbt;
	}
	public function isGhastling() : bool{ return $this->ghastling; }
	public function setGhastling(bool $baby) : void{
		$this->ghastling = $baby;
		$this->growTicks = $baby ? self::GROW_TICKS : 0;
		$this->setScale($baby ? 0.2375 : 1.0);
		$this->networkPropertiesDirty = true;
	}
	public function getBodyArmor() : Item{ return $this->harness ?? VanillaItems::AIR(); }
	private static function isHarness(Item $item) : bool{ return $item instanceof \pocketmine\item\GenericBedrockItem && str_ends_with($item->getBedrockName(), "_harness"); }
	private static function isSnowball(Item $item) : bool{ return $item->getTypeId() === VanillaItems::SNOWBALL()->getTypeId(); }

	public function getMaxRiders() : int{ return 4; }
	public function getSeatPosition(int $seat) : \pocketmine\math\Vector3{
		return match($seat){ 0 => new \pocketmine\math\Vector3(0, 4.0, 1.0), 1 => new \pocketmine\math\Vector3(1.0, 4.0, 0), 2 => new \pocketmine\math\Vector3(0, 4.0, -1.0), default => new \pocketmine\math\Vector3(-1.0, 4.0, 0) };
	}
	public function isControlledByDriver() : bool{ return $this->harness !== null && \pocketmine\entity\riding\RidingManager::getDriver($this) instanceof Player; }
	public function canBeLeashed() : bool{ return true; }

	public function getInteractButtonText(Player $player) : string{
		$held = $player->getInventory()->getItemInHand();
		if($this->ghastling){ return self::isSnowball($held) ? "action.interact.feed" : ""; }
		if($this->harness === null){ return self::isHarness($held) ? "action.interact.equipharness" : ""; }
		if($held->getTypeId() === VanillaItems::SHEARS()->getTypeId() && count(\pocketmine\entity\riding\RidingManager::getRiders($this)) === 0){ return "action.interact.removeharness"; }
		return \pocketmine\entity\riding\RidingManager::getVehicle($player) === $this ? "" : "action.interact.ride.horse";
	}

	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if(self::isSnowball($held) && ($this->ghastling || $this->getHealth() < $this->getMaxHealth())){
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			$this->heal(new \pocketmine\event\entity\EntityRegainHealthEvent($this, 1, \pocketmine\event\entity\EntityRegainHealthEvent::CAUSE_EATING));
			if($this->ghastling){ $this->growTicks = max(0, $this->growTicks - (int) (self::GROW_TICKS * 0.1)); }
			$this->getWorld()->addParticle($this->getPosition()->add(0, 1, 0), new \pocketmine\world\particle\HappyVillagerParticle());
			return true;
		}
		if($this->ghastling){ return false; }
		if($this->harness === null && self::isHarness($held)){
			$this->harness = (clone $held)->setCount(1);
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			$this->broadcastEquipment();
			$this->networkPropertiesDirty = true;
			return true;
		}
		if($this->harness !== null && $held->getTypeId() === VanillaItems::SHEARS()->getTypeId() && count(\pocketmine\entity\riding\RidingManager::getRiders($this)) === 0){
			$this->getWorld()->dropItem($this->getPosition()->add(0, 1, 0), $this->harness);
			$this->harness = null;
			$this->broadcastEquipment();
			$this->networkPropertiesDirty = true;
			return true;
		}
		if($this->harness !== null && !$player->isSneaking() && \pocketmine\entity\riding\RidingManager::getVehicle($player) !== $this){
			return \pocketmine\entity\riding\RidingManager::mount($player, $this);
		}
		return false;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->ghastling && $this->isAlive() && ($this->growTicks -= $tickDiff) <= 0){
			$this->setGhastling(false);
		}
		return $update;
	}

	protected function tickWandering(int $tickDiff) : void{
		$driver = \pocketmine\entity\riding\RidingManager::getDriver($this);
		if($driver instanceof Player && $this->harness !== null){
			if($this->isClientDriven()){ return; }
			$location = $driver->getLocation();
			if($this->driverForward !== 0.0 || $this->driverStrafe !== 0.0 || $this->driverJumping){
				$dir = $driver->getDirectionVector();
				$yaw = deg2rad($this->driverYaw);
				$speed = 0.18;
				$vx = ($dir->x * $this->driverForward + cos($yaw) * $this->driverStrafe) * $speed;
				$vz = ($dir->z * $this->driverForward + sin($yaw) * $this->driverStrafe) * $speed;
				$vy = $dir->y * $this->driverForward * $speed + ($this->driverJumping ? 0.15 : 0.0);
				$this->setMotion(new \pocketmine\math\Vector3($vx, $vy, $vz));
				$this->setRotation($location->getYaw(), 0.0);
			}else{
				$m = $this->getMotion(); $this->setMotion($m->multiply(0.8));
			}
			return;
		}
		if(count(\pocketmine\entity\riding\RidingManager::getRiders($this)) > 0){
			$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0)); // passengers without a harnessed driver: hover
			return;
		}
		// follow players holding snowballs
		foreach($this->getWorld()->getPlayers() as $player){
			if(self::isSnowball($player->getInventory()->getItemInHand()) && ($d = $player->getPosition()->distance($this->getPosition())) < 16 && $d > 3){
				$to = $player->getPosition()->add(0, 2, 0)->subtractVector($this->getPosition());
				$this->setMotion($to->multiply(0.06 / max(0.1, $to->length()) * 1.5));
				return;
			}
		}
		parent::tickWandering($tickDiff);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->ghastling);
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->harness !== null);
		$properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $this->isControlledByDriver());
	}

	public function getDrops() : array{ return $this->harness !== null ? [$this->harness] : []; }
	protected function calculateFallDamage(float $fallDistance) : float{ return 0.0; }
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
	protected const CUBE_BASE_DAMAGE = 2;
	protected const CUBE_SMALL_DROPS = false;
	public function isFireProof() : bool{ return true; }
	protected function calculateFallDamage(float $fallDistance) : float{ return 0.0; }
	/** BetterPMMP: vanilla sizes - big (4), medium (2), small (1): health and damage by size, a dying cube splits into 2-4 of the next smaller size */
	private int $cubeSize = 0;
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.52, 0.52); }
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setCubeSize($nbt->getByte("Size", [1, 2, 2, 4, 4][mt_rand(0, 4)]), $nbt->getTag("Size") === null);
	}
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setByte("Size", $this->cubeSize); }
	public function getCubeSize() : int{ return $this->cubeSize; }
	public function setCubeSize(int $size, bool $heal = true) : void{
		$this->cubeSize = in_array($size, [1, 2, 4], true) ? $size : 1;
		$this->setScale((float) $this->cubeSize);
		$this->setMaxHealth($this->cubeSize * $this->cubeSize);
		if($heal || $this->getHealth() > $this->getMaxHealth()){ $this->setHealth($this->getMaxHealth()); }
		$this->networkPropertiesDirty = true;
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setInt(EntityMetadataProperties::VARIANT, $this->cubeSize); }
	protected function meleeDamage() : float{ return static::CUBE_BASE_DAMAGE + $this->cubeSize - (static::CUBE_BASE_DAMAGE === 0 && $this->cubeSize === 1 ? 1 : 0); }
	public function getXpDropAmount() : int{ return $this->cubeSize; }
	public function getDrops() : array{ return ($this->cubeSize === 1) === static::CUBE_SMALL_DROPS ? parent::getDrops() : []; }
	protected function onDeath() : void{
		parent::onDeath();
		if($this->cubeSize > 1){
			for($i = mt_rand(2, 4); $i > 0; --$i){
				$child = new static(Location::fromObject($this->getPosition()->add(mt_rand(-5, 5) / 10 * $this->cubeSize / 2, 0.2, mt_rand(-5, 5) / 10 * $this->cubeSize / 2), $this->getWorld(), mt_rand(0, 359), 0));
				$child->setCubeSize(intdiv($this->cubeSize, 2));
				$child->spawnToAll();
			}
		}
	}
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

class ParrotMob extends GenericFlyingAnimalMob implements TameablePet{
	protected const ENTITY_ID = EntityIds::PARROT;
	use TameableTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initTameable($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveTameable(parent::saveNBT()); }
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $this->syncTameable($properties); }
	/** BetterPMMP: a tamed, standing parrot hops onto its owner's shoulder (max. 2) and leaves when the owner jumps, falls, swims, flies or sleeps. */
	private ?Player $shoulder = null;
	private int $shoulderCooldown = 0;
	/** @var array<int, int> owner id => parrots on the shoulders */
	private static array $onShoulders = [];
	private function shoulderLink(int $type) : void{
		if($this->shoulder === null){ return; }
		$pk = \pocketmine\network\mcpe\protocol\SetActorLinkPacket::create(new \pocketmine\network\mcpe\protocol\types\entity\EntityLink($this->shoulder->getId(), $this->getId(), $type, true, false, 0.0));
		foreach([$this->shoulder, ...$this->shoulder->getViewers()] as $viewer){ if($viewer->isConnected()){ $viewer->getNetworkSession()->sendDataPacket($pk); } }
	}
	private function leaveShoulder() : void{
		if($this->shoulder === null){ return; }
		$this->shoulderLink(\pocketmine\network\mcpe\protocol\types\entity\EntityLink::TYPE_REMOVE);
		$id = $this->shoulder->getId();
		self::$onShoulders[$id] = max(0, (self::$onShoulders[$id] ?? 1) - 1);
		$this->shoulder = null;
		$this->shoulderCooldown = 100;
		$this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, false);
		$this->networkPropertiesDirty = true;
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->shoulderCooldown = max(0, $this->shoulderCooldown - $tickDiff);
		$owner = $this->shoulder;
		if($owner !== null){
			if(!$owner->isConnected() || !$owner->isAlive() || $owner->getWorld() !== $this->getWorld() || $owner->getFallDistance() > 0.4 || $owner->isUnderwater() || $owner->isFlying() || $owner->isGliding() || $owner->isSleeping() || $this->isSitting()){
				$this->leaveShoulder();
			}else{
				$this->teleport($owner->getPosition()->add(0, 1.4, 0));
				return;
			}
		}
		if($this->isTamed() && !$this->isSitting() && $this->shoulderCooldown === 0 && ($o = $this->getOwner()) !== null && $o->getWorld() === $this->getWorld()
			&& $o->isOnGround() && !$o->isSneaking() && (self::$onShoulders[$o->getId()] ?? 0) < 2 && $o->getPosition()->distanceSquared($this->getPosition()) < 2.5 * 2.5){
			$this->shoulder = $o;
			self::$onShoulders[$o->getId()] = (self::$onShoulders[$o->getId()] ?? 0) + 1;
			$this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::RIDING, true);
			$this->networkPropertiesDirty = true;
			$this->shoulderLink(\pocketmine\network\mcpe\protocol\types\entity\EntityLink::TYPE_PASSENGER);
			return;
		}
		if(!$this->tickPet($tickDiff)){ parent::tickWandering($tickDiff); }
	}
	public function spawnTo(Player $player) : void{
		parent::spawnTo($player);
		if($this->shoulder !== null && $player !== $this->shoulder){
			$player->getNetworkSession()->sendDataPacket(\pocketmine\network\mcpe\protocol\SetActorLinkPacket::create(new \pocketmine\network\mcpe\protocol\types\entity\EntityLink($this->shoulder->getId(), $this->getId(), \pocketmine\network\mcpe\protocol\types\entity\EntityLink::TYPE_PASSENGER, true, false, 0.0)));
		}
	}
	protected function onDispose() : void{ $this->leaveShoulder(); parent::onDispose(); }
	/** a parrot on a shoulder cannot be hit by its owner */
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->shoulder !== null && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && $source->getDamager() === $this->shoulder){ $source->cancel(); }
		parent::attack($source);
	}
	protected function getTamingItems() : array{ return [VanillaItems::WHEAT_SEEDS(), VanillaItems::MELON_SEEDS(), VanillaItems::PUMPKIN_SEEDS(), VanillaItems::BEETROOT_SEEDS(), VanillaItems::TORCHFLOWER_SEEDS()]; }
	protected function getTameChance() : int{ return 10; }
	/** a cookie poisons a parrot (vanilla) */
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($held->equals(VanillaItems::COOKIE(), false, false)){
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			$this->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::POISON(), 20 * 60, 0));
			$this->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($player, $this, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC, 1000));
			return true;
		}
		return $this->tameableInteract($player) ?? parent::onInteract($player, $clickPos);
	}
	protected const DISPLAY_NAME = "Parrot";
	protected const SPAWN_EGG_GETTER = "PARROT_SPAWN_EGG";
	protected const HEIGHT = 0.5;
	protected const WIDTH = 0.9;
}

class PhantomMob extends GenericHostileFlyingMob{
	/** BetterPMMP: vanilla phantom - circles 10-20 blocks above its target and swoops down to bite (6 damage), then climbs again; burns in daylight */
	private int $swoopCooldown = 100;
	private bool $swooping = false;
	private float $circleAngle = 0.0;
	protected function burnsInDaylight() : bool{ return true; }
	protected function calculateFallDamage(float $fallDistance) : float{ return 0.0; }
	protected function tickWandering(int $tickDiff) : void{
		$target = $this->nearestSurvivalPlayer(64);
		if($target === null){ GenericFreeMotionMob::tickWandering($tickDiff); return; }
		$pos = $this->getPosition();
		$tpos = $target->getPosition();
		if($this->swooping){
			$to = $tpos->add(0, 1.0, 0)->subtractVector($pos);
			$len = $to->length();
			if($len < 1.6){
				if($this->attackTime <= 0){ $this->attackTime = 20; $target->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $target, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, 6)); }
				$this->swooping = false; $this->swoopCooldown = mt_rand(100, 200);
			}else{
				$this->setMotion($to->multiply(0.55 / $len));
				$this->setRotation(rad2deg(atan2(-$to->x, $to->z)), -rad2deg(atan2($to->y, sqrt($to->x * $to->x + $to->z * $to->z))));
			}
			if($this->isCollidedHorizontally || $this->isCollidedVertically){ $this->swooping = false; $this->swoopCooldown = 60; }
			return;
		}
		$this->circleAngle += 0.05 * $tickDiff;
		$goal = $tpos->add(cos($this->circleAngle) * 12, 14, sin($this->circleAngle) * 12);
		$to = $goal->subtractVector($pos);
		$len = max(0.1, $to->length());
		$this->setMotion($to->multiply(min(0.35, $len * 0.1) / $len));
		$this->setRotation(rad2deg(atan2(-$to->x, $to->z)), 0.0);
		if(($this->swoopCooldown -= $tickDiff) <= 0 && $this->seesPlayer($target)){ $this->swooping = true; }
	}
	protected const ENTITY_ID = EntityIds::PHANTOM;
	protected const DISPLAY_NAME = "Phantom";
	protected const SPAWN_EGG_GETTER = "PHANTOM_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class PiglinMob extends GenericHostileMob{
	/** BetterPMMP: vanilla piglin - leaves players in gold armor alone until hit, and barters: give it a gold ingot and it throws an item back after 6 s */
	private int $barterTicks = 0;
	private int $angerTicks = 0;
	private ?Player $barterWith = null;
	private static function wearsGold(Player $player) : bool{
		foreach($player->getArmorInventory()->getContents() as $item){ if(str_contains(strtolower($item->getVanillaName()), "gold")){ return true; } }
		return false;
	}
	protected function isValidCombatTarget(Living $target, bool $keep) : bool{
		if($target instanceof Player && $this->angerTicks <= 0 && self::wearsGold($target)){ return false; }
		return parent::isValidCombatTarget($target, $keep);
	}
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && $source->getDamager() instanceof Player){ $this->angerTicks = 600; $this->barterTicks = 0; }
	}
	public function getInteractButtonText(Player $player) : string{
		return $this->barterTicks === 0 && $player->getInventory()->getItemInHand()->getTypeId() === VanillaItems::GOLD_INGOT()->getTypeId() ? "action.interact.barter" : "";
	}
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($this->barterTicks > 0 || $this->angerTicks > 0 || $held->getTypeId() !== VanillaItems::GOLD_INGOT()->getTypeId()){ return parent::onInteract($player, $clickPos); }
		if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
		$this->barterTicks = 120;
		$this->barterWith = $player;
		$this->getNavigator()->stop();
		return true;
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->angerTicks = max(0, $this->angerTicks - $tickDiff);
		if($this->barterTicks > 0){
			$this->getNavigator()->stop();
			$m = $this->getMotion(); $this->setMotion(new \pocketmine\math\Vector3(0, $m->y, 0));
			if(($this->barterTicks -= $tickDiff) <= 0){
				$this->barterTicks = 0;
				$loot = self::barterLoot();
				$to = $this->barterWith !== null && $this->barterWith->isConnected() && $this->barterWith->getWorld() === $this->getWorld() ? $this->barterWith->getPosition()->subtractVector($this->getPosition()) : $this->getDirectionVector();
				$len = max(0.1, $to->length());
				if($loot !== null){ $this->getWorld()->dropItem($this->getPosition()->add(0, 1.2, 0), $loot, new \pocketmine\math\Vector3($to->x / $len * 0.3, 0.25, $to->z / $len * 0.3)); }
				$this->barterWith = null;
			}
			return;
		}
		parent::tickWandering($tickDiff);
	}
	/** vanilla Bedrock piglin bartering table */
	private static function barterLoot() : ?Item{
		$table = [["enchanted_book", 1, 1, 5], ["iron_boots", 1, 1, 8], ["potion", 1, 1, 8], ["splash_potion", 1, 1, 8], ["water_bottle", 1, 1, 10], ["iron_nugget", 10, 36, 10], ["ender_pearl", 2, 4, 10],
			["string", 3, 9, 20], ["nether_quartz", 5, 12, 20], ["obsidian", 1, 1, 40], ["crying_obsidian", 1, 3, 40], ["fire_charge", 1, 1, 40], ["leather", 2, 4, 40], ["soul_sand", 2, 8, 40],
			["nether_brick", 2, 8, 40], ["arrow", 6, 12, 40], ["gravel", 8, 16, 40], ["blackstone", 8, 16, 40]];
		$total = 0; foreach($table as $row){ $total += $row[3]; }
		$roll = mt_rand(1, $total);
		foreach($table as [$name, $min, $max, $weight]){
			if(($roll -= $weight) > 0){ continue; }
			$soulSpeed = \pocketmine\item\enchantment\ExtraEnchantments::get("soul_speed");
			$item = match($name){
				"enchanted_book" => VanillaItems::ENCHANTED_BOOK()->addEnchantment(new \pocketmine\item\enchantment\EnchantmentInstance($soulSpeed, mt_rand(1, 3))),
				"iron_boots" => VanillaItems::IRON_BOOTS()->addEnchantment(new \pocketmine\item\enchantment\EnchantmentInstance($soulSpeed, mt_rand(1, 3))),
				"potion" => VanillaItems::POTION()->setType(\pocketmine\item\PotionType::FIRE_RESISTANCE()),
				"splash_potion" => VanillaItems::SPLASH_POTION()->setType(\pocketmine\item\PotionType::FIRE_RESISTANCE()),
				"water_bottle" => VanillaItems::POTION()->setType(\pocketmine\item\PotionType::WATER()),
				default => \pocketmine\item\StringToItemParser::getInstance()->parse($name),
			};
			return $item?->setCount(mt_rand($min, $max));
		}
		return null;
	}
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
	use RaiderTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initRaider($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveRaider(parent::saveNBT()); }
	public function getDrops() : array{ return $this->raiderDrops(parent::getDrops()); }
	protected function tickWandering(int $tickDiff) : void{ if($this->getCombatTarget() !== null || !$this->tickRaidMarch($tickDiff)){ parent::tickWandering($tickDiff); } }
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
	use RaiderTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initRaider($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveRaider(parent::saveNBT()); }
	public function getDrops() : array{ return $this->raiderDrops(parent::getDrops()); }
	protected function tickWandering(int $tickDiff) : void{ if($this->getCombatTarget() !== null || !$this->tickRaidMarch($tickDiff)){ parent::tickWandering($tickDiff); } }
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
	/** BetterPMMP: vanilla shulker - stays where it is, shoots homing bullets (4 damage + levitation) at visible players within 16 blocks, takes little damage while closed and teleports away when badly hurt */
	private int $shootCooldown = 40;
	private int $openTicks = 0;
	protected function getInitialGravity() : float{ return 0.0; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->openTicks <= 0 && $source->getCause() !== \pocketmine\event\entity\EntityDamageEvent::CAUSE_VOID){
			if($source instanceof \pocketmine\event\entity\EntityDamageByChildEntityEvent && $source->getChild() instanceof \pocketmine\entity\projectile\Arrow){ $source->cancel(); } // arrows bounce off a closed shell
			else{ $source->setModifier(-$source->getFinalDamage() * 0.8, \pocketmine\event\entity\EntityDamageEvent::MODIFIER_ARMOR); }
		}
		parent::attack($source);
		if($this->isAlive() && !$source->isCancelled() && $this->getHealth() < $this->getMaxHealth() / 2 && mt_rand(0, 3) === 0){
			$world = $this->getWorld(); $pos = $this->getPosition();
			for($i = 0; $i < 12; ++$i){
				$x = $pos->getFloorX() + mt_rand(-8, 8); $y = $pos->getFloorY() + mt_rand(-4, 4); $z = $pos->getFloorZ() + mt_rand(-8, 8);
				if($world->isInWorld($x, $y, $z) && $world->isChunkLoaded($x >> 4, $z >> 4) && $world->getBlockAt($x, $y, $z)->getTypeId() === \pocketmine\block\BlockTypeIds::AIR && $world->getBlockAt($x, $y - 1, $z)->isSolid()){
					$this->teleport(new \pocketmine\math\Vector3($x + 0.5, $y, $z + 0.5));
					$world->addSound($pos, new \pocketmine\world\sound\EndermanTeleportSound());
					break;
				}
			}
		}
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setInt(EntityMetadataProperties::SHULKER_PEEK_ID, $this->openTicks > 0 ? 100 : 0); }
	protected function tickWandering(int $tickDiff) : void{
		$this->setMotion(new \pocketmine\math\Vector3(0, 0, 0));
		$this->shootCooldown = max(0, $this->shootCooldown - $tickDiff);
		$wasOpen = $this->openTicks > 0;
		$this->openTicks = max(0, $this->openTicks - $tickDiff);
		$target = null;
		foreach($this->getWorld()->getPlayers() as $player){
			if($player->isAlive() && !$player->isCreative() && !$player->isSpectator() && $player->getPosition()->distanceSquared($this->getPosition()) < 16 * 16 && $this->canSeeEntity($player)){ $target = $player; break; }
		}
		if($target !== null){
			$this->openTicks = 30;
			if($this->shootCooldown === 0){
				$this->shootCooldown = mt_rand(20, 110);
				$bullet = new \pocketmine\entity\projectile\ShulkerBullet(Location::fromObject($this->getPosition()->add(0, 0.5, 0), $this->getWorld()), $this);
				$bullet->setTarget($target);
				$bullet->setMotion(new \pocketmine\math\Vector3(0, 0.15, 0));
				$bullet->spawnToAll();
				$this->getWorld()->broadcastPacketToViewers($this->getPosition(), \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::SHOOT, $this->getPosition(), -1, EntityIds::SHULKER, false, false, $this->getId(), null));
			}
		}
		if($wasOpen !== ($this->openTicks > 0)){ $this->networkPropertiesDirty = true; }
	}
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
	protected const CUBE_BASE_DAMAGE = 0;
	protected const CUBE_SMALL_DROPS = true;
	/** BetterPMMP: vanilla sizes - big (4), medium (2), small (1): health and damage by size, a dying cube splits into 2-4 of the next smaller size */
	private int $cubeSize = 0;
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.52, 0.52); }
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setCubeSize($nbt->getByte("Size", [1, 2, 2, 4, 4][mt_rand(0, 4)]), $nbt->getTag("Size") === null);
	}
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setByte("Size", $this->cubeSize); }
	public function getCubeSize() : int{ return $this->cubeSize; }
	public function setCubeSize(int $size, bool $heal = true) : void{
		$this->cubeSize = in_array($size, [1, 2, 4], true) ? $size : 1;
		$this->setScale((float) $this->cubeSize);
		$this->setMaxHealth($this->cubeSize * $this->cubeSize);
		if($heal || $this->getHealth() > $this->getMaxHealth()){ $this->setHealth($this->getMaxHealth()); }
		$this->networkPropertiesDirty = true;
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setInt(EntityMetadataProperties::VARIANT, $this->cubeSize); }
	protected function meleeDamage() : float{ return static::CUBE_BASE_DAMAGE + $this->cubeSize - (static::CUBE_BASE_DAMAGE === 0 && $this->cubeSize === 1 ? 1 : 0); }
	public function getXpDropAmount() : int{ return $this->cubeSize; }
	public function getDrops() : array{ return ($this->cubeSize === 1) === static::CUBE_SMALL_DROPS ? parent::getDrops() : []; }
	protected function onDeath() : void{
		parent::onDeath();
		if($this->cubeSize > 1){
			for($i = mt_rand(2, 4); $i > 0; --$i){
				$child = new static(Location::fromObject($this->getPosition()->add(mt_rand(-5, 5) / 10 * $this->cubeSize / 2, 0.2, mt_rand(-5, 5) / 10 * $this->cubeSize / 2), $this->getWorld(), mt_rand(0, 359), 0));
				$child->setCubeSize(intdiv($this->cubeSize, 2));
				$child->spawnToAll();
			}
		}
	}
	protected const ENTITY_ID = EntityIds::SLIME;
	protected const DISPLAY_NAME = "Slime";
	protected const SPAWN_EGG_GETTER = "SLIME_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
}

class SnifferMob extends GenericAnimalMob{
	/** BetterPMMP: vanilla sniffer - every 8 minutes or so it digs into grass, dirt, moss or mud and brings up a torchflower seed or a pitcher pod */
	private int $sniffCooldown = 2400;
	private int $digTicks = 0;
	protected function tickWandering(int $tickDiff) : void{
		if($this->digTicks > 0){
			$this->getNavigator()->stop();
			if(($this->digTicks -= $tickDiff) <= 0){
				$seed = mt_rand(0, 1) === 0 ? VanillaItems::TORCHFLOWER_SEEDS() : VanillaItems::PITCHER_POD();
				$this->getWorld()->dropItem($this->getPosition()->addVector($this->getDirectionVector()->multiply(1.5))->add(0, 0.5, 0), $seed);
			}
			return;
		}
		if(!$this->isBaby() && ($this->sniffCooldown -= $tickDiff) <= 0){
			$this->sniffCooldown = mt_rand(8400, 10800);
			$pos = $this->getPosition();
			$below = $this->getWorld()->getBlockAt($pos->getFloorX(), $pos->getFloorY() - 1, $pos->getFloorZ());
			$name = strtolower($below->getName());
			if(str_contains($name, "grass") || str_contains($name, "dirt") || str_contains($name, "moss") || str_contains($name, "mud") || str_contains($name, "podzol")){
				$this->digTicks = 80;
				return;
			}
		}
		parent::tickWandering($tickDiff);
	}
	protected const ENTITY_ID = EntityIds::SNIFFER;
	protected const DISPLAY_NAME = "Sniffer";
	protected const SPAWN_EGG_GETTER = "SNIFFER_SPAWN_EGG";
	protected const HEIGHT = 1.9;
	protected const WIDTH = 1.75;
}

class SnowGolemMob extends GenericAnimalMob{
	/** BetterPMMP: vanilla snow golem - throws snowballs at monsters within 10 blocks and leaves a snow trail outside hot biomes */
	private int $throwCooldown = 20;
	public function canBeLeashed() : bool{ return true; }
	public function getDrops() : array{ return [VanillaItems::SNOWBALL()->setCount(mt_rand(0, 15))]; }
	protected function tickWandering(int $tickDiff) : void{
		$this->throwCooldown = max(0, $this->throwCooldown - $tickDiff);
		$pos = $this->getPosition();
		$world = $this->getWorld();
		if($this->ticksLived % 10 === 0){
			[$temperature] = \pocketmine\world\weather\Weather::biomeClimate($world->getBiomeId($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
			if($temperature < 0.8 && $world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())->getTypeId() === \pocketmine\block\BlockTypeIds::AIR && $world->getBlockAt($pos->getFloorX(), $pos->getFloorY() - 1, $pos->getFloorZ())->isFullCube()){
				$world->setBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), VanillaBlocks::SNOW_LAYER());
			}
		}
		if($this->throwCooldown === 0){
			foreach($world->getNearbyEntities($this->getBoundingBox()->expandedCopy(10, 4, 10), $this) as $e){
				if(($e instanceof GenericHostileMob || $e instanceof GenericHostileFlyingMob || $e instanceof Zombie) && $e->isAlive()){
					$eye = $pos->add(0, 1.6, 0);
					$aim = $e->getPosition()->add(0, $e->getSize()->getHeight() * 0.6, 0)->subtractVector($eye);
					$flat = sqrt($aim->x * $aim->x + $aim->z * $aim->z);
					$ball = new \pocketmine\entity\projectile\Snowball(Location::fromObject($eye, $world), $this);
					$ball->setMotion((new \pocketmine\math\Vector3($aim->x, $aim->y + $flat * 0.1, $aim->z))->normalize()->multiply(1.3));
					$ball->spawnToAll();
					$this->setRotation(rad2deg(atan2(-$aim->x, $aim->z)), 0.0);
					$this->throwCooldown = 20;
					break;
				}
			}
		}
		parent::tickWandering($tickDiff);
	}
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
	protected function decorateArrow(\pocketmine\entity\projectile\Arrow $arrow) : void{ $arrow->setHitEffect(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::SLOWNESS(), 600)); }
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
	/** BetterPMMP: tadpoles grow into frogs after 20 minutes (slimeballs: -10 %) and can be caught in a bucket */
	private int $growTicks = 24000;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->growTicks = $nbt->getInt("BetterPMMPGrowTicks", 24000); }
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setInt("BetterPMMPGrowTicks", $this->growTicks); }
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($held->getTypeId() === VanillaItems::SLIMEBALL()->getTypeId()){
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			$this->growTicks -= 2400;
			$this->getWorld()->addParticle($this->getPosition(), new \pocketmine\world\particle\HappyVillagerParticle());
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && ($this->growTicks -= $tickDiff) <= 0){
			$frog = new FrogMob(Location::fromObject($this->getPosition(), $this->getWorld(), $this->getLocation()->getYaw(), 0));
			$frog->spawnToAll();
			$this->flagForDespawn();
		}
		return $update;
	}
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
	use RaiderTrait;
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->initRaider($nbt); }
	public function saveNBT() : CompoundTag{ return $this->saveRaider(parent::saveNBT()); }
	public function getDrops() : array{ return $this->raiderDrops(parent::getDrops()); }
	protected function tickWandering(int $tickDiff) : void{ if($this->getCombatTarget() !== null || !$this->tickRaidMarch($tickDiff)){ parent::tickWandering($tickDiff); } }
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

/**
 * BetterPMMP: vanilla witch - keeps 6-8 blocks away and throws a splash
 * potion every 3 s (slowness at range, poison while the target is healthy,
 * weakness up close, otherwise harming), drinks healing when hurt, fire
 * resistance when burning and water breathing under water, and takes only
 * 15 % of magic damage.
 */
class WitchMob extends GenericHostileMob{
	protected const ENTITY_ID = EntityIds::WITCH;
	protected const DISPLAY_NAME = "Witch";
	protected const SPAWN_EGG_GETTER = "WITCH_SPAWN_EGG";
	protected const HEIGHT = 1.8;
	protected const WIDTH = 0.6;
	private int $throwCooldown = 40;
	private int $drinkCooldown = 0;
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($source->getCause() === \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC){
			$source->setModifier(-$source->getFinalDamage() * 0.85, \pocketmine\event\entity\EntityDamageEvent::MODIFIER_RESISTANCE);
		}
		parent::attack($source);
	}
	private function drink(\pocketmine\item\PotionType $type) : void{
		foreach($type->getEffects() as $effect){ $this->getEffects()->add($effect); }
		if($type === \pocketmine\item\PotionType::HEALING()){ $this->heal(new \pocketmine\event\entity\EntityRegainHealthEvent($this, 4, \pocketmine\event\entity\EntityRegainHealthEvent::CAUSE_MAGIC)); }
		$this->getWorld()->broadcastPacketToViewers($this->getPosition(), \pocketmine\network\mcpe\protocol\LevelSoundEventPacket::create(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::DRINK, $this->getPosition(), -1, EntityIds::WITCH, false, false, $this->getId(), null));
		$this->drinkCooldown = 40;
	}
	protected function tickWandering(int $tickDiff) : void{
		$this->throwCooldown = max(0, $this->throwCooldown - $tickDiff);
		$this->drinkCooldown = max(0, $this->drinkCooldown - $tickDiff);
		if($this->drinkCooldown === 0){
			if($this->isUnderwater() && !$this->getEffects()->has(\pocketmine\entity\effect\VanillaEffects::WATER_BREATHING())){ $this->drink(\pocketmine\item\PotionType::WATER_BREATHING()); }
			elseif($this->isOnFire() && !$this->getEffects()->has(\pocketmine\entity\effect\VanillaEffects::FIRE_RESISTANCE())){ $this->drink(\pocketmine\item\PotionType::FIRE_RESISTANCE()); }
			elseif($this->getHealth() < $this->getMaxHealth() && mt_rand(0, 19) === 0){ $this->drink(\pocketmine\item\PotionType::HEALING()); }
		}
		$target = $this->findTarget($tickDiff);
		if($target === null){ parent::tickWandering($tickDiff); return; }
		$d = $target->getPosition()->subtractVector($this->getPosition());
		$horizontal = sqrt($d->x * $d->x + $d->z * $d->z);
		$this->setRotation(rad2deg(atan2(-$d->x, $d->z)), 0.0);
		if($horizontal > 8 || !$this->canSeeEntity($target)){
			$this->getNavigator()->moveTo($target->getPosition(), $this->getWalkSpeed(true), 6.0);
			$this->getNavigator()->tick($tickDiff);
		}else{
			$this->getNavigator()->stop();
		}
		if($this->throwCooldown > 0 || $horizontal > 10 || !$this->canSeeEntity($target)){ return; }
		$this->throwCooldown = 60;
		$effects = $target->getEffects();
		$type = match(true){
			$horizontal >= 8 && !$effects->has(\pocketmine\entity\effect\VanillaEffects::SLOWNESS()) => \pocketmine\item\PotionType::SLOWNESS(),
			$target->getHealth() >= 8 && !$effects->has(\pocketmine\entity\effect\VanillaEffects::POISON()) => \pocketmine\item\PotionType::POISON(),
			$horizontal <= 3 && !$effects->has(\pocketmine\entity\effect\VanillaEffects::WEAKNESS()) && mt_rand(0, 3) === 0 => \pocketmine\item\PotionType::WEAKNESS(),
			default => \pocketmine\item\PotionType::HARMING(),
		};
		$eye = $this->getPosition()->add(0, 1.4, 0);
		$aim = new \pocketmine\math\Vector3($d->x, $target->getPosition()->y + 1.1 - $eye->y + $horizontal * 0.2, $d->z);
		$potion = new \pocketmine\entity\projectile\SplashPotion(Location::fromObject($eye, $this->getWorld()), $this, $type);
		$potion->setMotion($aim->normalize()->multiply(0.75));
		$potion->spawnToAll();
		$this->getWorld()->addSound($eye, new \pocketmine\world\sound\ThrowSound());
	}
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

class WolfMob extends GenericAnimalMob implements TameablePet{
	/** BetterPMMP: wolf armor (64 durability) takes the damage instead of the wolf; scutes repair it, the owner removes it with shears */
	private ?Item $wolfArmor = null;
	private int $wolfArmorDurability = 0;
	public function getBodyArmor() : Item{ return $this->wolfArmor ?? VanillaItems::AIR(); }
	private static function isWolfArmor(Item $item) : bool{ return $item instanceof \pocketmine\item\GenericBedrockItem && $item->getBedrockName() === "minecraft:wolf_armor"; }
	private static function isScute(Item $item) : bool{ return $item instanceof \pocketmine\item\GenericBedrockItem && $item->getBedrockName() === "minecraft:armadillo_scute"; }
	private function wolfArmorInteract(Player $player) : bool{
		if(!$this->isTamed() || !$this->isOwnedBy($player)){ return false; }
		$held = $player->getInventory()->getItemInHand();
		if($this->wolfArmor === null && self::isWolfArmor($held) && !$this->isBaby()){
			$this->wolfArmor = (clone $held)->setCount(1);
			$this->wolfArmorDurability = 64 - $held->getNamedTag()->getInt("BetterPMMPDamage", 0);
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			$this->broadcastEquipment();
			return true;
		}
		if($this->wolfArmor !== null && self::isScute($held) && $this->wolfArmorDurability < 64){
			$this->wolfArmorDurability = min(64, $this->wolfArmorDurability + 8);
			if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
			return true;
		}
		if($this->wolfArmor !== null && $held->getTypeId() === VanillaItems::SHEARS()->getTypeId()){
			$armor = $this->wolfArmor;
			$tag = $armor->getNamedTag(); $tag->setInt("BetterPMMPDamage", 64 - $this->wolfArmorDurability); $armor->setNamedTag($tag);
			$this->getWorld()->dropItem($this->getPosition(), $armor);
			$this->wolfArmor = null;
			$this->broadcastEquipment();
			return true;
		}
		return false;
	}
	protected const ENTITY_ID = EntityIds::WOLF;
	use TameableTrait;
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt); $this->initTameable($nbt);
		$armor = $nbt->getCompoundTag("BetterPMMPWolfArmor");
		if($armor !== null){ try{ $this->wolfArmor = Item::nbtDeserialize($armor); $this->wolfArmorDurability = $nbt->getInt("BetterPMMPWolfArmorDurability", 64); }catch(\Exception){ $this->wolfArmor = null; } }
	}
	public function saveNBT() : CompoundTag{
		$nbt = $this->saveTameable(parent::saveNBT());
		if($this->wolfArmor !== null){ $nbt->setTag("BetterPMMPWolfArmor", $this->wolfArmor->nbtSerialize()); $nbt->setInt("BetterPMMPWolfArmorDurability", $this->wolfArmorDurability); }
		return $nbt;
	}
	public function getDrops() : array{ $drops = parent::getDrops(); if($this->wolfArmor !== null){ $drops[] = $this->wolfArmor; } return $drops; }
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $this->syncTameable($properties); }
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{ return $this->wolfArmorInteract($player) || ($this->tameableInteract($player) ?? parent::onInteract($player, $clickPos)); }
	protected function getTamingItems() : array{ return [VanillaItems::BONE()]; }
	protected function getTameChance() : int{ return 3; }
	protected function getTamedMaxHealth() : int{ return 40; }
	protected function petAttackDamage() : float{ return 4.0; }
	protected function usesCollar() : bool{ return true; }
	/** wild wolves fight back instead of fleeing */
	protected function shouldFleeFromAttacker(\pocketmine\event\entity\EntityDamageByEntityEvent $source) : bool{ return false; }
	public function attack(\pocketmine\event\entity\EntityDamageEvent $source) : void{
		if($this->isSitting() && !$source->isCancelled() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent){ $this->setSitting(false); }
		if($this->wolfArmor !== null && !$source->isCancelled() && !in_array($source->getCause(), [\pocketmine\event\entity\EntityDamageEvent::CAUSE_STARVATION, \pocketmine\event\entity\EntityDamageEvent::CAUSE_DROWNING, \pocketmine\event\entity\EntityDamageEvent::CAUSE_VOID, \pocketmine\event\entity\EntityDamageEvent::CAUSE_MAGIC], true)){
			// the armor takes the hit
			$this->wolfArmorDurability -= max(1, (int) ceil($source->getFinalDamage()));
			$source->cancel();
			if($this->wolfArmorDurability <= 0){
				$this->wolfArmor = null;
				$this->broadcastEquipment();
				$this->getWorld()->addSound($this->getPosition(), new \pocketmine\world\sound\ItemBreakSound());
			}
		}
		parent::attack($source);
		if($this->isAlive() && $source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living){
			if($this->isTamed() && $damager instanceof Player && $this->isOwnedBy($damager)){ return; }
			$this->angryAt = $damager; $this->angryTicks = 400;
		}
	}
	private ?Living $angryAt = null;
	private int $angryTicks = 0;
	private int $biteCooldown = 0;
	protected function tickWandering(int $tickDiff) : void{
		$this->biteCooldown = max(0, $this->biteCooldown - $tickDiff);
		if($this->angryAt !== null && !$this->isSitting()){
			$t = $this->angryAt;
			if(($this->angryTicks -= $tickDiff) <= 0 || !$t->isAlive() || $t->isClosed() || $t->getWorld() !== $this->getWorld() || ($t instanceof Player && ($t->isCreative() || $t->isSpectator()))){ $this->angryAt = null; }
			else{
				if($t->getPosition()->distanceSquared($this->getPosition()) < 2.5 * 2.5 && $this->biteCooldown === 0){ $this->biteCooldown = 20; $t->attack(new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $t, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->isTamed() ? 4 : 3)); }
				$this->getNavigator()->moveTo($t->getPosition(), $this->getWalkSpeed(true), 1.2); $this->getNavigator()->tick($tickDiff);
				return;
			}
		}
		if(!$this->tickPet($tickDiff)){ parent::tickWandering($tickDiff); }
	}
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
	/** BetterPMMP: vanilla cure - a zombie villager with Weakness that is fed a golden apple shakes for 3-5 minutes and turns into a villager */
	private int $cureTicks = 0;
	protected function burnsInDaylight() : bool{ return true; }
	protected function initEntity(CompoundTag $nbt) : void{ parent::initEntity($nbt); $this->cureTicks = $nbt->getInt("BetterPMMPCureTicks", 0); if($this->cureTicks > 0){ $this->setPersistent(); } }
	public function saveNBT() : CompoundTag{ return parent::saveNBT()->setInt("BetterPMMPCureTicks", $this->cureTicks); }
	public function isCuring() : bool{ return $this->cureTicks > 0; }
	public function getInteractButtonText(Player $player) : string{
		return $this->cureTicks === 0 && $this->getEffects()->has(\pocketmine\entity\effect\VanillaEffects::WEAKNESS()) && $player->getInventory()->getItemInHand()->getTypeId() === VanillaItems::GOLDEN_APPLE()->getTypeId() ? "action.interact.cure" : "";
	}
	public function onInteract(Player $player, \pocketmine\math\Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($this->cureTicks > 0 || $held->getTypeId() !== VanillaItems::GOLDEN_APPLE()->getTypeId() || !$this->getEffects()->has(\pocketmine\entity\effect\VanillaEffects::WEAKNESS())){ return parent::onInteract($player, $clickPos); }
		if($player->hasFiniteResources()){ $held->pop(); $player->getInventory()->setItemInHand($held); }
		$this->cureTicks = mt_rand(3600, 6000);
		$this->setPersistent();
		$this->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::STRENGTH(), $this->cureTicks, 0));
		$this->networkPropertiesDirty = true;
		$this->getWorld()->addSound($this->getPosition(), new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::REMEDY));
		return true;
	}
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$update = parent::entityBaseTick($tickDiff);
		if($this->cureTicks > 0 && $this->isAlive() && ($this->cureTicks -= $tickDiff) <= 0){
			$villager = new Villager(Location::fromObject($this->getPosition(), $this->getWorld(), $this->getLocation()->getYaw(), 0));
			$villager->spawnToAll();
			$villager->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(\pocketmine\entity\effect\VanillaEffects::NAUSEA(), 200, 0));
			$this->getWorld()->addSound($this->getPosition(), new \pocketmine\world\sound\VanillaSound(\pocketmine\network\mcpe\protocol\types\LevelSoundEvent::UNFECT));
			$this->flagForDespawn();
		}
		return $update;
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{ parent::syncNetworkData($properties); $properties->setGenericFlag(EntityMetadataFlags::CONVERTING, $this->cureTicks > 0); }
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
