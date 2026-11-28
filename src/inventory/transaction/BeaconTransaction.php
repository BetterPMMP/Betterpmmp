<?php

/*
 * BetterPMMP - paying a beacon and choosing its powers.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\Beacon;
use pocketmine\block\inventory\BeaconInventory;
use pocketmine\player\Player;

/**
 * One payment item (iron, gold, emerald, diamond or netherite) is used up; the beacon gets the chosen powers.
 * The server checks the powers against the pyramid like vanilla.
 */
final class BeaconTransaction extends InventoryTransaction{

	public function __construct(Player $source, private BeaconInventory $beacon, private int $primary, private int $secondary){
		parent::__construct($source);
	}

	public function validate() : void{
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		$paid = 0;
		foreach($inputs as $item){
			if(!Beacon::isPayment($item)){
				throw new TransactionValidationException("Only iron, gold, emerald, diamond or netherite can pay a beacon");
			}
			$paid += $item->getCount();
		}
		if($paid !== 1 || $outputs !== []){
			throw new TransactionValidationException("A beacon takes exactly one payment item");
		}
		$block = $this->beacon->getHolder()->getWorld()->getBlock($this->beacon->getHolder());
		if(!$block instanceof Beacon || !$block->canUsePowers($this->primary, $this->secondary)){
			throw new TransactionValidationException("These beacon powers need a bigger pyramid");
		}
	}

	public function execute() : void{
		parent::execute();
		$block = $this->beacon->getHolder()->getWorld()->getBlock($this->beacon->getHolder());
		if($block instanceof Beacon){
			$block->setPowers($this->primary, $this->secondary);
		}
	}
}
