<?php

/*
 * BetterPMMP - grindstone use.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;

class GrindstoneTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly GrindstoneInventory $grindstone,
		private readonly Item $expectedResult,
		private readonly int $experience
	){
		parent::__construct($source);
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		if(count($outputs) !== 1 || !$outputs[0]->equalsExact($this->expectedResult)){
			throw new TransactionValidationException("Unexpected grindstone result");
		}
		$consumed = 0;
		foreach($inputs as $item){
			$consumed += $item->getCount();
		}
		$expected = 0;
		foreach([0, 1] as $slot){
			$expected += $this->grindstone->getItem($slot)->isNull() ? 0 : 1;
		}
		if($consumed !== $expected){
			throw new TransactionValidationException("The grindstone inputs must be consumed");
		}
	}

	public function execute() : void{
		parent::execute();
		if($this->experience > 0){
			$pos = $this->grindstone->getHolder();
			$pos->getWorld()->dropExperience($pos->add(0.5, 0.5, 0.5), $this->experience);
		}
	}
}
