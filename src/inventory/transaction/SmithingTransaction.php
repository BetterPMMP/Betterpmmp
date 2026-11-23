<?php

/*
 * BetterPMMP - using the smithing table (netherite upgrade, armour trim).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\inventory\SmithingTableInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function count;

/** One result out, one of each of the three inputs used up. */
class SmithingTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly SmithingTableInventory $table,
		private readonly Item $expectedResult
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
			throw new TransactionValidationException("Unexpected smithing result");
		}
		$consumed = 0;
		foreach($inputs as $item){
			$consumed += $item->getCount();
		}
		if($consumed !== 3){
			throw new TransactionValidationException("The smithing table inputs must be used up (one of each)");
		}
	}

	public function execute() : void{
		parent::execute();
		$position = $this->table->getHolder();
		$position->getWorld()->addSound($position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::SMITHING_TABLE_USE));
	}
}
