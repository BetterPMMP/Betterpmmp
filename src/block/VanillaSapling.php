<?php

/*
 * BetterPMMP - cherry, pale oak and poplar saplings.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\BlockTransaction;
use pocketmine\world\generator\vanilla\BlockWriter;
use pocketmine\world\generator\vanilla\VanillaFeatures;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function abs;
use function in_array;
use function mt_rand;

/**
 * Saplings the core has no tree for: cherry, pale oak (needs 2x2 saplings like
 * dark oak) and poplar. They grow in two stages on random ticks with enough
 * light, or with bone meal. The tree is built with the world generator's
 * features in a copy of the chunks and then applied as one block transaction
 * (StructureGrowEvent, so protection plugins can cancel it).
 */
class VanillaSapling extends GenericBedrockBlock{

	private function kind() : string{
		return match($this->getBedrockName()){
			"minecraft:cherry_sapling" => "cherry",
			"minecraft:pale_oak_sapling" => "pale_oak",
			default => "poplar",
		};
	}

	private function isReady() : bool{
		$tag = $this->getStateData()->getStates()["age_bit"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	private static function isSoil(Block $block) : bool{
		$id = $block->getTypeId();
		if(in_array($id, [BlockTypeIds::DIRT, BlockTypeIds::GRASS, BlockTypeIds::PODZOL, BlockTypeIds::MYCELIUM, BlockTypeIds::FARMLAND, BlockTypeIds::MUD, BlockTypeIds::MUDDY_MANGROVE_ROOTS], true)){
			return true;
		}
		return $block instanceof GenericBedrockBlock && in_array($block->getBedrockName(), ["minecraft:moss_block", "minecraft:pale_moss_block", "minecraft:rooted_dirt", "minecraft:coarse_dirt"], true);
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock) && self::isSoil($blockReplace->getSide(Facing::DOWN));
	}

	public function onNearbyBlockChange() : void{
		if(!self::isSoil($this->getSide(Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer){
			return false;
		}
		$world = $this->position->getWorld();
		$world->addParticle($this->position->add(0.5, 0.5, 0.5), new HappyVillagerParticle());
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		if(mt_rand(1, 100) <= 45){
			$this->advance($player);
		}
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getFullLightAt($this->position->getFloorX(), $this->position->getFloorY() + 1, $this->position->getFloorZ()) >= 9 && mt_rand(1, 7) === 1){
			$this->advance(null);
		}
	}

	private function advance(?Player $player) : void{
		if(!$this->isReady()){
			$this->position->getWorld()->setBlock($this->position, $this->withProperties(["age_bit" => 1]));
			return;
		}
		$this->grow($player);
	}

	/** Grows the tree; returns whether it grew. */
	public function grow(?Player $player) : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$corner = [$x, $z];
		if($this->kind() === "pale_oak"){
			$corner = $this->findSquare($world, $x, $y, $z);
			if($corner === null){
				return false; // pale oaks need four saplings in a square
			}
		}
		[$cx, $cz] = $corner;

		// build the tree in a copy of the surrounding chunks
		$copy = new SimpleChunkManager($world->getMinY(), $world->getMaxY());
		for($chunkX = ($cx >> 4) - 1; $chunkX <= ($cx >> 4) + 1; ++$chunkX){
			for($chunkZ = ($cz >> 4) - 1; $chunkZ <= ($cz >> 4) + 1; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					return false;
				}
				$copy->setChunk($chunkX, $chunkZ, clone $chunk);
			}
		}
		$writer = new BlockWriter($copy);
		$random = new Random(mt_rand());
		// saplings are not "air" for the space check, clear them in the copy first
		foreach($this->saplingPositions($cx, $cz) as [$sx, $sz]){
			$writer->setBlock($sx, $y, $sz, VanillaBlocks::AIR());
		}
		$grown = match($this->kind()){
			"cherry" => VanillaFeatures::cherry($writer, $cx, $y - 1, $cz, $random),
			"pale_oak" => VanillaFeatures::paleOak($writer, $cx, $y - 1, $cz, $random),
			default => self::poplar($writer, $cx, $y - 1, $cz, $random),
		};
		if(!$grown){
			return false;
		}

		// apply the difference as one transaction
		$transaction = new BlockTransaction($world);
		$changes = 0;
		for($dx = -8; $dx <= 9; ++$dx){
			for($dz = -8; $dz <= 9; ++$dz){
				for($dy = -1; $dy <= 24; ++$dy){
					$bx = $cx + $dx;
					$by = $y + $dy;
					$bz = $cz + $dz;
					if($by < $world->getMinY() || $by >= $world->getMaxY()){
						continue;
					}
					$new = $writer->get($bx, $by, $bz);
					if($new !== $world->getBlockAt($bx, $by, $bz)->getStateId()){
						$transaction->addBlockAt($bx, $by, $bz, RuntimeBlockStateRegistry::getInstance()->fromStateId($new));
						++$changes;
					}
				}
			}
		}
		if($changes === 0){
			return false;
		}
		$ev = new StructureGrowEvent($this, $transaction, $player);
		$ev->call();
		return !$ev->isCancelled() && $transaction->apply();
	}

