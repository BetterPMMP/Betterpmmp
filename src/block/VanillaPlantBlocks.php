<?php

/*
 * BetterPMMP - vanilla behaviour of plants, moss, poplar logs and leaves of the Bedrock palette.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Axe;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\sound\ItemUseOnBlockSound;
use function abs;
use function in_array;
use function str_replace;
use function max;
use function min;
use function mt_rand;
use function str_contains;

/**
 * Bush, firefly bush, short and tall dry grass, golden dandelion, pale hanging moss: they need a block to grow
 * on (hanging moss: above), break when it is gone, can be walked through, and grass can be built over.
 */
class VanillaGroundPlant extends GenericBedrockBlock{

	public function isSolid() : bool{ return false; }

	public function canBeReplaced() : bool{
		return in_array($this->getBedrockName(), ["minecraft:short_dry_grass", "minecraft:tall_dry_grass", "minecraft:bush"], true);
	}

	protected function recalculateCollisionBoxes() : array{ return []; }

	private function hanging() : bool{
		return $this->getBedrockName() === "minecraft:pale_hanging_moss";
	}

	private function supportedBy(Block $block) : bool{
		if($this->hanging()){
			return $block->isSolid() || self::nameOf($block) === "minecraft:pale_hanging_moss";
		}
		$name = self::nameOf($block);
		$dry = str_contains($this->getBedrockName(), "dry_grass");
		return $block instanceof Dirt || $block instanceof Grass || $block instanceof Podzol || $block instanceof Mycelium || $block instanceof Farmland
			|| in_array($name, ["minecraft:mud", "minecraft:muddy_mangrove_roots", "minecraft:moss_block", "minecraft:pale_moss_block"], true)
			|| ($dry && ($block instanceof Sand || $block instanceof HardenedClay));
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock) && $this->supportedBy($blockReplace->getSide($this->hanging() ? Facing::UP : Facing::DOWN));
	}

	public function onNearbyBlockChange() : void{
		if(!$this->supportedBy($this->getSide($this->hanging() ? Facing::UP : Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	/** Bedrock name of any block (for blocks the core has no class for). */
	public static function nameOf(Block $block) : string{
		if($block instanceof GenericBedrockBlock){
			return $block->getBedrockName();
		}
		try{
			return \pocketmine\world\format\io\GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId())->getName();
		}catch(\Throwable){
			return "";
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		//grass and bushes only drop with shears (handled by the tool check of the item), the rest drops itself
		return in_array($this->getBedrockName(), ["minecraft:short_dry_grass", "minecraft:tall_dry_grass"], true) ? [] : parent::getDropsForCompatibleTool($item);
	}
}

/**
 * Leaf litter and wildflowers: up to four pieces in one block. Using the item on it adds a piece, bone meal
 * adds one too (and drops one when the block is full), every piece drops when broken.
 */
class VanillaSegmentedPlant extends GenericBedrockBlock{
	private const MAX_GROWTH = 3;

	public function isSolid() : bool{ return false; }

	public function canBeReplaced() : bool{ return false; }

	protected function recalculateCollisionBoxes() : array{ return []; }

	private function growth() : int{
		$tag = $this->getStateData()->getStates()["growth"] ?? null;
		return $tag === null ? 0 : min(self::MAX_GROWTH, (int) $tag->getValue());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			//the first piece faces like the player
			$direction = match($player->getHorizontalFacing()){
				Facing::NORTH => "north",
				Facing::EAST => "east",
				Facing::WEST => "west",
				default => "south",
			};
			try{
				$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => $direction, "growth" => 0]));
				return true;
			}catch(\InvalidArgumentException){
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock) && $blockReplace->getSide(Facing::DOWN)->isSolid();
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$same = $item->getBlock()->hasSameTypeId($this);
		if(!$same && !$item instanceof Fertilizer){
			return false;
		}
		if($this->growth() < self::MAX_GROWTH){
			$world->setBlock($this->position, $this->withProperties(["growth" => $this->growth() + 1]));
		}elseif($item instanceof Fertilizer){
			$world->dropItem($this->position->add(0.5, 0.5, 0.5), $this->asItem());
		}else{
			return false;
		}
		if($item instanceof Fertilizer){
			$world->addParticle($this->position->add(0.5, 0.5, 0.5), new HappyVillagerParticle());
		}else{
			$world->addSound($this->position, new ItemUseOnBlockSound($this));
		}
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->getSide(Facing::DOWN)->isSolid()){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()->setCount($this->growth() + 1)];
	}
}

/** Poplar logs and wood: placed along the clicked axis, an axe strips them. */
class VanillaPillarLog extends GenericBedrockBlock{

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$axis = match(Facing::axis($face)){
			Axis::X => "x",
			Axis::Z => "z",
			default => "y",
		};
		try{
			$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["pillar_axis" => $axis]));
			return true;
		}catch(\InvalidArgumentException){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$name = $this->getBedrockName();
		if(!$item instanceof Axe || str_contains($name, "stripped_")){
			return false;
		}
		$stripped = ExtraVanillaBlocks::get(str_replace("minecraft:", "minecraft:stripped_", $name));
		if($stripped === null){
			return false;
		}
		$axis = (string) ($this->getStateData()->getStates()["pillar_axis"]?->getValue() ?? "y");
		try{
			$stripped = $stripped->withProperties(["pillar_axis" => $axis]);
		}catch(\InvalidArgumentException){
		}
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $stripped);
		$world->addSound($this->position, new ItemUseOnBlockSound($stripped));
		$item->applyDamage(1);
		return true;
	}
}

