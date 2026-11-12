<?php

/*
 * BetterPMMP - pointed dripstone.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\VanillaSound;
use function max;
use function min;
use function mt_rand;

/**
 * Vanilla pointed dripstone: landing on an upward tip doubles the fall damage
 * (+2 blocks); a hanging stalactite loses its support when the block above
 * goes and breaks. A hanging tip under a dripstone with water or lava two
 * blocks above slowly fills a cauldron up to 10 blocks below it.
 */
class VanillaPointedDripstone extends GenericBedrockBlock{

	private function isHanging() : bool{
		$tag = $this->getStateData()->getStates()["hanging"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	private function thickness() : string{
		$tag = $this->getStateData()->getStates()["dripstone_thickness"] ?? null;
		return $tag === null ? "tip" : (string) $tag->getValue();
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [new AxisAlignedBB(0.3125, 0, 0.3125, 0.6875, 1, 0.6875)];
	}

	public function onEntityLand(Entity $entity) : ?float{
		if(!$this->isHanging() && $this->thickness() === "tip" && $entity instanceof Living){
			$distance = $entity->getFallDistance();
			$entity->resetFallDistance();
			$damage = max(0, ((int) $distance + 2) * 2 - 2);
			if($damage > 0){
				$entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_FALL, $damage));
			}
		}
		return null;
	}

	public function onNearbyBlockChange() : void{
		$support = $this->getSide($this->isHanging() ? Facing::UP : Facing::DOWN);
		$ok = $support->isSolid() || ($support instanceof self && $support->isHanging() === $this->isHanging());
		if(!$ok){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function ticksRandomly() : bool{
		return $this->isHanging() && $this->thickness() === "tip";
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		// find the dripstone root and the liquid above it
		$y = $this->position->getFloorY();
		$top = $y;
		while($top < $y + 12 && $world->getBlockAt($this->position->getFloorX(), $top + 1, $this->position->getFloorZ()) instanceof self){
			++$top;
		}
		$root = $world->getBlockAt($this->position->getFloorX(), $top + 1, $this->position->getFloorZ());
		$liquid = $world->getBlockAt($this->position->getFloorX(), $top + 2, $this->position->getFloorZ());
		if(!$root instanceof GenericBedrockBlock || $root->getBedrockName() !== "minecraft:dripstone_block"){
			return;
		}
		$lava = $liquid instanceof Lava;
		if(!$lava && !$liquid instanceof Water){
			return;
		}
		if(!$lava && mt_rand(1, 1000) <= 11 && $top - $y < 6){
			$this->growDown();
			return;
		}
		if(mt_rand(0, 255) >= ($lava ? 15 : 45)){
			return;
		}
		for($dy = 1; $dy <= 10; ++$dy){
			$pos = $this->position->subtract(0, $dy, 0);
			$block = $world->getBlock($pos);
			if($block instanceof Cauldron){
				$world->setBlock($pos, $lava ? VanillaBlocks::LAVA_CAULDRON()->setFillLevel(FillableCauldron::MAX_FILL_LEVEL) : VanillaBlocks::WATER_CAULDRON()->setFillLevel(2));
				$world->addSound($pos, new VanillaSound($lava ? LevelSoundEvent::CAULDRON_DRIP_LAVA_POINTED_DRIPSTONE : LevelSoundEvent::CAULDRON_DRIP_WATER_POINTED_DRIPSTONE));
				return;
			}
			if($block instanceof WaterCauldron && !$lava){
				if($block->getFillLevel() < FillableCauldron::MAX_FILL_LEVEL){
					$world->setBlock($pos, $block->setFillLevel(min(FillableCauldron::MAX_FILL_LEVEL, $block->getFillLevel() + 2)));
					$world->addSound($pos, new VanillaSound(LevelSoundEvent::CAULDRON_DRIP_WATER_POINTED_DRIPSTONE));
				}
				return;
			}
			if($block->getTypeId() !== BlockTypeIds::AIR){
				return;
			}
		}
	}

	/** Grows the stalactite one block down (water above the dripstone block). */
	private function growDown() : void{
		$world = $this->position->getWorld();
		$below = $this->position->subtract(0, 1, 0);
		if($world->getBlock($below)->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		$above = $this->getSide(Facing::UP);
		if($above instanceof self && $above->thickness() === "frustum"){
			$world->setBlock($above->position, $above->withProperties(["dripstone_thickness" => "middle"]));
		}
		$world->setBlock($this->position, $this->withProperties(["dripstone_thickness" => "frustum"]));
		$world->setBlock($below, $this->withProperties(["dripstone_thickness" => "tip", "hanging" => 1]));
	}
}
