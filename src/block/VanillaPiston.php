<?php

/*
 * BetterPMMP - vanilla piston and sticky piston.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\PistonArm as PistonArmTile;
use pocketmine\block\utils\RedstonePower;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\PistonSound;
use pocketmine\world\World;
use function array_filter;
use function array_shift;
use function array_values;
use function count;
use function usort;

/**
 * Bedrock pistons: no quasi-connectivity, push limit 12, slime and honey blocks
 * drag their neighbours, sticky pistons pull one block (or a slime structure).
 * Blocks with block entities (chests, furnaces, ...) are immovable except
 * retracted pistons. Movement completes after 2 game ticks.
 */
class VanillaPiston extends GenericBedrockBlock{

	public const PUSH_LIMIT = 12;
	private const MOVE_TICKS = 2;

	/** Bedrock stores horizontal piston facings mirrored compared to other blocks. */
	public static function facingFromBedrock(int $value) : int{
		return match($value){
			2 => Facing::SOUTH, 3 => Facing::NORTH, 4 => Facing::EAST, 5 => Facing::WEST,
			1 => Facing::UP, default => Facing::DOWN,
		};
	}

	public static function facingToBedrock(int $facing) : int{
		return match($facing){
			Facing::SOUTH => 2, Facing::NORTH => 3, Facing::EAST => 4, Facing::WEST => 5,
			Facing::UP => 1, default => 0,
		};
	}

	/** The direction a player is looking in, snapped to one of the 6 faces. */
	public static function lookingDirection(Player $player) : int{
		$pitch = $player->getLocation()->getPitch();
		if($pitch > 45){
			return Facing::DOWN;
		}
		if($pitch < -45){
			return Facing::UP;
		}
		return $player->getHorizontalFacing();
	}

	public function isSticky() : bool{
		return $this->getBedrockName() === "minecraft:sticky_piston";
	}

	public function getFacing() : int{
		return self::facingFromBedrock((int) $this->getStateData()->getStates()["facing_direction"]->getValue());
	}

