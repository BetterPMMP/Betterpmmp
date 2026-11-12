<?php

/*
 * BetterPMMP - beehive and bee nest.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\SupportType;
use pocketmine\entity\BeeMob;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\VanillaSound;
use function max;
use function min;

/**
 * Vanilla beehive / bee nest: bees bring nectar and raise the honey level
 * (0-5). At level 5, shears give 3 honeycomb and a glass bottle gives a honey
 * bottle. Harvesting without a lit campfire up to 5 blocks below angers the
 * bees nearby. The front faces the player when placed; a comparator reads the
 * honey level. A bee nest only drops with silk touch.
 */
class VanillaBeehive extends GenericBedrockBlock{

	public const MAX_HONEY = 5;

	public function getHoneyLevel() : int{
		$tag = $this->getStateData()->getStates()["honey_level"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function withHoneyLevel(int $level) : self{
		return $this->withProperties(["honey_level" => max(0, min(self::MAX_HONEY, $level))]);
	}

	/** Adds one level of honey (a bee came back with nectar). */
	public function addHoney() : void{
		if($this->getHoneyLevel() < self::MAX_HONEY){
			$this->position->getWorld()->setBlock($this->position, $this->withHoneyLevel($this->getHoneyLevel() + 1));
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$front = $player !== null ? Facing::opposite($player->getHorizontalFacing()) : Facing::SOUTH;
		$direction = match($front){
			Facing::WEST => 1,
			Facing::NORTH => 2,
			Facing::EAST => 3,
			default => 0,
		};
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["direction" => $direction]));
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->getHoneyLevel() < self::MAX_HONEY){
			return false;
		}
		$world = $this->position->getWorld();
		if($item->getTypeId() === ItemTypeIds::SHEARS){
			$world->dropItem($this->position->add(0.5, 1.1, 0.5), VanillaItems::HONEYCOMB()->setCount(3));
			if($item instanceof Durable && ($player === null || $player->hasFiniteResources())){
				$item->applyDamage(1);
			}
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_BEEHIVE_SHEAR));
		}elseif($item->getTypeId() === ItemTypeIds::GLASS_BOTTLE){
			if($player === null || $player->hasFiniteResources()){
				$item->pop();
			}
			$returnedItems[] = VanillaItems::HONEY_BOTTLE();
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BOTTLE_FILL));
		}else{
			return false;
		}
		$world->setBlock($this->position, $this->withHoneyLevel(0));
		if($player !== null && !$this->isCalmedByCampfire()){
			BeeMob::angerAround($this->position, $player);
		}
		return true;
	}

	private function isCalmedByCampfire() : bool{
		$world = $this->position->getWorld();
		for($dy = 1; $dy <= 5; ++$dy){
			$block = $world->getBlock($this->position->subtract(0, $dy, 0));
			if($block instanceof Campfire){
				return $block->isLit();
			}
		}
		return false;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($player !== null && !$this->isCalmedByCampfire()){
			BeeMob::angerAround($this->position, $player);
		}
		return $result;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if($this->getBedrockName() === "minecraft:bee_nest"){
			return [];
		}
		return parent::getDropsForCompatibleTool($item);
	}

	public function getDropsForIncompatibleTool(Item $item) : array{
		return $this->getBedrockName() === "minecraft:beehive" ? parent::getDropsForCompatibleTool($item) : [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItem()];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::FULL;
	}
}
