<?php

/*
 * BetterPMMP - mangrove propagules, seagrass, bubble columns, conduits,
 * lodestones and sculk catalysts.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Conduit as TileConduit;
use pocketmine\block\utils\SupportType;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Compass;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\generator\vanilla\BlockWriter;
use pocketmine\world\generator\vanilla\VanillaFeatures;
use pocketmine\world\LodestoneTracker;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\VanillaSound;
use pocketmine\world\World;
use function abs;
use function floor;
use function in_array;
use function intdiv;
use function max;
use function min;
use function mt_rand;

/**
 * Mangrove propagules: hanging ones (under mangrove leaves) ripen through five
 * stages; planted ones grow into a mangrove tree on random ticks or with bone
 * meal.
 */
class VanillaMangrovePropagule extends GenericBedrockBlock{

	public const MAX_STAGE = 4;

	private function state(string $name) : int{
		$tag = $this->getStateData()->getStates()[$name] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function isHanging() : bool{ return $this->state("hanging") === 1; }
	public function getStage() : int{ return $this->state("propagule_stage"); }
	public function isSolid() : bool{ return false; }
	public function canBeReplaced() : bool{ return false; }

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public static function isSoil(Block $block) : bool{
		return in_array($block->getTypeId(), [
			BlockTypeIds::DIRT, BlockTypeIds::GRASS, BlockTypeIds::PODZOL, BlockTypeIds::MYCELIUM, BlockTypeIds::FARMLAND,
			BlockTypeIds::MUD, BlockTypeIds::MUDDY_MANGROVE_ROOTS, BlockTypeIds::CLAY,
		], true) || ($block instanceof GenericBedrockBlock && in_array($block->getBedrockName(), ["minecraft:moss_block", "minecraft:pale_moss_block", "minecraft:rooted_dirt"], true));
	}

	private static function isLeaves(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::MANGROVE_LEAVES;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face === Facing::DOWN && self::isLeaves($blockReplace->getSide(Facing::UP))){
			$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["hanging" => 1, "propagule_stage" => 0]));
			return true;
		}
		if(!self::isSoil($blockReplace->getSide(Facing::DOWN))){
			return false;
		}
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["hanging" => 0, "propagule_stage" => 0]));
		return true;
	}

	public function onNearbyBlockChange() : void{
		$ok = $this->isHanging() ? self::isLeaves($this->getSide(Facing::UP)) : self::isSoil($this->getSide(Facing::DOWN));
		if(!$ok){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if($this->isHanging()){
			if($this->getStage() < self::MAX_STAGE && mt_rand(1, 10) === 1){
				$this->position->getWorld()->setBlock($this->position, $this->withProperties(["propagule_stage" => $this->getStage() + 1]));
			}
			return;
		}
		$world = $this->position->getWorld();
		if($world->getFullLightAt($this->position->getFloorX(), $this->position->getFloorY() + 1, $this->position->getFloorZ()) >= 9 && mt_rand(1, 7) === 1){
			$this->grow(null);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer){
			return false;
		}
		$world = $this->position->getWorld();
		if($this->isHanging()){
			if($this->getStage() >= self::MAX_STAGE){
				return false;
			}
			$world->setBlock($this->position, $this->withProperties(["propagule_stage" => $this->getStage() + 1]));
		}elseif(mt_rand(1, 100) <= 45){
			$this->grow($player);
		}
		$world->addParticle($this->position->add(0.5, 0.5, 0.5), new HappyVillagerParticle());
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	public function grow(?Player $player) : bool{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		return VanillaSapling::growStructure($this, $player, $x, $y, $z, [[$x, $z]], fn(BlockWriter $w, Random $r) : bool => VanillaFeatures::mangrove($w, $x, $y - 1, $z, $r));
	}
}

/**
 * Seagrass: bone meal turns it into tall seagrass when there is water above.
 * Breaking either kind leaves the water behind, both halves break together.
 */
class VanillaSeagrass extends GenericBedrockBlock{

	private function type() : string{
		$tag = $this->getStateData()->getStates()["sea_grass_type"] ?? null;
		return $tag === null ? "default" : (string) $tag->getValue();
	}

	public function isSolid() : bool{ return false; }
	public function canBeReplaced() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return $blockReplace instanceof Water && $blockReplace->isSource() && $blockReplace->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer || $this->type() !== "default"){
			return false;
		}
		$world = $this->position->getWorld();
		$above = $this->getSide(Facing::UP);
		if(!$above instanceof Water || !$above->isSource()){
			return false;
		}
		$world->setBlock($this->position, $this->withProperties(["sea_grass_type" => "double_bot"]));
		$world->setBlock($above->getPosition(), $this->withProperties(["sea_grass_type" => "double_top"]));
		$world->addParticle($this->position->add(0.5, 0.5, 0.5), new HappyVillagerParticle());
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::WATER());
		$other = match($this->type()){
			"double_bot" => $this->getSide(Facing::UP),
			"double_top" => $this->getSide(Facing::DOWN),
			default => null,
		};
		if($other instanceof self){
			$world->setBlock($other->getPosition(), VanillaBlocks::WATER());
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$below = $this->getSide(Facing::DOWN);
		$ok = match($this->type()){
			"double_top" => $below instanceof self,
			"double_bot" => $this->getSide(Facing::UP) instanceof self && $below->getSupportType(Facing::UP) === SupportType::FULL,
			default => $below->getSupportType(Facing::UP) === SupportType::FULL,
		};
		if(!$ok){
			$this->position->getWorld()->setBlock($this->position, VanillaBlocks::WATER());
		}
	}
}