/**
 * Poplar leaves: decay when no log is close (like vanilla leaves; placed by players they stay), drop
 * saplings (5 %) and sticks (2 %).
 */
class VanillaDecayingLeaves extends GenericBedrockBlock{

	public function isTransparent() : bool{ return true; }

	public function ticksRandomly() : bool{
		return !$this->persistent();
	}

	private function persistent() : bool{
		$tag = $this->getStateData()->getStates()["persistent_bit"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		try{
			$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["persistent_bit" => 1]));
			return true;
		}catch(\InvalidArgumentException){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}
	}

	public function onRandomTick() : void{
		if($this->persistent() || $this->nearLog()){
			return;
		}
		$this->position->getWorld()->useBreakOn($this->position);
	}

	/** Vanilla: leaves stay up to 6 blocks (through other leaves) from a log; this checks a 4 block radius. */
	private function nearLog() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($dx = -4; $dx <= 4; ++$dx){
			for($dy = -4; $dy <= 4; ++$dy){
				for($dz = -4; $dz <= 4; ++$dz){
					if(abs($dx) + abs($dy) + abs($dz) > 6 || !$world->isInWorld($x + $dx, $y + $dy, $z + $dz)){
						continue;
					}
					$block = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
					if($block instanceof Wood || $block instanceof VanillaPillarLog){
						return true;
					}
				}
			}
		}
		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drops = [];
		if(mt_rand(1, 20) === 1 && ($sapling = ExtraVanillaBlocks::get("minecraft:poplar_sapling")) !== null){
			$drops[] = $sapling->asItem();
		}
		if(mt_rand(1, 50) === 1){
			$drops[] = VanillaItems::STICK()->setCount(mt_rand(1, 2));
		}
		return $drops;
	}

	public function isAffectedBySilkTouch() : bool{ return true; }
}

/**
 * Moss block and pale moss block: bone meal turns stone, dirt and similar blocks around into moss and lets
 * moss carpet, grass and azaleas grow on it, like vanilla (radius 3).
 */
class VanillaMossBlock extends GenericBedrockBlock{

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer || !$this->getSide(Facing::UP)->canBeReplaced()){
			return false;
		}
		$world = $this->position->getWorld();
		$pale = $this->getBedrockName() === "minecraft:pale_moss_block";
		$carpet = ExtraVanillaBlocks::get($pale ? "minecraft:pale_moss_carpet" : "minecraft:moss_carpet");
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($dx = -3; $dx <= 3; ++$dx){
			for($dz = -3; $dz <= 3; ++$dz){
				//the farther away, the less likely, like the vanilla patch
				if((abs($dx) === 3 || abs($dz) === 3) && mt_rand(0, 3) !== 0){
					continue;
				}
				for($dy = 1; $dy >= -2; --$dy){
					if(!$world->isInWorld($x + $dx, $y + $dy, $z + $dz) || !$world->isInWorld($x + $dx, $y + $dy + 1, $z + $dz)){
						continue;
					}
					$ground = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
					$above = $world->getBlockAt($x + $dx, $y + $dy + 1, $z + $dz);
					if(!$above->canBeReplaced() || $above instanceof Liquid){
						continue;
					}
					if($ground->hasSameTypeId($this) || $this->mossReplaceable($ground)){
						if(!$ground->hasSameTypeId($this)){
							$world->setBlockAt($x + $dx, $y + $dy, $z + $dz, clone $this);
						}
						$roll = mt_rand(0, 99);
						$plant = match(true){
							$roll < 25 && $carpet !== null => $carpet,
							$roll < 30 && !$pale => VanillaBlocks::AZALEA(),
							$roll < 32 && !$pale => VanillaBlocks::FLOWERING_AZALEA(),
							$roll < 50 && !$pale => VanillaBlocks::TALL_GRASS(),
							default => null,
						};
						if($plant !== null && $above->getTypeId() === BlockTypeIds::AIR){
							$world->setBlockAt($x + $dx, $y + $dy + 1, $z + $dz, $plant);
						}
						break;
					}
				}
			}
		}
		$world->addParticle($this->position->add(0.5, 1.5, 0.5), new HappyVillagerParticle());
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		return true;
	}

	private function mossReplaceable(Block $block) : bool{
		return $block instanceof Dirt || $block instanceof Grass || $block instanceof Podzol || $block instanceof Mycelium
			|| $block->getTypeId() === BlockTypeIds::STONE || $block->getTypeId() === BlockTypeIds::GRANITE || $block->getTypeId() === BlockTypeIds::DIORITE
			|| $block->getTypeId() === BlockTypeIds::ANDESITE || $block->getTypeId() === BlockTypeIds::DEEPSLATE || $block->getTypeId() === BlockTypeIds::TUFF
			|| $block->getTypeId() === BlockTypeIds::CAVE_VINES;
	}
}
