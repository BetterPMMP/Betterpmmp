<?php

/*
 * BetterPMMP - fishing bobber.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\Water;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\item\enchantment\ExtraEnchantments;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\FishingRod;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\particle\BubbleParticle;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sqrt;

/**
 * Vanilla fishing: the bobber floats on water, a fish bites after 5-30
 * seconds (lure: -5 s per level), the bobber dips and the player has about
 * 1-2 seconds to reel in. Reeling in during a bite gives fishing loot
 * (fish 85 %, junk 10 %, treasure 5 %, adjusted by luck of the sea) and
 * 1-6 experience. Mobs can be hooked and pulled.
 */
class FishingHook extends Entity implements NeverSavedWithChunkEntity{

	/** @var array<int, FishingHook> player entity id => their bobber */
	private static array $hooks = [];

	private ?Player $owner = null;
	private int $waitTicks = 0;
	private int $biteTicks = 0;
	private ?Entity $hooked = null;
	private int $lure = 0;
	private int $luck = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::FISHING_HOOK; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }
	protected function getInitialDragMultiplier() : float{ return 0.08; }
	protected function getInitialGravity() : float{ return 0.04; }
	public function canBeCollidedWith() : bool{ return false; }

	public static function getHookOf(Player $player) : ?self{
		$hook = self::$hooks[$player->getId()] ?? null;
		if($hook !== null && $hook->isClosed()){
			unset(self::$hooks[$player->getId()]);
			return null;
		}
		return $hook;
	}

	public static function cast(Player $player, Item $rod) : self{
		$dir = $player->getDirectionVector();
		$hook = new self(Location::fromObject($player->getEyePos()->addVector($dir->multiply(0.3)), $player->getWorld(), $player->getLocation()->getYaw(), 0.0));
		$hook->owner = $player;
		$hook->lure = $rod->getEnchantmentLevel(ExtraEnchantments::get("lure"));
		$hook->luck = $rod->getEnchantmentLevel(ExtraEnchantments::get("luck_of_the_sea"));
		$hook->setMotion(new Vector3($dir->x * 1.2 + mt_rand(-10, 10) / 1000, $dir->y * 1.2 + 0.2, $dir->z * 1.2 + mt_rand(-10, 10) / 1000));
		$hook->spawnToAll();
		self::$hooks[$player->getId()] = $hook;
		return $hook;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setLong(EntityMetadataProperties::OWNER_EID, $this->owner?->getId() ?? -1);
	}

	private function inWater() : bool{
		$p = $this->location;
		return $this->getWorld()->getBlockAt((int) floor($p->x), (int) floor($p->y), (int) floor($p->z)) instanceof Water;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$owner = $this->owner;
		if($owner === null || !$owner->isConnected() || !$owner->isAlive() || $owner->getWorld() !== $this->getWorld()
			|| !$owner->getInventory()->getItemInHand() instanceof FishingRod || $owner->getPosition()->distanceSquared($this->location) > 32 * 32){
			$this->flagForDespawn();
			return true;
		}
		if($this->hooked !== null){
			if($this->hooked->isClosed() || !$this->hooked->isAlive()){
				$this->hooked = null;
			}else{
				$pos = $this->hooked->getPosition();
				$this->teleport(new Vector3($pos->x, $pos->y + $this->hooked->getSize()->getHeight() * 0.8, $pos->z));
				return true;
			}
		}
		if(!$this->inWater()){
			// hook a mob it flies into
			if(!$this->isOnGround()){
				foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(0.3, 0.3, 0.3), $this) as $entity){
					if($entity !== $owner && !$entity instanceof self && !$entity instanceof ItemEntity && !$entity instanceof ExperienceOrb){
						$this->hooked = $entity;
						return true;
					}
				}
			}
			$this->waitTicks = 0;
			$this->biteTicks = 0;
			return true;
		}

		// float on the surface
		$motion = $this->getMotion();
		$p = $this->location;
		$submerged = $this->getWorld()->getBlockAt((int) floor($p->x), (int) floor($p->y + 0.3), (int) floor($p->z)) instanceof Water;
		$this->setMotion(new Vector3($motion->x * 0.8, $submerged ? min(0.1, $motion->y + 0.08) : $motion->y * 0.5, $motion->z * 0.8));

		if($this->biteTicks > 0){
			$this->biteTicks -= $tickDiff;
			if($this->biteTicks <= 0){
				$this->waitTicks = 0; // the fish got away
			}
			return true;
		}
		if($this->waitTicks <= 0){
			$this->waitTicks = max(20, mt_rand(100, 600) - $this->lure * 100);
			return true;
		}
		$this->waitTicks -= $tickDiff;
		if($this->waitTicks <= 20 && $this->waitTicks % 4 === 0){
			$this->getWorld()->addParticle($this->location->add(mt_rand(-10, 10) / 10, 0.1, mt_rand(-10, 10) / 10), new BubbleParticle());
		}
		if($this->waitTicks <= 0){
			// bite: the bobber dips
			$this->biteTicks = mt_rand(20, 40);
			$this->setMotion(new Vector3(0, -0.2, 0));
			foreach($this->getViewers() as $viewer){
				$viewer->getNetworkSession()->sendDataPacket(ActorEventPacket::create($this->getId(), ActorEvent::FISH_HOOK_HOOK, 0, null));
			}
			$this->getWorld()->addSound($this->location, new \pocketmine\world\sound\BucketFillWaterSound());
		}
		return true;
	}

	/**
	 * Reels in. Returns the durability damage for the rod.
	 */
	public function reel() : int{
		$owner = $this->owner;
		unset(self::$hooks[$owner?->getId() ?? -1]);
		$this->flagForDespawn();
		if($owner === null){
			return 0;
		}
		$ownerPos = $owner->getPosition();
		if($this->hooked !== null && !$this->hooked->isClosed()){
			$d = $ownerPos->subtractVector($this->hooked->getPosition());
			$this->hooked->setMotion($this->hooked->getMotion()->addVector($d->multiply(0.1)));
			return 5;
		}
		if($this->biteTicks > 0){
			$loot = $this->rollLoot();
			$d = $ownerPos->subtractVector($this->location);
			$distance = sqrt($d->x ** 2 + $d->y ** 2 + $d->z ** 2);
			$motion = new Vector3($d->x * 0.1, $d->y * 0.1 + sqrt($distance) * 0.08, $d->z * 0.1);
			$this->getWorld()->dropItem($this->location, $loot, $motion, 0);
			$this->getWorld()->dropExperience($ownerPos, mt_rand(1, 6));
			return 1;
		}
		return $this->isOnGround() ? 2 : 0;
	}

	/** Vanilla fishing loot table (Java 1.21 weights). */
	private function rollLoot() : Item{
		$treasure = 5 + $this->luck * 2;
		$junk = max(0, 10 - $this->luck * 2);
		$roll = mt_rand(0, 99);
		$parser = StringToItemParser::getInstance();
		if($roll < $treasure){
			return match(mt_rand(0, 5)){
				0 => VanillaItems::BOW()->setDamage(mt_rand(0, 300)),
				1 => VanillaItems::ENCHANTED_BOOK()->addEnchantment(new \pocketmine\item\enchantment\EnchantmentInstance(VanillaEnchantments::UNBREAKING(), mt_rand(1, 3))),
				2 => VanillaItems::FISHING_ROD()->setDamage(mt_rand(0, 300)),
				3 => VanillaItems::NAME_TAG(),
				4 => $parser->parse("nautilus_shell") ?? VanillaItems::NAME_TAG(),
				default => $parser->parse("saddle") ?? VanillaItems::NAME_TAG(),
			};
		}
		if($roll < $treasure + $junk){
			$junkItems = [
				[17, $parser->parse("lily_pad")], [10, VanillaItems::LEATHER_BOOTS()->setDamage(mt_rand(0, 50))], [10, VanillaItems::LEATHER()],
				[10, VanillaItems::BONE()], [10, VanillaItems::POTION()], [5, VanillaItems::STRING()], [2, VanillaItems::FISHING_ROD()->setDamage(mt_rand(0, 300))],
				[10, VanillaItems::BOWL()], [5, VanillaItems::STICK()], [1, VanillaItems::INK_SAC()->setCount(10)],
				[10, $parser->parse("tripwire_hook")], [10, VanillaItems::ROTTEN_FLESH()],
			];
			return self::weighted($junkItems) ?? VanillaItems::STICK();
		}
		return self::weighted([[60, VanillaItems::RAW_FISH()], [25, VanillaItems::RAW_SALMON()], [2, VanillaItems::CLOWNFISH()], [13, VanillaItems::PUFFERFISH()]]) ?? VanillaItems::RAW_FISH();
	}

	/** @param array<int, array{int, Item|null}> $entries */
	private static function weighted(array $entries) : ?Item{
		$total = 0;
		foreach($entries as [$w, $item]){
			$total += $item !== null ? $w : 0;
		}
		$roll = mt_rand(0, max(0, $total - 1));
		foreach($entries as [$w, $item]){
			if($item === null){
				continue;
			}
			$roll -= $w;
			if($roll < 0){
				return $item;
			}
		}
		return null;
	}

	protected function onDispose() : void{
		if($this->owner !== null && (self::$hooks[$this->owner->getId()] ?? null) === $this){
			unset(self::$hooks[$this->owner->getId()]);
		}
		parent::onDispose();
	}
}