/**
 * Bubble columns: soul sand under water makes an upward column, magma a
 * downward one. The column grows up through water sources and turns back
 * into water when its base goes away. Items and mobs inside are pushed.
 */
class VanillaBubbleColumn extends GenericBedrockBlock{

	public function isDragDown() : bool{
		$tag = $this->getStateData()->getStates()["drag_down"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	public function isSolid() : bool{ return false; }
	public function canBeReplaced() : bool{ return true; }
	public function hasEntityCollision() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	/** The drag of a column standing on this block, or null if it supports none. */
	public static function dragFor(Block $base) : ?bool{
		if($base->getTypeId() === BlockTypeIds::SOUL_SAND){
			return false;
		}
		if($base->getTypeId() === BlockTypeIds::MAGMA){
			return true;
		}
		return $base instanceof self ? $base->isDragDown() : null;
	}

	/** Places (or refreshes) a column above the given base block if there is water. */
	public static function updateAbove(Block $base) : void{
		$drag = self::dragFor($base);
		if($drag === null){
			return;
		}
		$above = $base->getSide(Facing::UP);
		$column = ExtraVanillaBlocks::get("minecraft:bubble_column");
		if($column === null){
			return;
		}
		if(($above instanceof Water && $above->isSource()) || ($above instanceof self && $above->isDragDown() !== $drag)){
			$above->getPosition()->getWorld()->setBlock($above->getPosition(), $column->withProperties(["drag_down" => $drag ? 1 : 0]));
		}
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$drag = self::dragFor($this->getSide(Facing::DOWN));
		if($drag === null){
			$world->setBlock($this->position, VanillaBlocks::WATER());
			return;
		}
		if($drag !== $this->isDragDown()){
			$world->setBlock($this->position, $this->withProperties(["drag_down" => $drag ? 1 : 0]));
			return;
		}
		self::updateAbove($this);
	}

	public function onEntityInside(Entity $entity) : bool{
		if($entity instanceof Player){
			return true; // the client moves players itself
		}
		$motion = $entity->getMotion();
		$air = !$this->getSide(Facing::UP) instanceof self && !$this->getSide(Facing::UP) instanceof Water;
		$y = $this->isDragDown()
			? max($air ? -0.9 : -0.3, $motion->y - 0.03)
			: min($air ? 1.8 : 0.7, $motion->y + ($air ? 0.1 : 0.06));
		$entity->setMotion(new Vector3($motion->x, $y, $motion->z));
		return true;
	}
}

/**
 * Conduits: a frame of prismarine and sea lanterns around a conduit in water
 * activates it. It gives Conduit Power to players in water within 16 blocks per
 * 7 frame blocks and, with a full frame, hurts hostile mobs nearby.
 */
class VanillaConduit extends GenericBedrockBlock{

	private const FRAME_BLOCKS = [BlockTypeIds::PRISMARINE, BlockTypeIds::PRISMARINE_BRICKS, BlockTypeIds::DARK_PRISMARINE, BlockTypeIds::SEA_LANTERN];
	private const HOSTILE = [
		EntityIds::DROWNED, EntityIds::GUARDIAN, EntityIds::ELDER_GUARDIAN, EntityIds::ZOMBIE, EntityIds::SKELETON, EntityIds::CREEPER,
		EntityIds::SPIDER, EntityIds::CAVE_SPIDER, EntityIds::WITCH, EntityIds::HUSK, EntityIds::STRAY, EntityIds::BOGGED, EntityIds::PHANTOM,
		EntityIds::ZOMBIE_VILLAGER_V2, EntityIds::PILLAGER, EntityIds::VINDICATOR, EntityIds::SLIME, EntityIds::SILVERFISH, EntityIds::ENDERMITE,
	];

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }
	public function getLightLevel() : int{ return 15; }

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(5 / 16, 5 / 16, 5 / 16)];
	}

	public function onPostPlace() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	/** Number of frame blocks, or 0 if the conduit is not surrounded by water. */
	public static function countFrame(World $world, int $x, int $y, int $z) : int{
		for($dx = -1; $dx <= 1; ++$dx){
			for($dy = -1; $dy <= 1; ++$dy){
				for($dz = -1; $dz <= 1; ++$dz){
					if(($dx !== 0 || $dy !== 0 || $dz !== 0) && !self::isWater($world, $x + $dx, $y + $dy, $z + $dz)){
						return 0;
					}
				}
			}
		}
		$count = 0;
		for($dx = -2; $dx <= 2; ++$dx){
			for($dy = -2; $dy <= 2; ++$dy){
				for($dz = -2; $dz <= 2; ++$dz){
					$ax = abs($dx);
					$ay = abs($dy);
					$az = abs($dz);
					if(($ax > 1 || $ay > 1 || $az > 1) && (($dx === 0 && ($ay === 2 || $az === 2)) || ($dy === 0 && ($ax === 2 || $az === 2)) || ($dz === 0 && ($ax === 2 || $ay === 2)))){
						if(in_array($world->getBlockAt($x + $dx, $y + $dy, $z + $dz)->getTypeId(), self::FRAME_BLOCKS, true)){
							++$count;
						}
					}
				}
			}
		}
		return $count;
	}

	private static function isWater(World $world, int $x, int $y, int $z) : bool{
		return $world->getBlockAt($x, $y, $z) instanceof Water || $world->getLiquidAt($x, $y, $z) instanceof Water;
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileConduit){
			return;
		}
		$frame = self::countFrame($world, $x, $y, $z);
		$active = $frame >= 16;
		if($active !== $tile->isActive()){
			$tile->setActive($active);
			$world->addSound($this->position, new VanillaSound($active ? LevelSoundEvent::CONDUIT_ACTIVATE : LevelSoundEvent::CONDUIT_DEACTIVATE));
		}
		if($active){
			$range = intdiv($frame, 7) * 16;
			$center = $this->position->add(0.5, 0.5, 0.5);
			foreach($world->getNearbyEntities(new AxisAlignedBB($x - $range, $y - $range, $z - $range, $x + $range + 1, $y + $range + 1, $z + $range + 1)) as $entity){
				if($entity instanceof Player && $entity->getPosition()->distance($center) <= $range && ($entity->isUnderwater() || self::isWater($world, (int) floor($entity->getPosition()->x), (int) floor($entity->getPosition()->y), (int) floor($entity->getPosition()->z)))){
					$entity->getEffects()->add(new EffectInstance(VanillaEffects::CONDUIT_POWER(), 260, 0, true, true));
				}
			}
			if($frame >= 42 && $tile->tickAttack()){
				$target = null;
				foreach($world->getNearbyEntities(new AxisAlignedBB($x - 8, $y - 8, $z - 8, $x + 9, $y + 9, $z + 9)) as $entity){
					if($entity instanceof Living && !$entity instanceof Player && $entity->isAlive() && in_array($entity::getNetworkTypeId(), self::HOSTILE, true) && $entity->isUnderwater()){
						$target = $entity;
						break;
					}
				}
				if($target !== null){
					$target->attack(new EntityDamageEvent($target, EntityDamageEvent::CAUSE_MAGIC, 4));
					$world->addSound($this->position, new VanillaSound(LevelSoundEvent::CONDUIT_ATTACK));
				}
			}
		}
		$world->scheduleDelayedBlockUpdate($this->position, 40);
	}
}