	/** @return $this */
	public function setFacing(int $facing) : self{
		return $this->setStateIndex($this->withProperties(["facing_direction" => self::facingToBedrock($facing)])->getStateIndex());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		// the head points at the player
		$this->setFacing($player !== null ? Facing::opposite(self::lookingDirection($player)) : $face);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function tile() : ?PistonArmTile{
		if(!$this->position->isValid()){
			return null;
		}
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof PistonArmTile ? $tile : null;
	}

	public function isExtended() : bool{
		return $this->tile()?->isExtended() ?? false;
	}

	public function isSolid() : bool{
		return !$this->isExtended();
	}

	public function onPostPlace() : void{
		$this->tile()?->setSticky($this->isSticky());
		$this->onNearbyBlockChange();
	}

	private function isPoweredFromBack() : bool{
		$front = $this->getFacing();
		foreach(Facing::ALL as $side){
			if($side !== $front && RedstonePower::fromSide($this, $side) > 0){
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$tile = $this->tile();
		if($tile === null || $tile->isMoving()){
			return;
		}
		$powered = $this->isPoweredFromBack();
		if($powered && !$tile->isExtended()){
			$this->extend($tile);
		}elseif(!$powered && $tile->isExtended()){
			$this->retract($tile);
		}
	}

	public function onScheduledUpdate() : void{
		$tile = $this->tile();
		if($tile === null || !$tile->isMoving()){
			return;
		}
		$tile->setState($tile->getState() === PistonArmTile::STATE_EXTENDING ? PistonArmTile::STATE_EXTENDED : PistonArmTile::STATE_RETRACTED);
		$this->resend();
		// power may have changed while the piston was moving
		$this->onNearbyBlockChange();
	}

	private function resend() : void{
		$this->position->getWorld()->setBlock($this->position, $this, false);
	}

	private function extend(PistonArmTile $tile) : bool{
		$facing = $this->getFacing();
		$world = $this->position->getWorld();
		$head = $this->position->getSide($facing);
		if(!$world->isInWorld($head->getFloorX(), $head->getFloorY(), $head->getFloorZ())){
			return false;
		}
		$plan = $this->resolve($head, $facing, true);
		if($plan === null){
			return false;
		}
		[$moved, $destroyed] = $plan;
		$tile->setSticky($this->isSticky());
		$tile->setState(PistonArmTile::STATE_EXTENDING);
		$this->move($moved, $destroyed, $facing);
		$arm = ExtraVanillaBlocks::get($this->isSticky() ? "minecraft:sticky_piston_arm_collision" : "minecraft:piston_arm_collision");
		if($arm instanceof VanillaPistonArm){
			$world->setBlock($head, $arm->setFacing($facing));
		}
		$this->resend();
		$world->addSound($this->position, new PistonSound(true));
		$world->scheduleDelayedBlockUpdate($this->position, self::MOVE_TICKS);
		return true;
	}

	private function retract(PistonArmTile $tile) : void{
		$facing = $this->getFacing();
		$world = $this->position->getWorld();
		$head = $this->position->getSide($facing);
		$tile->setState(PistonArmTile::STATE_RETRACTING);
		if($world->getBlock($head) instanceof VanillaPistonArm){
			$world->setBlock($head, VanillaBlocks::AIR());
		}
		if($this->isSticky()){
			$plan = $this->resolve($head->getSide($facing), Facing::opposite($facing), false);
			if($plan !== null){
				[$moved, $destroyed] = $plan;
				$this->move($moved, $destroyed, Facing::opposite($facing));
			}
		}
		$this->resend();
		$world->addSound($this->position, new PistonSound(false));
		$world->scheduleDelayedBlockUpdate($this->position, self::MOVE_TICKS);
	}

	/** Whether a block can be moved by a piston at all. */
	public static function isMovable(Block $block) : bool{
		if(!$block->getBreakInfo()->isBreakable()){
			return false;
		}
		$id = $block->getTypeId();
		if($id === BlockTypeIds::OBSIDIAN || $id === BlockTypeIds::CRYING_OBSIDIAN || $id === BlockTypeIds::GLOWING_OBSIDIAN ||
			$id === BlockTypeIds::END_PORTAL_FRAME || $id === BlockTypeIds::REINFORCED_DEEPSLATE ||
			$id === BlockTypeIds::NETHER_PORTAL || $id === BlockTypeIds::END_PORTAL){
			return false;
		}
		if($block instanceof VanillaPistonArm){
			return false;
		}
		if($block instanceof VanillaPiston){
			return !$block->isExtended();
		}
		if($block instanceof GenericBedrockBlock && !$block->hasItemForm()){
			return false;
		}
		return $block->getIdInfo()->getTileClass() === null;
	}

	/** Blocks that are destroyed (and drop) when pushed instead of moving. */
	public static function breaksWhenPushed(Block $block) : bool{
		return !$block->isSolid() && !($block instanceof VanillaPiston);
	}

	private static function isStickyBlock(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::SLIME || ($block instanceof GenericBedrockBlock && $block->getBedrockName() === "minecraft:honey_block");
	}

	private static function sticksTogether(Block $a, Block $b) : bool{
		// slime and honey do not stick to each other
		$aSlime = $a->getTypeId() === BlockTypeIds::SLIME;
		$bSlime = $b->getTypeId() === BlockTypeIds::SLIME;
		$bHoney = $b instanceof GenericBedrockBlock && $b->getBedrockName() === "minecraft:honey_block";
		$aHoney = $a instanceof GenericBedrockBlock && $a->getBedrockName() === "minecraft:honey_block";
		return !(($aSlime && $bHoney) || ($aHoney && $bSlime));
	}

	/**
	 * Works out which blocks move. Returns null when the move is blocked.
	 * @return array{list<Vector3>, list<Vector3>}|null
	 */
	private function resolve(Vector3 $start, int $direction, bool $pushing) : ?array{
		$world = $this->position->getWorld();
		$self = $this->position->asVector3();
		$headPos = $this->position->getSide($this->getFacing());
		$startBlock = $world->getBlock($start);
		if($startBlock->getTypeId() === BlockTypeIds::AIR || !self::isMovable($startBlock) || self::breaksWhenPushed($startBlock)){
			if($pushing){
				// empty, replaceable or breakable: the head simply goes there
				if($startBlock->getTypeId() === BlockTypeIds::AIR || $startBlock->canBeReplaced()){
					return [[], []];
				}
				return self::isMovable($startBlock) || self::breaksWhenPushed($startBlock) ? [[], [$start]] : null;
			}
			return [[], []];
		}

		/** @var Vector3[] $moved */
		$moved = [];
		$movedKeys = [];
		$destroyed = [];
		$destroyedKeys = [];
		$queue = [$start];
		while(count($queue) > 0){
			$pos = array_shift($queue);
			$key = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			if(isset($movedKeys[$key]) || $pos->equals($self) || (!$pushing && $pos->equals($headPos))){
				continue;
			}
			if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
				return null;
			}
			$block = $world->getBlock($pos);
			if($block->getTypeId() === BlockTypeIds::AIR || ($block->canBeReplaced() && !$block->isSolid())){
				continue;
			}
			if(self::breaksWhenPushed($block) && self::isMovable($block)){
				if(!isset($destroyedKeys[$key])){
					$destroyedKeys[$key] = true;
					$destroyed[] = $pos;
				}
				continue;
			}
			if(!self::isMovable($block)){
				return null;
			}
			$movedKeys[$key] = true;
			$moved[] = $pos;
			if(count($moved) > self::PUSH_LIMIT){
				return null;
			}
			// the block it moves into must move too
			$queue[] = $pos->getSide($direction);
			if(self::isStickyBlock($block)){
				foreach(Facing::ALL as $side){
					if($side === $direction){
						continue;
					}
					$next = $pos->getSide($side);
					$other = $world->getBlock($next);
					if($other->getTypeId() !== BlockTypeIds::AIR && self::isMovable($other) && !self::breaksWhenPushed($other) && self::sticksTogether($block, $other)){
						$queue[] = $next;
					}
				}
			}
		}
		// a breakable block that ends up where a moved block was is not destroyed
		$destroyed = array_values(array_filter($destroyed, fn(Vector3 $p) => !isset($movedKeys[World::blockHash($p->getFloorX(), $p->getFloorY(), $p->getFloorZ())])));
		return [$moved, $destroyed];
	}

	/**
	 * @param Vector3[] $moved
	 * @param Vector3[] $destroyed
	 */
	private function move(array $moved, array $destroyed, int $direction) : void{
		$world = $this->position->getWorld();
		foreach($destroyed as $pos){
			$block = $world->getBlock($pos);
			foreach($block->getDrops(VanillaItems::AIR()) as $drop){
				$world->dropItem($pos->add(0.5, 0.5, 0.5), $drop);
			}
			$world->setBlock($pos, VanillaBlocks::AIR());
		}
		[$dx, $dy, $dz] = Facing::OFFSET[$direction];
		usort($moved, fn(Vector3 $a, Vector3 $b) => ($b->x * $dx + $b->y * $dy + $b->z * $dz) <=> ($a->x * $dx + $a->y * $dy + $a->z * $dz));
		$blocks = [];
		foreach($moved as $i => $pos){
			$blocks[$i] = $world->getBlock($pos);
		}
		$targets = [];
		foreach($moved as $i => $pos){
			$world->setBlock($pos, VanillaBlocks::AIR(), false);
		}
		foreach($moved as $i => $pos){
			$target = $pos->getSide($direction);
			$targets[World::blockHash($target->getFloorX(), $target->getFloorY(), $target->getFloorZ())] = true;
			$world->setBlock($target, $blocks[$i]);
		}
		foreach($moved as $pos){
			if(!isset($targets[World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())])){
				$world->setBlock($pos, VanillaBlocks::AIR());
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$extended = $this->isExtended();
		$head = $this->position->getSide($this->getFacing());
		$result = parent::onBreak($item, $player, $returnedItems);
		if($extended && $this->position->getWorld()->getBlock($head) instanceof VanillaPistonArm){
			$this->position->getWorld()->setBlock($head, VanillaBlocks::AIR());
		}
		return $result;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()];
	}
}
