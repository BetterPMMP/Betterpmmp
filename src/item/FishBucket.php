<?php

/*
 * BetterPMMP - buckets of fish, axolotl and tadpole.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\AxolotlMob;
use pocketmine\entity\CodMob;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\PufferfishMob;
use pocketmine\entity\SalmonMob;
use pocketmine\entity\TadpoleMob;
use pocketmine\entity\TropicalFishMob;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\VanillaSound;
use function array_search;
use function mt_rand;

/** Empties water and releases the mob it holds, like vanilla. */
class FishBucket extends GenericBedrockItem{

	/** bucket name => mob class */
	private const MOBS = [
		"minecraft:cod_bucket" => CodMob::class,
		"minecraft:salmon_bucket" => SalmonMob::class,
		"minecraft:pufferfish_bucket" => PufferfishMob::class,
		"minecraft:tropical_fish_bucket" => TropicalFishMob::class,
		"minecraft:axolotl_bucket" => AxolotlMob::class,
		"minecraft:tadpole_bucket" => TadpoleMob::class,
	];
	public const BUCKETS = ["minecraft:cod_bucket", "minecraft:salmon_bucket", "minecraft:pufferfish_bucket", "minecraft:tropical_fish_bucket", "minecraft:axolotl_bucket", "minecraft:tadpole_bucket"];

	/** The bucket item a mob is caught into, or null if it cannot be. */
	public static function bucketFor(Entity $mob) : ?Item{
		$name = array_search($mob::class, self::MOBS, true);
		return $name === false ? null : ExtraVanillaItems::get($name);
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(!$blockReplace->canBeReplaced() && !$blockReplace instanceof Water){
			return ItemUseResult::NONE;
		}
		$world = $player->getWorld();
		$pos = $blockReplace->getPosition();
		if($world->getDimensionId() !== \pocketmine\network\mcpe\protocol\types\DimensionIds::NETHER){
			$world->setBlock($pos, VanillaBlocks::WATER()->getFlowingForm());
		}
		$class = self::MOBS[$this->getBedrockName()] ?? null;
		if($class !== null){
			$mob = new $class(Location::fromObject($pos->add(0.5, 0.1, 0.5), $world, mt_rand(0, 359), 0));
			$mob->spawnToAll();
		}
		$world->addSound($pos->add(0.5, 0.5, 0.5), new VanillaSound(LevelSoundEvent::BUCKET_EMPTY_FISH));
		if($player->hasFiniteResources()){
			$this->pop();
			$returnedItems[] = VanillaItems::BUCKET();
		}
		return ItemUseResult::SUCCESS;
	}
}
