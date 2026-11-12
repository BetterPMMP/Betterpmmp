<?php

/*
 * BetterPMMP - powder snow.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\sound\VanillaSound;
use function min;
use function str_contains;

/**
 * Vanilla powder snow: entities sink in (no collision), take no fall damage,
 * and freeze after 7 seconds inside (1 damage every 2 seconds, the frost
 * overlay grows on the client). Leather boots protect from freezing.
 * Strays, snow golems and polar bears are immune. An empty bucket picks it up.
 */
class VanillaPowderSnow extends GenericBedrockBlock{

	public const FREEZE_TICKS = 140;

	/** @var array<int, array{int, int}> entity id => [frozen ticks, last tick seen] */
	private static array $frozen = [];

	public function isSolid() : bool{ return false; }

	protected function recalculateCollisionBoxes() : array{ return []; }

	public function hasEntityCollision() : bool{ return true; }

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		if($entity->isOnFire()){
			$entity->extinguish();
			$this->position->getWorld()->useBreakOn($this->position);
			return false;
		}
		if(!$entity instanceof Living || !$entity->isAlive() || self::immune($entity)){
			return true;
		}
		$motion = $entity->getMotion();
		if(!$entity instanceof Player){
			$entity->setMotion(new Vector3($motion->x * 0.9, min($motion->y, 0.0) * 0.5, $motion->z * 0.9));
		}
		$now = Server::getInstance()->getTick();
		[$ticks, $last] = self::$frozen[$entity->getId()] ?? [0, $now];
		if($last === $now){
			self::$frozen[$entity->getId()] = [$ticks, $now];
			if($ticks > 0){ return true; }
		}
		$ticks = min(self::FREEZE_TICKS + 40, $ticks + 1);
		self::$frozen[$entity->getId()] = [$ticks, $now];
		$entity->getNetworkProperties()->setFloat(EntityMetadataProperties::FREEZING_EFFECT_STRENGTH, $ticks / self::FREEZE_TICKS > 1 ? 1.0 : $ticks / self::FREEZE_TICKS);
		if($ticks >= self::FREEZE_TICKS && $now % 40 === 0){
			$entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGIC, 1));
		}
		return true;
	}

	private static function immune(Living $entity) : bool{
		if($entity instanceof Player){
			if($entity->isCreative() || $entity->isSpectator()){
				return true;
			}
			return $entity->getArmorInventory()->getBoots()->getTypeId() === ItemTypeIds::LEATHER_BOOTS;
		}
		$class = $entity::class;
		foreach(["Stray", "SnowGolem", "PolarBear"] as $name){
			if(str_contains($class, $name)){
				return true;
			}
		}
		return false;
	}

	/** Thaws an entity that left the powder snow (called every tick from Living). */
	public static function thaw(Living $entity) : void{
		$id = $entity->getId();
		if(!isset(self::$frozen[$id])){
			return;
		}
		[$ticks, $last] = self::$frozen[$id];
		$now = Server::getInstance()->getTick();
		if($now - $last <= 1){
			return;
		}
		$ticks -= 2;
		if($ticks <= 0 || !$entity->isAlive()){
			unset(self::$frozen[$id]);
			$entity->getNetworkProperties()->setFloat(EntityMetadataProperties::FREEZING_EFFECT_STRENGTH, 0.0);
			return;
		}
		self::$frozen[$id] = [$ticks, $now - 2];
		$entity->getNetworkProperties()->setFloat(EntityMetadataProperties::FREEZING_EFFECT_STRENGTH, min(1.0, $ticks / self::FREEZE_TICKS));
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item->getTypeId() !== ItemTypeIds::BUCKET){
			return false;
		}
		$bucket = ExtraVanillaItems::get("minecraft:powder_snow_bucket");
		if($bucket === null){
			return false;
		}
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position, new VanillaSound(LevelSoundEvent::BUCKET_FILL_POWDER_SNOW));
		if($player === null || $player->hasFiniteResources()){
			$item->pop();
			$returnedItems[] = $bucket;
		}
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
