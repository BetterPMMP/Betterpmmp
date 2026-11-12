<?php

/*
 * BetterPMMP - behaviour for Bedrock building blocks the core has no class for
 * (new wood sets like poplar, concrete/wool slabs and stairs, ...): doors,
 * trapdoors, buttons, fence gates, pressure plates, slabs, stairs and fences.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\DirectionalRedstoneSource;
use pocketmine\block\utils\RedstonePower;
use pocketmine\block\utils\SupportType;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\DoorSound;
use pocketmine\world\sound\RedstonePowerOffSound;
use pocketmine\world\sound\RedstonePowerOnSound;
use function is_int;
use function is_string;
use function str_contains;
use function str_replace;

/** Helpers to read and write the common Bedrock block properties. */
trait BedrockShapeStates{

	protected function prop(string $name) : int|string|null{
		$tag = $this->getStateData()->getStates()[$name] ?? null;
		if($tag === null){
			return null;
		}
		$value = $tag->getValue();
		return is_int($value) || is_string($value) ? $value : null;
	}

	protected function flag(string $name) : bool{
		return (int) ($this->prop($name) ?? 0) === 1;
	}

	protected static function cardinalName(int $facing) : string{
		return match($facing){
			Facing::NORTH => "north",
			Facing::EAST => "east",
			Facing::WEST => "west",
			default => "south",
		};
	}

	protected static function cardinalFacing(?string $name) : int{
		return match($name){
			"north" => Facing::NORTH,
			"east" => Facing::EAST,
			"west" => Facing::WEST,
			default => Facing::SOUTH,
		};
	}

	protected static function horizontalFacingOf(?Player $player) : int{
		return $player !== null ? $player->getHorizontalFacing() : Facing::NORTH;
	}
}

/** Two-block doors: open/close by hand (except iron-like ones) or redstone. */
class VanillaDoorBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function isTop() : bool{ return $this->flag("upper_block_bit"); }
	private function isOpen() : bool{ return $this->flag("open_bit"); }
	private function facing() : int{ return self::cardinalFacing((string) $this->prop("minecraft:cardinal_direction")); }

	protected function recalculateCollisionBoxes() : array{
		// the lower half carries the real state (facing, hinge, open)
		$state = $this;
		if($this->isTop() && $this->position->isValid() && ($below = $this->getSide(Facing::DOWN)) instanceof self){
			$state = $below;
		}
		$facing = $state->facing();
		$open = $this->isOpen();
		$hingeRight = $state->flag("door_hinge_bit");
		return [AxisAlignedBB::one()->trim($open ? Facing::rotateY($facing, !$hingeRight) : $facing, 327 / 400)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$above = $blockReplace->getSide(Facing::UP);
		$below = $blockReplace->getSide(Facing::DOWN);
		if(!$above->canBeReplaced() || $below->getSupportType(Facing::UP) !== SupportType::FULL){
			return false;
		}
		$facing = self::horizontalFacingOf($player);
		// hinge on the side where a door of the same type stands, like vanilla
		$left = $blockReplace->getSide(Facing::rotateY($facing, false));
		$hinge = $left instanceof self && $left->getBedrockName() === $this->getBedrockName();
		$lower = $this->withProperties(["minecraft:cardinal_direction" => self::cardinalName($facing), "upper_block_bit" => false, "open_bit" => false, "door_hinge_bit" => $hinge]);
		$upper = $lower->withProperties(["upper_block_bit" => true]);
		$tx->addBlock($blockReplace->getPosition(), $lower);
		$tx->addBlock($above->getPosition(), $upper);
		return true;
	}

	private function other() : ?self{
		$other = $this->getSide($this->isTop() ? Facing::DOWN : Facing::UP);
		return $other instanceof self && $other->getBedrockName() === $this->getBedrockName() ? $other : null;
	}

	private function setOpen(bool $open) : void{
		$world = $this->position->getWorld();
		$other = $this->other();
		$world->setBlock($this->position, $this->withProperties(["open_bit" => $open]));
		if($other !== null){
			$world->setBlock($other->getPosition(), $other->withProperties(["open_bit" => $open]));
		}
		$world->addSound($this->position, new DoorSound());
	}

	private function redstoneOnly() : bool{
		$name = $this->getBedrockName();
		return str_contains($name, "iron");
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->redstoneOnly()){
			return false;
		}
		$this->setOpen(!$this->isOpen());
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if($this->other() === null || (!$this->isTop() && $this->getSide(Facing::DOWN)->getSupportType(Facing::UP) !== SupportType::FULL)){
			$world->useBreakOn($this->position);
			return;
		}
		$other = $this->other();
		$powered = RedstonePower::isPowered($this) || RedstonePower::isPowered($other);
		$bottom = $this->isTop() ? $other : $this;
		if(RedstonePower::consumerEdge($bottom->getPosition(), $world, $powered) && $this->isOpen() !== $powered){
			$this->setOpen($powered);
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->isTop() ? [] : [$this->asItem()];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}

/** Trapdoors: open/close by hand or redstone, top or bottom half. */
class VanillaTrapdoorBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function facing() : int{
		// Bedrock "direction": 0 east, 1 west, 2 south, 3 north (5 - facing)
		return 5 - (int) ($this->prop("direction") ?? 0);
	}

	protected function recalculateCollisionBoxes() : array{
		$open = $this->flag("open_bit");
		$top = $this->flag("upside_down_bit");
		return [AxisAlignedBB::one()->trim($open ? $this->facing() : ($top ? Facing::DOWN : Facing::UP), 13 / 16)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = $player !== null ? Facing::opposite($player->getHorizontalFacing()) : Facing::NORTH;
		$top = ($face !== Facing::UP && $clickVector->y > 0.5) || $face === Facing::DOWN;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["direction" => 5 - $facing, "upside_down_bit" => $top, "open_bit" => false]));
		return true;
	}

	private function setOpen(bool $open) : void{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this->withProperties(["open_bit" => $open]));
		$world->addSound($this->position, new DoorSound());
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(str_contains($this->getBedrockName(), "iron")){
			return false;
		}
		$this->setOpen(!$this->flag("open_bit"));
		return true;
	}

	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		if(RedstonePower::consumerEdge($this->position, $this->position->getWorld(), $powered) && $this->flag("open_bit") !== $powered){
			$this->setOpen($powered);
		}
	}
}

