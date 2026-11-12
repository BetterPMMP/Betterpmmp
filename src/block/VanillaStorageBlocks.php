<?php

/*
 * BetterPMMP - copper chests and decorated pots.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\VanillaSound;

/** Copper chests: a normal 27-slot chest (core chest block entity) that faces the player. */
class VanillaCopperChest extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(0.025, 0, 0.025)->trim(Facing::UP, 0.05)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = $player !== null ? Facing::opposite($player->getHorizontalFacing()) : Facing::SOUTH;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => self::cardinalName($facing)]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !$this->getSide(Facing::UP)->isTransparent()){
			return true;
		}
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileChest && $tile->canOpenWith($item->getCustomName())){
			$player->setCurrentWindow($tile->getInventory());
		}
		return true;
	}
}

/** Decorated pots: right-click puts one item in (up to a stack of one type); breaking drops them. */
class VanillaDecoratedPot extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 1 / 16)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		// Bedrock "direction": 0 south, 1 west, 2 north, 3 east
		$facing = $player !== null ? Facing::opposite($player->getHorizontalFacing()) : Facing::SOUTH;
		$direction = match($facing){
			Facing::WEST => 1,
			Facing::NORTH => 2,
			Facing::EAST => 3,
			default => 0,
		};
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["direction" => $direction]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileDecoratedPot || $item->isNull()){
			return false;
		}
		$stored = $tile->getItem();
		if(!$stored->isNull() && (!$stored->canStackWith($item) || $stored->getCount() >= $stored->getMaxStackSize())){
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_DECORATED_POT_INSERT_FAIL));
			return true;
		}
		$one = (clone $item)->setCount(1);
		$tile->setItem($stored->isNull() ? $one : $stored->setCount($stored->getCount() + 1));
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_DECORATED_POT_INSERT));
		return true;
	}
}
