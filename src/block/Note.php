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

namespace pocketmine\block;

use pocketmine\block\tile\Note as TileNote;
use pocketmine\block\utils\RedstonePower;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\NoteInstrument;
use pocketmine\world\sound\NoteSound;
use function assert;

class Note extends Opaque{
	public const MIN_PITCH = 0;
	public const MAX_PITCH = 24;

	private int $pitch = self::MIN_PITCH;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileNote){
			$this->pitch = $tile->getPitch();
		}else{
			$this->pitch = self::MIN_PITCH;
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileNote);
		$tile->setPitch($this->pitch);
	}

	public function getFuelTime() : int{
		return 300;
	}

	public function getPitch() : int{
		return $this->pitch;
	}

	/** @return $this */
	public function setPitch(int $pitch) : self{
		if($pitch < self::MIN_PITCH || $pitch > self::MAX_PITCH){
			throw new \InvalidArgumentException("Pitch must be in range " . self::MIN_PITCH . " - " . self::MAX_PITCH);
		}
		$this->pitch = $pitch;
		return $this;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null && $player->isSneaking() && !$item->isNull()){
			return false; //sneak-placing against a note block must not retune it
		}
		$this->pitch = ($this->pitch + 1) % (self::MAX_PITCH + 1);
		$this->position->getWorld()->setBlock($this->position, $this);
		$this->playNote();
		return true;
	}

	public function onAttack(Item $item, int $face, ?Player $player = null) : bool{
		$this->playNote();
		return false; //keep normal breaking behaviour
	}

	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		if(RedstonePower::consumerEdge($this->position, $this->position->getWorld(), $powered) && $powered){
			$this->playNote();
		}
	}

	public function onPostPlace() : void{
		RedstonePower::consumerEdge($this->position, $this->position->getWorld(), RedstonePower::isPowered($this));
	}

	/**
	 * Vanilla only plays a note if the block directly above is air.
	 */
	public function playNote() : bool{
		if($this->getSide(Facing::UP)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new NoteSound($this->getInstrument(), $this->pitch));
		return true;
	}

	/**
	 * Instrument selected by the block below, as in vanilla.
	 */
	public function getInstrument() : NoteInstrument{
		$below = $this->getSide(Facing::DOWN);
		$instrument = match($below->getTypeId()){
			BlockTypeIds::GOLD => NoteInstrument::BELL,
			BlockTypeIds::CLAY => NoteInstrument::FLUTE,
			BlockTypeIds::PACKED_ICE => NoteInstrument::CHIME,
			BlockTypeIds::WOOL => NoteInstrument::GUITAR,
			BlockTypeIds::BONE_BLOCK => NoteInstrument::XYLOPHONE,
			BlockTypeIds::IRON => NoteInstrument::IRON_XYLOPHONE,
			BlockTypeIds::SOUL_SAND => NoteInstrument::COW_BELL,
			BlockTypeIds::PUMPKIN, BlockTypeIds::CARVED_PUMPKIN, BlockTypeIds::LIT_PUMPKIN => NoteInstrument::DIDGERIDOO,
			BlockTypeIds::EMERALD => NoteInstrument::BIT,
			BlockTypeIds::HAY_BALE => NoteInstrument::BANJO,
			BlockTypeIds::GLOWSTONE => NoteInstrument::PLING,
			BlockTypeIds::SAND, BlockTypeIds::RED_SAND, BlockTypeIds::GRAVEL, BlockTypeIds::CONCRETE_POWDER => NoteInstrument::SNARE,
			BlockTypeIds::GLASS, BlockTypeIds::STAINED_GLASS, BlockTypeIds::HARDENED_GLASS, BlockTypeIds::STAINED_HARDENED_GLASS,
			BlockTypeIds::TINTED_GLASS, BlockTypeIds::SEA_LANTERN, BlockTypeIds::BEACON => NoteInstrument::CLICKS_AND_STICKS,
			default => null
		};
		if($instrument !== null){
			return $instrument;
		}
		$toolType = $below->getBreakInfo()->getToolType();
		if(($toolType & BlockToolType::AXE) !== 0){
			return NoteInstrument::DOUBLE_BASS; //wood-based blocks
		}
		if(($toolType & BlockToolType::PICKAXE) !== 0 && $below->isSolid()){
			return NoteInstrument::BASS_DRUM; //stone-based blocks
		}
		return NoteInstrument::PIANO;
	}
}
