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

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\FlintSteelSound;

class FlintSteel extends Tool{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if($blockReplace->getTypeId() === BlockTypeIds::AIR){
			$world = $player->getWorld();
			if($this->tryIgniteNetherPortal($blockReplace)){
				$this->applyDamage(1);
				$world->addSound($blockReplace->getPosition()->add(0.5, 0.5, 0.5), new FlintSteelSound());
				return ItemUseResult::SUCCESS;
			}
			$world->setBlock($blockReplace->getPosition(), VanillaBlocks::FIRE());
			$world->addSound($blockReplace->getPosition()->add(0.5, 0.5, 0.5), new FlintSteelSound());

			$this->applyDamage(1);

			return ItemUseResult::SUCCESS;
		}

		return ItemUseResult::NONE;
	}

	/**
	 * Activates the smallest valid vanilla portal frame (2 by 3 interior).
	 * Larger frame detection can be added without changing the ignition contract.
	 */
	private function tryIgniteNetherPortal(Block $target) : bool{
		$pos = $target->getPosition();
		$world = $pos->getWorld();
		$obsidian = VanillaBlocks::OBSIDIAN()->getTypeId();
		$air = BlockTypeIds::AIR;

		foreach([
			[Axis::X, 1, 0],
			[Axis::Z, 0, 1]
		] as [$axis, $dx, $dz]){
			foreach([-1, 0] as $offsetX){
				foreach([-1, 0] as $offsetZ){
					$startX = $pos->getFloorX() + ($dx !== 0 ? $offsetX : 0);
					$startZ = $pos->getFloorZ() + ($dz !== 0 ? $offsetZ : 0);
					foreach([-2, -1, 0] as $offsetY){
						$startY = $pos->getFloorY() + $offsetY;
						$valid = true;

						for($y = -1; $y <= 3 && $valid; ++$y){
							for($w = -1; $w <= 2; ++$w){
								$isFrame = $y === -1 || $y === 3 || $w === -1 || $w === 2;
								$block = $world->getBlockAt($startX + $dx * $w, $startY + $y, $startZ + $dz * $w);
								if($isFrame ? $block->getTypeId() !== $obsidian : $block->getTypeId() !== $air){
									$valid = false;
									break;
								}
							}
						}
						if(!$valid){
							continue;
						}
						for($y = 0; $y < 3; ++$y){
							for($w = 0; $w < 2; ++$w){
								$portal = VanillaBlocks::NETHER_PORTAL()->setAxis($axis);
								$world->setBlockAt($startX + $dx * $w, $startY + $y, $startZ + $dz * $w, $portal);
							}
						}
						return true;
					}
				}
			}
		}
		return false;
	}

	public function getMaxDurability() : int{
		return 65;
	}
}
