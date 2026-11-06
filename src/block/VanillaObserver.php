<?php

/*
 * BetterPMMP - vanilla observer.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\DirectionalRedstoneSource;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;

/**
 * Watches the block in front of its face. When that block changes, it sends a
 * 2 game tick pulse out of its back after a 2 game tick delay (vanilla timing).
 * The back strongly powers the block behind it.
 */
class VanillaObserver extends GenericBedrockBlock implements DirectionalRedstoneSource{

	private const NAMES = [
		Facing::DOWN => "down", Facing::UP => "up", Facing::NORTH => "north",
		Facing::SOUTH => "south", Facing::WEST => "west", Facing::EAST => "east",
	];
	private const PULSE_TICKS = 2;

	/**
	 * Last seen state of the observed block, per world and observer position.
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private static array $observed = [];
	/**
	 * @var true[][]
	 * @phpstan-var array<int, array<int, true>>
	 */
	private static array $pending = [];

	public function getFacing() : int{
		$value = $this->getStateData()->getStates()["minecraft:facing_direction"]->getValue();
		$facing = array_search($value, self::NAMES, true);
		return $facing === false ? Facing::DOWN : $facing;
	}

	/** @return $this */
	public function setFacing(int $facing) : self{
		return $this->setStateIndex($this->withProperties(["minecraft:facing_direction" => self::NAMES[$facing]])->getStateIndex());
	}

	public function isPowered() : bool{
		return $this->getStateData()->getStates()["powered_bit"]->getValue() !== 0;
	}

	/** @return $this */
	public function setPowered(bool $powered) : self{
		return $this->setStateIndex($this->withProperties(["powered_bit" => $powered])->getStateIndex());
	}

	public function getWeakPowerTowards(int $towards) : int{
		return $this->isPowered() && $towards === Facing::opposite($this->getFacing()) ? 15 : 0;
	}

	public function getStrongPowerTowards(int $towards) : int{
		return $this->getWeakPowerTowards($towards);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		// The face looks where the player looks; the output is at the back, towards the player.
		$this->setFacing($player !== null ? VanillaPiston::lookingDirection($player) : Facing::opposite($face));
		$this->setPowered(false);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function key() : int{
		return World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	private function observedState() : int{
		return $this->position->getWorld()->getBlock($this->position->getSide($this->getFacing()))->getStateId();
	}

	public function onPostPlace() : void{
		self::$observed[$this->position->getWorld()->getId()][$this->key()] = $this->observedState();
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$worldId = $world->getId();
		$key = $this->key();
		$state = $this->observedState();
		$last = self::$observed[$worldId][$key] ?? null;
		self::$observed[$worldId][$key] = $state;
		if($last === null || $last === $state || isset(self::$pending[$worldId][$key])){
			return;
		}
		if(!$this->isPowered()){
			self::$pending[$worldId][$key] = true;
			$world->scheduleDelayedBlockUpdate($this->position, self::PULSE_TICKS);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$worldId = $world->getId();
		$key = $this->key();
		if(isset(self::$pending[$worldId][$key])){
			unset(self::$pending[$worldId][$key]);
			if(!$this->isPowered()){
				$world->setBlock($this->position, $this->setPowered(true));
				$world->scheduleDelayedBlockUpdate($this->position, self::PULSE_TICKS);
				$this->updateBehind();
				return;
			}
		}
		if($this->isPowered()){
			$world->setBlock($this->position, $this->setPowered(false));
			$this->updateBehind();
		}
	}

	/** The powered block behind the observer must update its own neighbours too. */
	private function updateBehind() : void{
		$this->position->getWorld()->notifyRedstoneChange($this->position->getSide(Facing::opposite($this->getFacing())));
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$worldId = $this->position->getWorld()->getId();
		unset(self::$observed[$worldId][$this->key()], self::$pending[$worldId][$this->key()]);
		$result = parent::onBreak($item, $player, $returnedItems);
		$this->updateBehind();
		return $result;
	}
}
