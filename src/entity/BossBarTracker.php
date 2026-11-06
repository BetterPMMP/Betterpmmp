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
use function spl_object_id;

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

	/** @var array<int, int> player id => number of syncs the boss has been visible to that player */
	private array $visibleFor = [];
	private int $syncCount = 0;

	public function sync() : void{
		if($this->boss->isClosed() || !$this->boss->isAlive()){
			$this->hideAll();
			return;
		}
		++$this->syncCount;
		$percent = $this->boss->getHealth() / max(1, $this->boss->getMaxHealth());
		$world = $this->boss->getWorld();
		$position = $this->boss->getPosition();
		$rangeSquared = $this->range * $this->range;
		$viewers = $this->boss->getViewers();
		$current = [];
		$visible = [];
		foreach($world->getPlayers() as $player){
			if(!$player->isConnected() || $player->getPosition()->distanceSquared($position) > $rangeSquared){
				continue;
			}
			$id = $player->getId();
			// the client ignores a boss bar for an entity it has not spawned yet: wait until the boss
			// was sent to this player and one more sync has passed
			if(!isset($viewers[spl_object_id($player)])){
				continue;
			}
			$visible[$id] = ($this->visibleFor[$id] ?? 0) + 1;
			if($visible[$id] < 2){
				continue;
			}
			// (re)show regularly: a dimension change or a lost packet clears the bar on the client
			$show = !isset($this->players[$id]) || $this->syncCount % 10 === 0;
			$sent = $show
				? $this->send($player, BossEventPacket::show($this->boss->getId(), $this->title, $percent, $this->color, $this->overlay))
				: $this->send($player, BossEventPacket::healthPercent($this->boss->getId(), $percent));
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
		$this->visibleFor = $visible;
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