/** Lodestones: using a compass on one turns it into a lodestone compass pointing here. */
class VanillaLodestone extends GenericBedrockBlock{

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Compass){
			return false;
		}
		$compass = LodestoneTracker::createCompass($this->position);
		if($compass === null){
			return false;
		}
		$item->pop();
		$returnedItems[] = $compass;
		$this->position->getWorld()->addSound($this->position, new VanillaSound(LevelSoundEvent::LODESTONE_COMPASS_LINK_COMPASS_TO_LODESTONE));
		return true;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		LodestoneTracker::forget($this->position);
		return parent::onBreak($item, $player, $returnedItems);
	}
}

/**
 * Sculk catalysts: when a mob dies within 8 blocks, its experience is turned
 * into sculk spreading over the ground around the death point.
 */
class VanillaSculkCatalyst extends GenericBedrockBlock{

	private const REPLACEABLE = [
		BlockTypeIds::STONE, BlockTypeIds::GRANITE, BlockTypeIds::DIORITE, BlockTypeIds::ANDESITE, BlockTypeIds::DEEPSLATE, BlockTypeIds::TUFF,
		BlockTypeIds::DIRT, BlockTypeIds::GRASS, BlockTypeIds::PODZOL, BlockTypeIds::MYCELIUM,
		BlockTypeIds::SAND, BlockTypeIds::RED_SAND, BlockTypeIds::GRAVEL, BlockTypeIds::CLAY, BlockTypeIds::MUD,
		BlockTypeIds::NETHERRACK, BlockTypeIds::SOUL_SAND, BlockTypeIds::SOUL_SOIL, BlockTypeIds::CALCITE, BlockTypeIds::SMOOTH_BASALT,
		BlockTypeIds::BASALT, BlockTypeIds::BLACKSTONE, BlockTypeIds::END_STONE, BlockTypeIds::HARDENED_CLAY,
	];

