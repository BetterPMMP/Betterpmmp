<?php

/*
 * BetterPMMP - something a player can trade with (villagers, wandering traders).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\trade;

use pocketmine\player\Player;

interface Trader{

	/** @return TradeOffer[] */
	public function getOffers() : array;

	/** Trade tier shown in the trade screen (0 = novice). */
	public function getLevel() : int;

	public function getName() : string;

	public function getId() : int;

	public function isAlive() : bool;

	public function isClosed() : bool;

	public function setTradingPlayer(?Player $player) : void;

	public function onTrade(Player $player, TradeOffer $offer, int $times) : void;
}
