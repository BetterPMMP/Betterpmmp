<?php

/*
 * BetterPMMP - a tool or sword defined in custom_items.yml.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item\custom;

use pocketmine\block\Block;
use pocketmine\block\BlockToolType;
use pocketmine\entity\Entity;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;

class CustomTool extends TieredTool{

	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		ToolTier $tier,
		private int $toolType,
		private int $durability,
		private int $attackPoints
	){
		parent::__construct($identifier, $name, $tier);
	}

	public function getBlockToolType() : int{
		return $this->toolType;
	}

	public function getBlockToolHarvestLevel() : int{
		return $this->toolType === BlockToolType::SWORD ? 1 : $this->tier->getHarvestLevel();
	}

	public function getMaxDurability() : int{
		return $this->durability;
	}

	public function getAttackPoints() : int{
		return $this->attackPoints;
	}

	public function getMiningEfficiency(bool $isCorrectTool) : float{
		if($this->toolType === BlockToolType::SWORD){
			return parent::getMiningEfficiency($isCorrectTool) * 1.5;
		}
		return parent::getMiningEfficiency($isCorrectTool);
	}

	public function onDestroyBlock(Block $block, array &$returnedItems) : bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			//like vanilla: swords wear out twice as fast on blocks, tools twice as fast on mobs
			return $this->applyDamage($this->toolType === BlockToolType::SWORD ? 2 : 1);
		}
		return false;
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->applyDamage($this->toolType === BlockToolType::SWORD ? 1 : 2);
	}
}