	public function getLightLevel() : int{ return 6; }

	/**
	 * Called when a mob dies. Returns whether a catalyst took its experience.
	 */
	public static function absorb(Position $death, int $xp) : bool{
		if($xp <= 0){
			return false;
		}
		$world = $death->getWorld();
		$cx = (int) floor($death->x);
		$cy = (int) floor($death->y);
		$cz = (int) floor($death->z);
		$catalyst = null;
		for($dx = -8; $dx <= 8 && $catalyst === null; ++$dx){
			for($dy = -8; $dy <= 8 && $catalyst === null; ++$dy){
				for($dz = -8; $dz <= 8; ++$dz){
					if(!$world->isInWorld($cx + $dx, $cy + $dy, $cz + $dz)){
						continue;
					}
					$block = $world->getBlockAt($cx + $dx, $cy + $dy, $cz + $dz);
					if($block instanceof self){
						$catalyst = $block;
						break;
					}
				}
			}
		}
		if($catalyst === null){
			return false;
		}
		$world->setBlock($catalyst->position, $catalyst->withProperties(["bloom" => 1]));
		$world->scheduleDelayedBlockUpdate($catalyst->position, 8);
		$world->addSound($catalyst->position, new VanillaSound(LevelSoundEvent::BLOCK_SCULK_CATALYST_BLOOM));
		self::spread($world, $cx, $cy, $cz, $xp);
		return true;
	}

	public function onScheduledUpdate() : void{
		$this->position->getWorld()->setBlock($this->position, $this->withProperties(["bloom" => 0]));
	}

	/** Turns up to $charge exposed ground blocks around (x, y, z) into sculk. */
	public static function spread(World $world, int $x, int $y, int $z, int $charge) : int{
		$vein = ExtraVanillaBlocks::get("minecraft:sculk_vein");
		$converted = 0;
		$attempts = $charge * 8;
		$radius = min(6, 2 + intdiv($charge, 4));
		while($charge > 0 && $attempts-- > 0){
			$bx = $x + mt_rand(-$radius, $radius);
			$bz = $z + mt_rand(-$radius, $radius);
			// find the ground block in this column near the death height
			for($by = $y + 2; $by >= $y - 3; --$by){
				if(!$world->isInWorld($bx, $by, $bz)){
					continue;
				}
				$block = $world->getBlockAt($bx, $by, $bz);
				if(!in_array($block->getTypeId(), self::REPLACEABLE, true) && !($block instanceof GenericBedrockBlock && in_array($block->getBedrockName(), ["minecraft:moss_block", "minecraft:rooted_dirt", "minecraft:dripstone_block"], true))){
					continue;
				}
				$above = $world->getBlockAt($bx, $by + 1, $bz);
				if($above->isSolid()){
					continue;
				}
				$world->setBlockAt($bx, $by, $bz, VanillaBlocks::SCULK());
				++$converted;
				--$charge;
				if($vein !== null && $above->getTypeId() === BlockTypeIds::AIR && mt_rand(1, 4) === 1){
					$world->setBlockAt($bx, $by + 1, $bz, $vein->withProperties(["multi_face_direction_bits" => 1]));
				}
				break;
			}
		}
		return $converted;
	}
}
