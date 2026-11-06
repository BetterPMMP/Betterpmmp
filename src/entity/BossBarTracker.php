<?php

/*
 * BetterPMMP - boss bar handling for boss mobs.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;
use pocketmine\player\Player;
use function max;

/**
 * Shows a boss bar for an entity to every connected player in the same world
 * within range, keeps its health percentage up to date and hides it again
 * when players leave the range, change worlds or the boss dies.
 */
final class BossBarTracker{

	/** @var Player[] */
	private array $players = [];

	public function __construct(
		private Living $boss,
		private string $title,
		private BossBarColor $color,
		private BossBarOverlay $overlay,
		private float $range
	){}

	public function sync() : void{
		if($this->boss->isClosed() || !$this->boss->isAlive()){
			$this->hideAll();
			return;
		}
		$percent = $this->boss->getHealth() / max(1, $this->boss->getMaxHealth());
		$world = $this->boss->getWorld();
		$position = $this->boss->getPosition();
		$rangeSquared = $this->range * $this->range;
		$current = [];
		foreach($world->getPlayers() as $player){
			if(!$player->isConnected() || $player->getPosition()->distanceSquared($position) > $rangeSquared){
				continue;
			}
			$id = $player->getId();
			$sent = isset($this->players[$id])
				? $this->send($player, BossEventPacket::healthPercent($this->boss->getId(), $percent))
				: $this->send($player, BossEventPacket::show($this->boss->getId(), $this->title, $percent, $this->color, $this->overlay));
			if($sent){
				$current[$id] = $player;
			}
		}
		foreach($this->players as $id => $player){
			if(!isset($current[$id])){
				$this->send($player, BossEventPacket::hide($this->boss->getId()));
			}
		}
		$this->players = $current;
	}

	public function hideAll() : void{
		foreach($this->players as $player){
			$this->send($player, BossEventPacket::hide($this->boss->getId()));
		}
		$this->players = [];
	}

	private function send(Player $player, ClientboundPacket $packet) : bool{
		try{
			$session = $player->getNetworkSession();
			return $session->isConnected() && $session->sendDataPacket($packet);
		}catch(\LogicException){
			return false;
		}
	}
}
