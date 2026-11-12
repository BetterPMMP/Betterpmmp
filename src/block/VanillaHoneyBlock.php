<?php

/*
 * BetterPMMP - honey block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Vanilla honey block: slightly smaller collision box, entities on top move
 * slowly and cannot jump high, entities against the side slide down slowly,
 * and landing on it takes only 20 % of the fall damage. (The client applies
 * the same physics to players itself.)
 */
class VanillaHoneyBlock extends GenericBedrockBlock{

	protected function recalculateCollisionBoxes() : array{
		return [new AxisAlignedBB(0.0625, 0, 0.0625, 0.9375, 0.9375, 0.9375)];
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if($entity instanceof Player){
			if($entity->getMotion()->y < -0.13){
				$entity->resetFallDistance(); // sliding down the side
			}
			return true;
		}
		$motion = $entity->getMotion();
		$y = $motion->y < -0.08 ? -0.05 : $motion->y;
		$entity->setMotion(new Vector3($motion->x * 0.4, $y, $motion->z * 0.4));
		if($y === -0.05){
			$entity->resetFallDistance();
		}
		return true;
	}

	public function onEntityLand(Entity $entity) : ?float{
		$entity->setFallDistance($entity->getFallDistance() * 0.2);
		return null;
	}

	public function getFrictionFactor() : float{
		return 0.8;
	}
}
