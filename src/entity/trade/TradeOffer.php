<?php

/*
 * BetterPMMP - one villager trade offer.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\trade;

use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use function floor;
use function max;

/**
 * A trade: buyA (+ optional buyB) for sell, with vanilla usage limits and
 * experience. Serialised in the Bedrock "Recipes" format for the trade UI and
 * for saving with the villager.
 */
final class TradeOffer{

	public function __construct(
		private Item $buyA,
		private ?Item $buyB,
		private Item $sell,
		private int $maxUses,
		private int $traderExp,
		private int $tier,
		private int $uses = 0
	){}

	public function getBuyA() : Item{ return clone $this->buyA; }

	/**
	 * BetterPMMP: the price for this player - Hero of the Village lowers it by
	 * 30 % + 6.25 % per further level (vanilla), never below 1.
	 */
	public function getBuyAFor(?\pocketmine\player\Player $player) : Item{
		$buy = clone $this->buyA;
		$hero = $player?->getEffects()->get(\pocketmine\entity\effect\ExtraEffects::get("village_hero"));
		if($hero !== null){
			$count = $buy->getCount();
			$buy->setCount(max(1, $count - (int) floor($count * (0.3 + 0.0625 * $hero->getAmplifier()))));
		}
		return $buy;
	}
	public function getBuyB() : ?Item{ return $this->buyB !== null ? clone $this->buyB : null; }
	public function getSell() : Item{ return clone $this->sell; }
	public function getMaxUses() : int{ return $this->maxUses; }
	public function getUses() : int{ return $this->uses; }
	public function getTraderExp() : int{ return $this->traderExp; }
	public function getTier() : int{ return $this->tier; }

	public function isDisabled() : bool{
		return $this->uses >= $this->maxUses;
	}

	public function canTrade(int $times) : bool{
		return $times >= 1 && $this->uses + $times <= $this->maxUses;
	}

	public function use(int $times) : void{
		$this->uses = max(0, $this->uses + $times);
	}

	public function restock() : void{
		$this->uses = 0;
	}

	/** Bedrock trade recipe NBT (used by the trade window). */
	public function toNbt(int $netId, ?\pocketmine\player\Player $player = null) : CompoundTag{
		$buyA = $this->getBuyAFor($player);
		$nbt = CompoundTag::create()
			->setTag("buyA", $buyA->nbtSerialize())
			->setInt("buyCountA", $buyA->getCount())
			->setInt("buyCountB", $this->buyB?->getCount() ?? 0)
			->setTag("sell", $this->sell->nbtSerialize())
			->setInt("maxUses", $this->maxUses)
			->setInt("uses", $this->uses)
			->setByte("rewardExp", 1)
			->setInt("traderExp", $this->traderExp)
			->setInt("tier", $this->tier)
			->setInt("demand", 0)
			->setFloat("priceMultiplierA", 0.05)
			->setFloat("priceMultiplierB", 0.0)
			->setInt("netId", $netId);
		if($this->buyB !== null){
			$nbt->setTag("buyB", $this->buyB->nbtSerialize());
		}
		return $nbt;
	}

	public static function fromNbt(CompoundTag $nbt) : ?self{
		try{
			$buyA = Item::nbtDeserialize($nbt->getCompoundTag("buyA") ?? throw new \InvalidArgumentException());
			$buyBTag = $nbt->getCompoundTag("buyB");
			$sell = Item::nbtDeserialize($nbt->getCompoundTag("sell") ?? throw new \InvalidArgumentException());
		}catch(\Throwable){
			return null;
		}
		if($buyA->isNull() || $sell->isNull()){
			return null;
		}
		return new self(
			$buyA,
			$buyBTag !== null ? Item::nbtDeserialize($buyBTag) : null,
			$sell,
			$nbt->getInt("maxUses", 12),
			$nbt->getInt("traderExp", 1),
			$nbt->getInt("tier", 0),
			$nbt->getInt("uses", 0)
		);
	}
}
