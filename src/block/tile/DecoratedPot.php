<?php

/*
 * BetterPMMP - decorated pot block entity.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\math\Vector3;

/**
 * A decorated pot keeps one stack of a single item type and its four sherds.
 * Breaking it drops the stored items.
 */
class DecoratedPot extends Spawnable{

	public const TAG_ITEM = "item";
	public const TAG_SHERDS = "sherds";

	private Item $item;
	/** @var list<string> */
	private array $sherds = [];

	public function __construct(\pocketmine\world\World $world, Vector3 $pos){
		$this->item = VanillaItems::AIR();
		parent::__construct($world, $pos);
	}

	public function getItem() : Item{
		return clone $this->item;
	}

	public function setItem(Item $item) : void{
		$this->item = clone $item;
		$this->clearSpawnCompoundCache();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$item = $nbt->getCompoundTag(self::TAG_ITEM);
		$this->item = $item === null ? VanillaItems::AIR() : Item::nbtDeserialize($item);
		$this->sherds = [];
		$sherds = $nbt->getListTag(self::TAG_SHERDS);
		if($sherds !== null){
			foreach($sherds as $tag){
				if($tag instanceof StringTag){
					$this->sherds[] = $tag->getValue();
				}
			}
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->addData($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addData($nbt);
	}

	private function addData(CompoundTag $nbt) : void{
		if(!$this->item->isNull()){
			$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		}
		$list = [];
		foreach($this->sherds === [] ? ["", "", "", ""] : $this->sherds as $sherd){
			$list[] = new StringTag($sherd);
		}
		$nbt->setTag(self::TAG_SHERDS, new ListTag($list));
	}

	protected function onBlockDestroyedHook() : void{
		if(!$this->item->isNull()){
			$this->position->getWorld()->dropItem($this->position->add(0.5, 0.5, 0.5), $this->item);
			$this->item = VanillaItems::AIR();
		}
	}
}