/** Fence gates: open away from the player, redstone, 1.5 blocks high when closed. */
class VanillaFenceGateBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function facing() : int{ return self::cardinalFacing((string) $this->prop("minecraft:cardinal_direction")); }

	protected function recalculateCollisionBoxes() : array{
		return $this->flag("open_bit") ? [] : [AxisAlignedBB::one()->extend(Facing::UP, 0.5)->squash(Facing::axis($this->facing()), 6 / 16)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => self::cardinalName(self::horizontalFacingOf($player)), "open_bit" => false]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$open = !$this->flag("open_bit");
		$properties = ["open_bit" => $open];
		if($open && $player !== null){
			$playerFacing = $player->getHorizontalFacing();
			if($playerFacing === Facing::opposite($this->facing())){
				$properties["minecraft:cardinal_direction"] = self::cardinalName($playerFacing);
			}
		}
		$this->position->getWorld()->setBlock($this->position, $this->withProperties($properties));
		$this->position->getWorld()->addSound($this->position, new DoorSound());
		return true;
	}

	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		if(RedstonePower::consumerEdge($this->position, $this->position->getWorld(), $powered) && $this->flag("open_bit") !== $powered){
			$this->position->getWorld()->setBlock($this->position, $this->withProperties(["open_bit" => $powered]));
			$this->position->getWorld()->addSound($this->position, new DoorSound());
		}
	}
}

