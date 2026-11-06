<?php

/*
 * BetterPMMP - generic block for Bedrock block types without a dedicated class.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use function count;

/**
 * A Bedrock block type for which the core has no dedicated implementation.
 * It keeps the exact Bedrock block state (as an index into the type's list of
 * states from the official palette), so the block can be placed, saved,
 * loaded, generated and sent to clients with every property intact.
 * Solidity, transparency, light and hardness come from the Bedrock data.
 */
class GenericBedrockBlock extends Block{

	private int $stateIndex;

	/**
	 * @param BlockStateData[] $states all Bedrock states of this block type
	 */
	public function __construct(
		BlockIdentifier $idInfo,
		string $name,
		BlockTypeInfo $typeInfo,
		private readonly string $bedrockName,
		private readonly array $states,
		int $defaultIndex,
		private readonly bool $solid,
		private readonly bool $transparent,
		private readonly int $light,
		private readonly bool $hasItem
	){
		$this->stateIndex = $defaultIndex;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getBedrockName() : string{ return $this->bedrockName; }

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		if(count($this->states) > 1){
			$w->boundedIntAuto(0, count($this->states) - 1, $this->stateIndex);
		}
	}

	public function getStateIndex() : int{ return $this->stateIndex; }

	/** @return $this */
	public function setStateIndex(int $index) : self{
		if($index < 0 || $index >= count($this->states)){
			throw new \InvalidArgumentException("State index $index out of range for $this->bedrockName");
		}
		$this->stateIndex = $index;
		return $this;
	}

	public function getStateData() : BlockStateData{
		return $this->states[$this->stateIndex];
	}

	/**
	 * Returns a copy using the state that matches the given Bedrock properties
	 * (other properties keep their current value), e.g. ["hanging" => true].
	 * @param array<string, bool|int|string> $properties
	 */
	public function withProperties(array $properties) : self{
		$current = $this->getStateData()->getStates();
		$wanted = [];
		foreach($current as $name => $tag){
			$wanted[$name] = self::tagValue($tag);
		}
		foreach($properties as $name => $value){
			$wanted[$name] = $value;
		}
		foreach($this->states as $index => $state){
			$match = true;
			foreach($state->getStates() as $name => $tag){
				$value = $wanted[$name] ?? null;
				$tagValue = self::tagValue($tag);
				if(is_bool($value)){
					$value = $value ? 1 : 0;
				}
				if($value !== $tagValue){
					$match = false;
					break;
				}
			}
			if($match){
				return (clone $this)->setStateIndex($index);
			}
		}
		throw new \InvalidArgumentException("No state of $this->bedrockName matches " . json_encode($properties));
	}

	private static function tagValue(Tag $tag) : int|string|null{
		return match(true){
			$tag instanceof StringTag => $tag->getValue(),
			$tag instanceof IntTag, $tag instanceof ByteTag => $tag->getValue(),
			default => null,
		};
	}

	/** Index of a Bedrock state of this type, or null if it is not part of it. */
	public function findStateIndex(BlockStateData $data) : ?int{
		foreach($this->states as $index => $state){
			if($state->getName() === $data->getName() && self::sameStates($state, $data)){
				return $index;
			}
		}
		return null;
	}

	private static function sameStates(BlockStateData $a, BlockStateData $b) : bool{
		$sa = $a->getStates();
		$sb = $b->getStates();
		if(count($sa) !== count($sb)){
			return false;
		}
		foreach($sa as $name => $tag){
			if(!isset($sb[$name]) || !$tag->equals($sb[$name])){
				return false;
			}
		}
		return true;
	}

	public function isSolid() : bool{
		return $this->solid;
	}

	public function isTransparent() : bool{
		return $this->transparent;
	}

	public function getLightLevel() : int{
		return $this->light;
	}

	public function canBeReplaced() : bool{
		return !$this->solid && !$this->hasItem;
	}

	protected function recalculateCollisionBoxes() : array{
		return $this->solid ? [AxisAlignedBB::one()] : [];
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->hasItem ? [$this->asItem()] : [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function hasItemForm() : bool{
		return $this->hasItem;
	}
}
