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

namespace pocketmine\player;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\LongTag;

class OfflinePlayer implements IPlayer{
	public function __construct(
		private string $name,
		private ?CompoundTag $namedtag
	){}

	public function getName() : string{
		return $this->name;
	}

	public function getFirstPlayed() : ?int{
		return ($this->namedtag !== null && ($firstPlayedTag = $this->namedtag->getTag(Player::TAG_FIRST_PLAYED)) instanceof LongTag) ? $firstPlayedTag->getValue() : null;
	}

	public function getLastPlayed() : ?int{
		return ($this->namedtag !== null && ($lastPlayedTag = $this->namedtag->getTag(Player::TAG_LAST_PLAYED)) instanceof LongTag) ? $lastPlayedTag->getValue() : null;
	}

	public function hasPlayedBefore() : bool{
		return $this->namedtag !== null;
	}

	// BetterPMMP: API 3 helpers
	public function isOnline() : bool{ return $this->getPlayer() !== null; }
	public function getPlayer() : ?Player{ return \pocketmine\Server::getInstance()->getPlayerExact($this->getName()); }
	public function getServer() : \pocketmine\Server{ return \pocketmine\Server::getInstance(); }
	public function isOp() : bool{ return \pocketmine\Server::getInstance()->isOp($this->getName()); }
	public function setOp(bool $value) : void{ $value ? \pocketmine\Server::getInstance()->addOp($this->getName()) : \pocketmine\Server::getInstance()->removeOp($this->getName()); }
	public function isBanned() : bool{ return \pocketmine\Server::getInstance()->getNameBans()->isBanned($this->getName()); }
	public function setBanned(bool $banned) : void{ $banned ? \pocketmine\Server::getInstance()->getNameBans()->addBan($this->getName(), null, null, null) : \pocketmine\Server::getInstance()->getNameBans()->remove($this->getName()); }
	public function isWhitelisted() : bool{ return \pocketmine\Server::getInstance()->isWhitelisted($this->getName()); }
	public function setWhitelisted(bool $value) : void{ $value ? \pocketmine\Server::getInstance()->addWhitelist($this->getName()) : \pocketmine\Server::getInstance()->removeWhitelist($this->getName()); }
}
