<?php

/*
 * BetterPMMP - crafter (1.21).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Container;
use pocketmine\block\tile\Crafter as CrafterTile;
use pocketmine\block\utils\RedstonePower;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\ClickSound;
use function explode;
use function mt_rand;

/**
 * Vanilla crafter: a redstone pulse crafts one item from the recipe in its
 * 3x3 grid (disabled slots count as empty) and puts it out of its front -
 * into a container there, or as a dropped item. Players fill it like a
 * dispenser and click slots to disable them.
 */
class VanillaCrafter extends GenericBedrockBlock{

	private const DELAY = 4;

	private function orientation() : string{
		$tag = $this->getStateData()->getStates()["orientation"] ?? null;
		return $tag === null ? "north_up" : (string) $tag->getValue();
	}

	public function getFacing() : int{
		return match(explode("_", $this->orientation())[0]){
			"down" => Facing::DOWN, "up" => Facing::UP, "south" => Facing::SOUTH, "west" => Facing::WEST, "east" => Facing::EAST, default => Facing::NORTH
		};
	}

	private function isTriggered() : bool{
		$tag = $this->getStateData()->getStates()["triggered_bit"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$look = Facing::opposite(VanillaPiston::lookingDirection($player));
			$name = match($look){ Facing::DOWN => "down", Facing::UP => "up", Facing::SOUTH => "south", Facing::WEST => "west", Facing::EAST => "east", default => "north" };
			$orientation = $look === Facing::DOWN || $look === Facing::UP ? $name . "_north" : $name . "_up";
			try{
				$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["orientation" => $orientation]));
				return true;
			}catch(\InvalidArgumentException){
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function tile() : ?CrafterTile{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof CrafterTile ? $tile : null;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->tile();
		if($player !== null && $tile !== null){
			$player->setCurrentWindow($tile->getInventory());
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		$world = $this->position->getWorld();
		if($powered && !$this->isTriggered()){
			$world->setBlock($this->position, $this->withProperties(["triggered_bit" => true]), false);
			$world->scheduleDelayedBlockUpdate($this->position, self::DELAY);
		}elseif(!$powered && $this->isTriggered()){
			$world->setBlock($this->position, $this->withProperties(["triggered_bit" => false, "crafting" => false]), false);
		}
	}

	public function onScheduledUpdate() : void{
		$tile = $this->tile();
		if($tile === null){
			return;
		}
		$world = $this->position->getWorld();
		$grid = $tile->getInventory();
		$grid->refresh();
		$recipe = null;
		foreach(Server::getInstance()->getCraftingManager()->getCraftingRecipeIndex() as $candidate){
			if($candidate->matchesCraftingGrid($grid)){
				$recipe = $candidate;
				break;
			}
		}
		if($recipe === null){
			$world->addSound($this->position, new ClickSound(1.2)); // fail sound
			return;
		}
		$results = $recipe->getResultsFor($grid);
		// one of each ingredient is used; buckets and bottles stay behind like in a crafting table
		for($slot = 0; $slot < 9; ++$slot){
			$item = $grid->getItem($slot);
			if($item->isNull()){
				continue;
			}
			$remainder = match($item->getTypeId()){
				\pocketmine\item\ItemTypeIds::WATER_BUCKET, \pocketmine\item\ItemTypeIds::LAVA_BUCKET, \pocketmine\item\ItemTypeIds::MILK_BUCKET => \pocketmine\item\VanillaItems::BUCKET(),
				\pocketmine\item\ItemTypeIds::HONEY_BOTTLE => \pocketmine\item\VanillaItems::GLASS_BOTTLE(),
				default => null
			};
			$grid->setItem($slot, $remainder ?? $item->setCount($item->getCount() - 1));
		}
		foreach($results as $result){
			$this->output($result);
		}
		try{
			$world->setBlock($this->position, $this->withProperties(["crafting" => true]), false);
		}catch(\InvalidArgumentException){
		}
		$world->addSound($this->position, new ClickSound(1.0));
	}

	private function output(Item $item) : void{
		$facing = $this->getFacing();
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position->getSide($facing));
		if($tile instanceof Container && $tile->getInventory()->canAddItem($item)){
			$tile->getInventory()->addItem($item);
			return;
		}
		[$dx, $dy, $dz] = Facing::OFFSET[$facing];
		$world->dropItem($this->position->add(0.5 + $dx * 0.7, 0.5 + $dy * 0.7, 0.5 + $dz * 0.7), $item,
			new Vector3($dx * 0.1 + mt_rand(-10, 10) / 1000, $dy * 0.1 + ($dy === 0 ? 0.1 : 0), $dz * 0.1 + mt_rand(-10, 10) / 1000), 0);
	}
}