/** Buttons: pressed for 1.5 s (wood) or 1 s (stone), powering the block they sit on. */
class VanillaButtonBlock extends GenericBedrockBlock implements DirectionalRedstoneSource{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }
	protected function recalculateCollisionBoxes() : array{ return []; }

	private function pressed() : bool{ return $this->flag("button_pressed_bit"); }
	private function facing() : int{ return (int) ($this->prop("facing_direction") ?? Facing::UP); }

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$blockClicked->getSupportType($face)->hasCenterSupport()){
			return false;
		}
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["facing_direction" => $face, "button_pressed_bit" => false]));
		return true;
	}

	private function activationTime() : int{
		$name = $this->getBedrockName();
		return str_contains($name, "stone") || str_contains($name, "blackstone") ? 20 : 30;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$this->pressed()){
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this->withProperties(["button_pressed_bit" => true]));
			$world->scheduleDelayedBlockUpdate($this->position, $this->activationTime());
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new RedstonePowerOnSound());
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->pressed()){
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this->withProperties(["button_pressed_bit" => false]));
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new RedstonePowerOffSound());
		}
	}

	public function onNearbyBlockChange() : void{
		if(!$this->getSide(Facing::opposite($this->facing()))->getSupportType($this->facing())->hasCenterSupport()){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->pressed() ? 15 : 0;
	}

	public function getStrongPowerTowards(int $towards) : int{
		return $this->pressed() && $towards === Facing::opposite($this->facing()) ? 15 : 0;
	}
}

/** Pressure plates: wooden ones react to any entity, stone ones to living entities. */
class VanillaPressurePlateBlock extends GenericBedrockBlock implements DirectionalRedstoneSource{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }
	protected function recalculateCollisionBoxes() : array{ return []; }

	private function signal() : int{ return (int) ($this->prop("redstone_signal") ?? 0); }

	public function hasEntityCollision() : bool{ return true; }

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$blockReplace->getSide(Facing::DOWN)->getSupportType(Facing::UP)->hasCenterSupport()){
			return false;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function detects(Entity $entity) : bool{
		return str_contains($this->getBedrockName(), "stone") ? $entity instanceof Living : true;
	}

	private function entitiesOnTop() : bool{
		$bb = new AxisAlignedBB(0.0625, 0, 0.0625, 0.9375, 0.25, 0.9375);
		foreach($this->position->getWorld()->getNearbyEntities($bb->offset($this->position->x, $this->position->y, $this->position->z)) as $entity){
			if($this->detects($entity)){
				return true;
			}
		}
		return false;
	}

	public function onEntityInside(Entity $entity) : bool{
		if($this->signal() === 0 && $this->detects($entity)){
			$this->setSignal(15);
			return false;
		}
		return true;
	}

	private function setSignal(int $signal) : void{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this->withProperties(["redstone_signal" => $signal]));
		$world->addSound($this->position->add(0.5, 0.1, 0.5), $signal > 0 ? new RedstonePowerOnSound() : new RedstonePowerOffSound());
		if($signal > 0){
			$world->scheduleDelayedBlockUpdate($this->position, 20);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->signal() > 0){
			if($this->entitiesOnTop()){
				$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 20);
			}else{
				$this->setSignal(0);
			}
		}
	}

	public function onNearbyBlockChange() : void{
		if(!$this->getSide(Facing::DOWN)->getSupportType(Facing::UP)->hasCenterSupport()){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->signal() > 0 ? 15 : 0;
	}

	public function getStrongPowerTowards(int $towards) : int{
		return $this->signal() > 0 && $towards === Facing::DOWN ? 15 : 0;
	}
}

/** Slabs: bottom or top half, two of the same kind become the double slab. */
class VanillaSlabBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function isTop() : bool{ return $this->prop("minecraft:vertical_half") === "top"; }

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim($this->isTop() ? Facing::DOWN : Facing::UP, 0.5)];
	}

	public function getSupportType(int $facing) : SupportType{
		return ($this->isTop() && $facing === Facing::UP) || (!$this->isTop() && $facing === Facing::DOWN) ? SupportType::FULL : SupportType::NONE;
	}

	private function doubleSlab() : ?GenericBedrockBlock{
		return ExtraVanillaBlocks::get(str_replace("_slab", "_double_slab", $this->getBedrockName()));
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		if($blockReplace instanceof self && $blockReplace->getBedrockName() === $this->getBedrockName()){
			// clicking the open half of a slab completes it
			return ($blockReplace->isTop() && ($isClickedBlock ? $face === Facing::DOWN : true)) || (!$blockReplace->isTop() && ($isClickedBlock ? $face === Facing::UP : true));
		}
		return parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($blockReplace instanceof self && $blockReplace->getBedrockName() === $this->getBedrockName() && ($double = $this->doubleSlab()) !== null){
			$tx->addBlock($blockReplace->getPosition(), $double);
			return true;
		}
		$top = ($face !== Facing::UP && $clickVector->y > 0.5) || $face === Facing::DOWN;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:vertical_half" => $top ? "top" : "bottom"]));
		return true;
	}
}

