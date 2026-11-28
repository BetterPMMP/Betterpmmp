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

namespace pocketmine\event\server;

use pocketmine\network\query\QueryInfo;

class QueryRegenerateEvent extends ServerEvent{
	public function __construct(private QueryInfo $queryInfo){}

	public function getQueryInfo() : QueryInfo{
		return $this->queryInfo;
	}

	// BetterPMMP: API 3/4 - the query values used to be on the event itself
	public function getServerName() : string{ return $this->queryInfo->getServerName(); }
	public function setServerName(string $v) : void{ $this->queryInfo->setServerName($v); }
	public function canListPlugins() : bool{ return $this->queryInfo->canListPlugins(); }
	public function setListPlugins(bool $v) : void{ $this->queryInfo->setListPlugins($v); }
	public function getPlugins() : array{ return $this->queryInfo->getPlugins(); }
	public function setPlugins(array $v) : void{ $this->queryInfo->setPlugins($v); }
	public function getPlayerList() : array{ return $this->queryInfo->getPlayerList(); }
	public function setPlayerList(array $v) : void{ $this->queryInfo->setPlayerList($v); }
	public function getPlayerCount() : int{ return $this->queryInfo->getPlayerCount(); }
	public function setPlayerCount(int $v) : void{ $this->queryInfo->setPlayerCount($v); }
	public function getMaxPlayerCount() : int{ return $this->queryInfo->getMaxPlayerCount(); }
	public function setMaxPlayerCount(int $v) : void{ $this->queryInfo->setMaxPlayerCount($v); }
	public function getWorld() : string{ return $this->queryInfo->getWorld(); }
	public function setWorld(string $v) : void{ $this->queryInfo->setWorld($v); }
	public function getLevelName() : string{ return $this->queryInfo->getWorld(); }
	public function setLevelName(string $world) : void{ $this->queryInfo->setWorld($world); }
}