	/** @return list<array{int, int}> */
	private function saplingPositions(int $cx, int $cz) : array{
		return $this->kind() === "pale_oak" ? [[$cx, $cz], [$cx + 1, $cz], [$cx, $cz + 1], [$cx + 1, $cz + 1]] : [[$cx, $cz]];
	}

	/**
	 * North-west corner of a 2x2 square of pale oak saplings containing this one.
	 * @return array{int, int}|null
	 */
	private function findSquare(World $world, int $x, int $y, int $z) : ?array{
		foreach([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$ox, $oz]){
			$ok = true;
			foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$px, $pz]){
				$block = $world->getBlockAt($x + $ox + $px, $y, $z + $oz + $pz);
				if(!$block instanceof self || $block->getBedrockName() !== $this->getBedrockName()){
					$ok = false;
					break;
				}
			}
			if($ok){
				return [$x + $ox, $z + $oz];
			}
		}
		return null;
	}

	/** Poplar: a tall, slim tree with a narrow column of autumn-coloured leaves. */
	private static function poplar(BlockWriter $w, int $x, int $y, int $z, Random $r) : bool{
		$height = 7 + $r->nextBoundedInt(4);
		for($yy = 1; $yy <= $height + 1; ++$yy){
			$state = $w->get($x, $y + $yy, $z);
			if($state !== VanillaBlocks::AIR()->getStateId() && !$w->getBlock($x, $y + $yy, $z)->canBeReplaced()){
				return false;
			}
		}
		$log = ExtraVanillaBlocks::get("minecraft:poplar_log");
		$leaves = ExtraVanillaBlocks::get(["minecraft:yellow_poplar_leaves", "minecraft:orange_poplar_leaves", "minecraft:red_poplar_leaves"][$r->nextBoundedInt(3)]);
		if($log === null || $leaves === null){
			return false;
		}
		$w->setBlock($x, $y, $z, VanillaBlocks::DIRT());
		for($yy = 1; $yy <= $height; ++$yy){
			$w->setBlock($x, $y + $yy, $z, $log);
		}
		$air = VanillaBlocks::AIR()->getStateId();
		for($yy = 3; $yy <= $height + 1; ++$yy){
			// radius 1 at the bottom and top, a little wider in the middle
			$wide = $yy > 4 && $yy < $height - 1;
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					$distance = abs($dx) + abs($dz);
					if(($dx === 0 && $dz === 0 && $yy <= $height) || $distance > ($wide ? 2 : 1) || ($wide && $distance === 2 && $r->nextBoundedInt(3) === 0)){
						continue;
					}
					if($w->get($x + $dx, $y + $yy, $z + $dz) === $air){
						$w->setBlock($x + $dx, $y + $yy, $z + $dz, $leaves);
					}
				}
			}
		}
		return true;
	}
}
