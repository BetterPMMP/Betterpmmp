<?php

/*
 * BetterPMMP - scaffolding.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function min;

/**
 * Vanilla scaffolding: a stack can reach 6 blocks sideways from a supported
 * column (stability 0-7); unsupported pieces drop as items. Using scaffolding
 * on scaffolding builds the column upwards (or sideways when sneaking is not
 * needed - the client sends the face). Breaking one breaks every piece that
 * loses support. Entities inside do not take fall damage.
 */
class VanillaScaffolding extends GenericBedrockBlock{

	public const MAX_DISTANCE = 7;

	public function getStability() : int{
		$tag = $this->getStateData()->getStates()["stability"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [new AxisAlignedBB(0, 0.875, 0, 1, 1, 1)];
	}

	public function canBeReplaced() : bool{
		return false;
	}

	/** Stability this position would have, or MAX_DISTANCE + 1 if unsupported. */
	private function computeStability(?Block $at = null) : int{
		$at ??= $this;
		$below = $at->getSide(Facing::DOWN);
		if($below instanceof self){
			return $below->getStability();
		}
		if($below->isSolid() || $below->getSupportType(Facing::UP)->hasEdgeSupport()){
			return 0;
		}
		$best = self::MAX_DISTANCE + 1;
		foreach(Facing::HORIZONTAL as $side){
			$block = $at->getSide($side);
			if($block instanceof self){
				$best = min($best, $block->getStability() + 1);
			}
		}
		return $best;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$stability = $this->computeStability($blockReplace);
		if($stability > self::MAX_DISTANCE){
			return false;
		}
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["stability" => $stability]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item->getBlock() instanceof self || $face !== Facing::UP){
			return false;
		}
		// clicking the top of a column places the next piece on top of it
		$world = $this->position->getWorld();
		$pos = $this->position;
		for($i = 0; $i < 64; ++$i){
			$pos = $pos->up();
			if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
				return false;
			}
			$block = $world->getBlock($pos);
			if($block instanceof self){
				continue;
			}
			if(!$block->canBeReplaced()){
				return false;
			}
			$new = $this->withProperties(["stability" => $this->getStability()]);
			$world->setBlock($pos, $new);
			if($player === null || $player->hasFiniteResources()){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$stability = $this->computeStability();
		$world = $this->position->getWorld();
		if($stability > self::MAX_DISTANCE){
			$world->useBreakOn($this->position);
			return;
		}
		if($stability !== $this->getStability()){
			$world->setBlock($this->position, $this->withProperties(["stability" => $stability]));
		}
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()];
	}

	public function getDropsForIncompatibleTool(Item $item) : array{
		return [$this->asItem()];
	}
}
