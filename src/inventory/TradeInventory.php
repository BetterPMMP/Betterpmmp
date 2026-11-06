<?php

/*
 * BetterPMMP - villager trade window.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\entity\trade\TradeOffer;
use pocketmine\entity\Villager;

/**
 * The trade window of a villager: two ingredient slots (items left in them
 * are returned to the player when the window closes) and the villager whose
 * offers are shown.
 */
final class TradeInventory extends SimpleInventory implements TemporaryInventory{

	public const SLOT_INGREDIENT_1 = 0;
	public const SLOT_INGREDIENT_2 = 1;

	public function __construct(private Villager $villager){
		parent::__construct(2);
	}

	public function onOpen(\pocketmine\player\Player $who) : void{
		parent::onOpen($who);
		$this->villager->setTradingPlayer($who);
	}

	public function onClose(\pocketmine\player\Player $who) : void{
		parent::onClose($who);
		$this->villager->setTradingPlayer(null);
	}

	public function getVillager() : Villager{
		return $this->villager;
	}

	/** Offer by the network id used in the trade window (index + 1). */
	public function getOfferByNetId(int $netId) : ?TradeOffer{
		return $this->villager->getOffers()[$netId - 1] ?? null;
	}
}
