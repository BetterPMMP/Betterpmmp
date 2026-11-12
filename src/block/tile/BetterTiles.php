<?php

/*
 * BetterPMMP - block entities for shelves and command blocks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\World;

/** A shelf shows three items. */
class Shelf extends Spawnable{

	public const SLOTS = 3;

	/** @var Item[] */
	private array $items = [];

	public function __construct(World $world, Vector3 $pos){
		for($i = 0; $i < self::SLOTS; ++$i){
			$this->items[$i] = VanillaItems::AIR();
		}
		parent::__construct($world, $pos);
	}

	public function getItem(int $slot) : Item{
		return clone ($this->items[$slot] ?? VanillaItems::AIR());
	}

	public function setItem(int $slot, Item $item) : void{
		if($slot >= 0 && $slot < self::SLOTS){
			$this->items[$slot] = clone $item;
			$this->clearSpawnCompoundCache();
		}
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$list = $nbt->getListTag(Container::TAG_ITEMS);
		if($list === null){
			return;
		}
		foreach($list as $index => $tag){
			if($tag instanceof CompoundTag && $index < self::SLOTS){
				try{
					$this->items[$tag->getByte(SavedItemStackData::TAG_SLOT, $index)] = Item::nbtDeserialize($tag);
				}catch(\Throwable){
					// unknown item: leave the slot empty
				}
			}
		}
	}

	private function itemList() : ListTag{
		$list = [];
		foreach($this->items as $slot => $item){
			if(!$item->isNull()){
				$list[] = $item->nbtSerialize($slot);
			}
		}
		return new ListTag($list);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setTag(Container::TAG_ITEMS, $this->itemList());
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setTag(Container::TAG_ITEMS, $this->itemList());
	}

	protected function onBlockDestroyedHook() : void{
		foreach($this->items as $item){
			if(!$item->isNull()){
				$this->position->getWorld()->dropItem($this->position->add(0.5, 0.5, 0.5), $item);
			}
		}
		$this->items = [];
	}
}

/** Command block settings and last result. */
class CommandBlock extends Spawnable{

	public string $command = "";
	public string $lastOutput = "";
	public int $successCount = 0;
	public bool $auto = false;
	public bool $trackOutput = true;
	public int $tickDelay = 0;
	public bool $executeOnFirstTick = true;
	public bool $powered = false;
	public string $customName = "";

	public function readSaveData(CompoundTag $nbt) : void{
		$this->command = $nbt->getString("Command", "");
		$this->lastOutput = $nbt->getString("LastOutput", "");
		$this->successCount = $nbt->getInt("SuccessCount", 0);
		$this->auto = $nbt->getByte("auto", 0) === 1;
		$this->trackOutput = $nbt->getByte("TrackOutput", 1) === 1;
		$this->tickDelay = $nbt->getInt("TickDelay", 0);
		$this->executeOnFirstTick = $nbt->getByte("ExecuteOnFirstTick", 1) === 1;
		$this->powered = $nbt->getByte("powered", 0) === 1;
		$this->customName = $nbt->getString("CustomName", "");
	}

	private function addData(CompoundTag $nbt) : void{
		$nbt->setString("Command", $this->command);
		$nbt->setString("LastOutput", $this->trackOutput ? $this->lastOutput : "");
		$nbt->setInt("SuccessCount", $this->successCount);
		$nbt->setByte("auto", $this->auto ? 1 : 0);
		$nbt->setByte("TrackOutput", $this->trackOutput ? 1 : 0);
		$nbt->setInt("TickDelay", $this->tickDelay);
		$nbt->setByte("ExecuteOnFirstTick", $this->executeOnFirstTick ? 1 : 0);
		$nbt->setByte("powered", $this->powered ? 1 : 0);
		$nbt->setByte("conditionMet", 1);
		$nbt->setString("CustomName", $this->customName);
		$nbt->setInt("Version", 38);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->addData($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addData($nbt);
	}

	public function changed() : void{
		$this->clearSpawnCompoundCache();
	}
}
