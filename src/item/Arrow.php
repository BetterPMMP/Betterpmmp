<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;
use function intdiv;
use function max;

/**
 * BetterPMMP: arrows and tipped arrows. Bedrock stores tipped arrows as arrows with the potion id + 1
 * as meta; their effects last an eighth of the potion's.
 */
class Arrow extends Item{

	/** 0 = plain arrow, otherwise the Bedrock potion id + 1 */
	private int $potionId = 0;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, 63, $this->potionId);
	}

	/** Bedrock item meta: 0 for a plain arrow, potion id + 1 for a tipped one. */
	public function getArrowMeta() : int{ return $this->potionId; }

	/** @return $this */
	public function setArrowMeta(int $meta) : self{
		$this->potionId = max(0, $meta);
		return $this;
	}

	public function isTipped() : bool{ return $this->potionId !== 0; }

	public function getPotionType() : ?PotionType{
		return $this->potionId === 0 ? null : PotionTypeIdMap::getInstance()->fromId($this->potionId - 1);
	}

	/** @return $this */
	public function setPotionType(?PotionType $type) : self{
		$this->potionId = $type === null ? 0 : PotionTypeIdMap::getInstance()->toId($type) + 1;
		return $this;
	}

	/**
	 * Effects given to the mob the arrow hits (vanilla: 1/8 of the potion's duration).
	 * @return EffectInstance[]
	 */
	public function getHitEffects() : array{
		$effects = [];
		foreach($this->getPotionType()?->getEffects() ?? [] as $effect){
			$copy = clone $effect;
			if(!$effect->getType() instanceof \pocketmine\entity\effect\InstantEffect){
				$copy->setDuration(max(1, intdiv($effect->getDuration(), 8)));
			}
			$effects[] = $copy;
		}
		return $effects;
	}

	/**
	 * The arrow a bow or crossbow uses: off hand first, then the inventory in slot order (vanilla).
	 * @return array{Inventory, int}|null
	 */
	public static function findAmmo(Player $player) : ?array{
		$offHand = $player->getOffHandInventory();
		if($offHand->getItem(0) instanceof self){
			return [$offHand, 0];
		}
		$inventory = $player->getInventory();
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			if($inventory->getItem($slot) instanceof self){
				return [$inventory, $slot];
			}
		}
		return null;
	}
}
