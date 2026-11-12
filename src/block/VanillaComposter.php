<?php

/*
 * BetterPMMP - composter.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function array_filter;
use function mt_rand;
use function str_contains;
use function strtolower;

/**
 * Vanilla composter: plants raise the fill level with a chance that depends
 * on the item (30 / 50 / 65 / 85 / 100 %). Seven layers turn into compost
 * after a second; using the full composter gives one bone meal. A comparator
 * reads the level (0-8).
 */
class VanillaComposter extends GenericBedrockBlock{

	public function getLevel() : int{
		$tag = $this->getStateData()->getStates()["composter_fill_level"] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	/** Chance in percent that an item adds a layer, 0 if it cannot be composted. */
	public static function compostChance(Item $item) : int{
		$name = strtolower($item->getVanillaName());
		$has = fn(string ...$parts) : bool => (bool) array_filter($parts, fn(string $p) => str_contains($name, $p));
		return match(true){
			$has("cake", "pumpkin pie") => 100,
			$has("bread", "cookie", "hay bale", "baked potato", "mushroom block", "nether wart block", "warped wart block", "flowering azalea", "torchflower", "pitcher plant") && !$has("seeds", "pod") => 85,
			$has("apple", "beetroot", "carrot", "cocoa", "fern", "lily pad", "melon", "mushroom", "nether wart", "potato", "pumpkin", "sea pickle", "wheat", "fungus", "roots", "azalea", "dandelion", "poppy", "orchid",
				"allium", "bluet", "tulip", "daisy", "cornflower", "lily of the valley", "sunflower", "lilac", "rose", "peony", "spore blossom", "moss block", "shroomlight", "big dripleaf") && !$has("seeds", "golden", "slice", "poisonous") => 65,
			$has("melon slice", "cactus", "sugar cane", "vines", "tall grass", "dripleaf", "glow lichen", "nether sprouts") => 50,
			$has("seeds", "leaves", "sapling", "grass", "kelp", "seagrass", "sweet berries", "glow berries", "moss carpet", "hanging roots", "propagule", "pitcher pod", "petals", "wildflowers", "leaf litter", "bush") => 30,
			default => 0,
		};
	}

	private function setLevel(int $level) : void{
		$this->position->getWorld()->setBlock($this->position, $this->withProperties(["composter_fill_level" => $level]));
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$level = $this->getLevel();
		if($level >= 8){
			$world->dropItem($this->position->add(0.5, 1.1, 0.5), VanillaItems::BONE_MEAL(), new Vector3(mt_rand(-5, 5) / 100, 0.2, mt_rand(-5, 5) / 100));
			$this->setLevel(0);
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_COMPOSTER_EMPTY));
			return true;
		}
		$chance = self::compostChance($item);
		if($chance === 0 || $level >= 7){
			return $chance > 0;
		}
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
		}
		if(mt_rand(1, 100) <= $chance){
			$this->setLevel($level + 1);
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_COMPOSTER_FILL_SUCCESS));
			if($level + 1 === 7){
				$world->scheduleDelayedBlockUpdate($this->position, 20);
			}
		}else{
			$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_COMPOSTER_FILL));
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->getLevel() === 7){
			$this->setLevel(8);
			$this->position->getWorld()->addSound($this->position, new VanillaSound(LevelSoundEvent::BLOCK_COMPOSTER_READY));
		}
	}
}
