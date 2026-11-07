<?php

/*
 * BetterPMMP - validates and performs a villager trade.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\entity\trade\TradeOffer;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;
use function mt_rand;

/**
 * A trade done in the villager trade window. The server checks that exactly
 * the offer's price was paid and exactly its result was received, so the
 * trade window cannot be used to create items.
 */
final class TradingTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private TradeInventory $window,
		private TradeOffer $offer,
		private int $times
	){
		parent::__construct($source);
	}

	public function getOffer() : TradeOffer{ return $this->offer; }
	public function getTimes() : int{ return $this->times; }

	/**
	 * @param Item[] $items
	 */
	private static function countMatching(array $items, Item $wanted) : int{
		$count = 0;
		foreach($items as $item){
			if($item->canStackWith($wanted)){
				$count += $item->getCount();
			}
		}
		return $count;
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		if(!$this->offer->canTrade($this->times)){
			throw new TransactionValidationException("Trade is out of stock");
		}
		if(!$this->window->getVillager()->isAlive() || $this->window->getVillager()->isClosed()){
			throw new TransactionValidationException("The villager is gone");
		}

		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		$sell = $this->offer->getSell();
		$received = self::countMatching($outputs, $sell);
		$totalOut = 0;
		foreach($outputs as $output){
			$totalOut += $output->getCount();
		}
		if($received !== $sell->getCount() * $this->times || $totalOut !== $received){
			throw new TransactionValidationException("Received items do not match the trade result");
		}

		$buyA = $this->offer->getBuyAFor($this->source);
		$buyB = $this->offer->getBuyB();
		$paidA = self::countMatching($inputs, $buyA);
		$paidB = $buyB !== null ? self::countMatching($inputs, $buyB) : 0;
		$totalIn = 0;
		foreach($inputs as $input){
			$totalIn += $input->getCount();
		}
		if($buyB !== null && $buyB->canStackWith($buyA)){
			//same item for both prices
			if($paidA !== ($buyA->getCount() + $buyB->getCount()) * $this->times || $totalIn !== $paidA){
				throw new TransactionValidationException("Paid items do not match the trade price");
			}
			return;
		}
		$expected = $buyA->getCount() * $this->times + ($buyB !== null ? $buyB->getCount() * $this->times : 0);
		if($paidA !== $buyA->getCount() * $this->times || ($buyB !== null && $paidB !== $buyB->getCount() * $this->times) || $totalIn !== $expected){
			throw new TransactionValidationException("Paid items do not match the trade price");
		}
	}

	public function execute() : void{
		parent::execute();
		$this->offer->use($this->times);
		$villager = $this->window->getVillager();
		$villager->onTrade($this->source, $this->offer, $this->times);
		//vanilla gives the player 3-6 experience per trade
		$xp = 0;
		for($i = 0; $i < $this->times; ++$i){
			$xp += mt_rand(3, 6);
		}
		$this->source->getXpManager()->addXp($xp);
	}
}
