<?php

/*
 * BetterPMMP - settings of a structure block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;

/**
 * Keeps what was entered in the structure block screen (name, offset, size, mode, rotation, ...) in the
 * Bedrock format, so the screen shows it again and it survives a restart.
 */
class StructureBlock extends Spawnable{

	private CompoundTag $settings;

	public function __construct(\pocketmine\world\World $world, \pocketmine\math\Vector3 $pos){
		$this->settings = CompoundTag::create()
			->setString("structureName", "")
			->setInt("data", 1)
			->setInt("xStructureOffset", 0)->setInt("yStructureOffset", 1)->setInt("zStructureOffset", 0)
			->setInt("xStructureSize", 5)->setInt("yStructureSize", 5)->setInt("zStructureSize", 5)
			->setByte("rotation", 0)->setByte("mirror", 0)
			->setByte("ignoreEntities", 0)->setByte("removeBlocks", 0)->setByte("includePlayers", 0)
			->setByte("showBoundingBox", 1)->setFloat("integrity", 100.0)->setLong("seed", 0)
			->setByte("isPowered", 0);
		parent::__construct($world, $pos);
	}

	public function getSettings() : CompoundTag{
		return clone $this->settings;
	}

	public function setSettings(CompoundTag $settings) : void{
		$this->settings = $settings;
		$this->clearSpawnCompoundCache();
		$this->setDirty();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		foreach($nbt->getValue() as $name => $tag){
			if($name !== "id" && $name !== "x" && $name !== "y" && $name !== "z" && $name !== "isMovable"){
				$this->settings->setTag($name, $tag);
			}
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		foreach($this->settings->getValue() as $name => $tag){
			$nbt->setTag($name, $tag);
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->writeSaveData($nbt);
	}
}
