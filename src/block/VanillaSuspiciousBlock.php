<?php

/*
 * BetterPMMP - suspicious sand and gravel (archaeology).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use pocketmine\world\World;
use function count;
use function mt_rand;
use function str_contains;

/**
 * Brushing: while a player keeps a brush pointed at the block, the
 * "brushed_progress" state goes 0 → 3 (one step every 10 ticks, about two
 * seconds in total); after the last step the block turns into plain sand or
 * gravel and drops one item of the archaeology loot table. Looking away stops
 * the brushing. Mined normally, it drops nothing (like vanilla).
 */
class VanillaSuspiciousBlock extends GenericBedrockBlock{

	private const STEP_TICKS = 10;

	/** @var array<int, array{Player, int}> block hash => [brushing player, last brush tick] */
	private static array $brushing = [];

	private function key() : int{
		return World::blockHash($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	public function isGravel() : bool{
		return str_contains($this->getBedrockName(), "gravel");
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	/** Called when a player uses a brush on this block. Returns whether brushing started or went on. */
	public function brush(Player $player) : bool{
		$key = $this->key();
		$tick = $player->getServer()->getTick();
		$active = self::$brushing[$key] ?? null;
		self::$brushing[$key] = [$player, $active !== null ? $active[1] : $tick];
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::BRUSH));
		if($active === null){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::STEP_TICKS);
		}
		return true;
	}

	private function stillBrushing(Player $player) : bool{
		if(!$player->isOnline() || !$player->isAlive() || $player->getWorld() !== $this->position->getWorld()
			|| !$player->getInventory()->getItemInHand() instanceof \pocketmine\item\Brush){
			return false;
		}
		$target = $player->getTargetBlock(5);
		return $target !== null && $target->getPosition()->equals($this->position);
	}

	public function onScheduledUpdate() : void{
		$key = $this->key();
		$entry = self::$brushing[$key] ?? null;
		if($entry === null){
			return;
		}
		[$player] = $entry;
		if(!$this->stillBrushing($player)){
			unset(self::$brushing[$key]);
			return;
		}
		$world = $this->position->getWorld();
		$progressTag = $this->getStateData()->getStates()["brushed_progress"] ?? null;
		$progress = $progressTag instanceof \pocketmine\nbt\tag\IntTag ? $progressTag->getValue() : 0;
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::BRUSH));
		if($progress < 3){
			$world->setBlock($this->position, $this->withProperties(["brushed_progress" => $progress + 1]));
			$world->scheduleDelayedBlockUpdate($this->position, self::STEP_TICKS);
			return;
		}
		unset(self::$brushing[$key]);
		$world->setBlock($this->position, $this->isGravel() ? VanillaBlocks::GRAVEL() : VanillaBlocks::SAND());
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::BRUSH_COMPLETED));
		$loot = $this->rollLoot();
		if($loot !== null){
			$world->dropItem($this->position->add(0.5, 1.0, 0.5), $loot, new Vector3(0, 0.1, 0));
		}
		$brush = $player->getInventory()->getItemInHand();
		if($brush instanceof \pocketmine\item\Brush && $player->hasFiniteResources()){
			$brush->applyDamage(1);
			$player->getInventory()->setItemInHand($brush);
		}
	}

	/** Archaeology loot (desert pyramid / desert well for sand, trail ruins / ocean ruins for gravel). */
	private function rollLoot() : ?Item{
		$underWater = $this->position->getWorld()->getBlock($this->position->up()) instanceof Water;
		$names = match(true){
			!$this->isGravel() => mt_rand(0, 3) === 0
				? ["arms_up_pottery_sherd", "brewer_pottery_sherd", "brick", "emerald", "stick", "suspicious_stew"]
				: ["archer_pottery_sherd", "miner_pottery_sherd", "prize_pottery_sherd", "skull_pottery_sherd", "diamond", "emerald", "gunpowder", "tnt"],
			$underWater => ["coal", "emerald", "wheat", "wooden_hoe", "gold_nugget", "angler_pottery_sherd", "shelter_pottery_sherd", "snort_pottery_sherd", "sniffer_egg", "iron_axe"],
			mt_rand(0, 9) === 0 => ["burn_pottery_sherd", "danger_pottery_sherd", "friend_pottery_sherd", "heart_pottery_sherd", "heartbreak_pottery_sherd",
				"howl_pottery_sherd", "sheaf_pottery_sherd", "music_disc_relic", "flow_banner_pattern", "guster_banner_pattern"],
			default => ["emerald", "wheat", "wooden_hoe", "clay_ball", "brick", "yellow_dye", "blue_dye", "light_blue_dye", "white_dye", "orange_dye",
				"red_candle", "green_candle", "purple_candle", "brown_candle", "magenta_stained_glass_pane", "pink_stained_glass_pane", "blue_stained_glass_pane",
				"light_blue_stained_glass_pane", "red_stained_glass_pane", "yellow_stained_glass_pane", "purple_stained_glass_pane", "spruce_hanging_sign",
				"oak_hanging_sign", "gold_nugget", "coal", "wheat_seeds", "beetroot_seeds", "dead_bush", "flower_pot", "string", "lead",
				"angler_pottery_sherd", "archer_pottery_sherd", "brewer_pottery_sherd", "explorer_pottery_sherd", "plenty_pottery_sherd", "prize_pottery_sherd", "shelter_pottery_sherd"],
		};
		$parser = StringToItemParser::getInstance();
		for($attempt = 0; $attempt < 5; ++$attempt){
			$item = $parser->parse($names[mt_rand(0, count($names) - 1)]);
			if($item !== null){
				return $item;
			}
		}
		return null;
	}
}