/** The block of two slabs: drops two slabs. */
class VanillaDoubleSlabBlock extends GenericBedrockBlock{
	public function getDropsForCompatibleTool(Item $item) : array{
		$slab = ExtraVanillaBlocks::get(str_replace("_double_slab", "_slab", $this->getBedrockName()));
		return $slab === null ? [] : [$slab->asItem()->setCount(2)];
	}
}

/** Stairs: facing the way the player looks, upside down when placed on the upper half. */
class VanillaStairsBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function facing() : int{
		return match((int) ($this->prop("weirdo_direction") ?? 0)){
			0 => Facing::EAST,
			1 => Facing::WEST,
			2 => Facing::SOUTH,
			default => Facing::NORTH,
		};
	}

	protected function recalculateCollisionBoxes() : array{
		$upsideDown = $this->flag("upside_down_bit");
		$half = AxisAlignedBB::one()->trim($upsideDown ? Facing::DOWN : Facing::UP, 0.5);
		$step = AxisAlignedBB::one()->trim($upsideDown ? Facing::UP : Facing::DOWN, 0.5)->trim(Facing::opposite($this->facing()), 0.5);
		return [$half, $step];
	}

	public function getSupportType(int $facing) : SupportType{
		$upsideDown = $this->flag("upside_down_bit");
		if($facing === Facing::UP && $upsideDown || $facing === Facing::DOWN && !$upsideDown || $facing === $this->facing()){
			return SupportType::FULL;
		}
		return SupportType::NONE;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = self::horizontalFacingOf($player);
		$direction = match($facing){
			Facing::EAST => 0,
			Facing::WEST => 1,
			Facing::SOUTH => 2,
			default => 3,
		};
		$upsideDown = ($face !== Facing::UP && $clickVector->y > 0.5) || $face === Facing::DOWN;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["weirdo_direction" => $direction, "upside_down_bit" => $upsideDown]));
		return true;
	}
}

/** Fences: connect to fences, fence gates and solid blocks next to them. */
class VanillaFenceBlock extends GenericBedrockBlock{
	use BedrockShapeStates;

	private const SIDES = [Facing::NORTH => "minecraft:connection_north", Facing::EAST => "minecraft:connection_east", Facing::SOUTH => "minecraft:connection_south", Facing::WEST => "minecraft:connection_west"];

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{
		$boxes = [new AxisAlignedBB(0.375, 0, 0.375, 0.625, 1.5, 0.625)];
		foreach(self::SIDES as $side => $property){
			if($this->flag($property)){
				$boxes[] = (new AxisAlignedBB(0.375, 0, 0.375, 0.625, 1.5, 0.625))->extend($side, 0.375);
			}
		}
		return $boxes;
	}

	private function connectsTo(Block $block, int $side) : bool{
		if($block instanceof Fence || $block instanceof FenceGate || $block instanceof self || $block instanceof VanillaFenceGateBlock){
			return true;
		}
		return $block->getSupportType(Facing::opposite($side)) === SupportType::FULL;
	}

	/** @return array<string, bool> */
	private function connections(Block $at) : array{
		$result = [];
		foreach(self::SIDES as $side => $property){
			$result[$property] = $this->connectsTo($at->getSide($side), $side);
		}
		return $result;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties($this->connections($blockReplace)));
		return true;
	}

	public function onNearbyBlockChange() : void{
		$connections = $this->connections($this);
		$changed = false;
		foreach($connections as $property => $connected){
			if($this->flag($property) !== $connected){
				$changed = true;
			}
		}
		if($changed){
			$this->position->getWorld()->setBlock($this->position, $this->withProperties($connections));
		}
	}
}
