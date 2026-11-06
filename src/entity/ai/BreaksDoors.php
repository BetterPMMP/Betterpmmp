<?php

/*
 * BetterPMMP - zombies breaking wooden doors on Hard.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\Door;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * Vanilla: on Hard a zombie that walks into a closed wooden door hammers on
 * it (cracks grow, knocking sounds) and destroys it after 12 seconds. The
 * door is removed without dropping.
 */
trait BreaksDoors{

	private int $doorBreakTicks = 0;
	private ?Vector3 $doorBreakPos = null;

	protected function tickDoorBreaking(int $tickDiff) : void{
		$world = $this->getWorld();
		$hard = $world->getDifficulty() === World::DIFFICULTY_HARD;
		$this->getNavigator()->setCanBreakDoors($hard && !$this->isBabyMob());
		$door = $hard && !$this->isBabyMob() && $this->isCollidedHorizontally ? $this->doorInFront() : null;
		if($door === null){
			$this->stopDoorBreaking();
			return;
		}
		if($this->doorBreakPos === null || !$this->doorBreakPos->equals($door)){
			$this->stopDoorBreaking();
			$this->doorBreakPos = $door;
			$world->broadcastPacketToViewers($door, LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int) (65535 / 240), $door));
		}
		$this->doorBreakTicks += $tickDiff;
		if(mt_rand(0, 19) === 0){
			$world->broadcastPacketToViewers($door, LevelEventPacket::create(LevelEvent::SOUND_DOOR_BUMP, 0, $door));
		}
		if($this->doorBreakTicks >= 240){
			$block = $world->getBlock($door);
			foreach([$door, $door->up(), $door->down()] as $pos){
				if($world->getBlock($pos) instanceof Door){
					$world->setBlock($pos, VanillaBlocks::AIR());
				}
			}
			$world->broadcastPacketToViewers($door, LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $door));
			$world->broadcastPacketToViewers($door, LevelEventPacket::create(LevelEvent::SOUND_DOOR_CRASH, 0, $door));
			$world->addParticle($door->add(0.5, 0.5, 0.5), new BlockBreakParticle($block));
			$this->doorBreakPos = null;
			$this->doorBreakTicks = 0;
		}
	}

	private function stopDoorBreaking() : void{
		if($this->doorBreakPos !== null){
			$this->getWorld()->broadcastPacketToViewers($this->doorBreakPos, LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $this->doorBreakPos));
		}
		$this->doorBreakPos = null;
		$this->doorBreakTicks = 0;
	}

	private function doorInFront() : ?Vector3{
		$pos = $this->getPosition();
		$dir = $this->getDirectionVector();
		foreach([0.0, 0.6] as $reach){
			$x = (int) floor($pos->x + $dir->x * ($reach + 0.4));
			$z = (int) floor($pos->z + $dir->z * ($reach + 0.4));
			for($dy = 0; $dy <= 1; ++$dy){
				$y = (int) floor($pos->y) + $dy;
				$block = $this->getWorld()->getBlockAt($x, $y, $z);
				if($block instanceof Door && !$block->isOpen() && $block->getTypeId() !== BlockTypeIds::IRON_DOOR){
					return new Vector3($x, $block->isTop() ? $y - 1 : $y, $z);
				}
			}
		}
		return null;
	}

	protected function isBabyMob() : bool{
		return false;
	}
}
