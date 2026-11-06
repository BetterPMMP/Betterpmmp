<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\particle\HeartParticle;
use function atan2;
use function max;
use function rad2deg;
use function min;
use function mt_rand;

trait AnimalBreedingTrait{
	private const TAG_BABY = "BetterPMMPBaby";
	private const TAG_BABY_GROWTH_TICKS = "BetterPMMPBabyGrowthTicks";
	private const TAG_LOVE_TICKS = "BetterPMMPLoveTicks";
	private const TAG_BREED_COOLDOWN = "BetterPMMPBreedCooldown";
	private const LOVE_DURATION_TICKS = 600;
	private const BREED_COOLDOWN_TICKS = 6000;
	private const BABY_GROWTH_TICKS = 24000;

	private bool $baby = false;
	private int $babyGrowthTicks = 0;
	private int $loveTicks = 0;
	private int $breedCooldownTicks = 0;
	private int $particleTicks = 0;

	private function initBreedingState(CompoundTag $nbt) : void{
		$this->babyGrowthTicks = max(0, $nbt->getInt(self::TAG_BABY_GROWTH_TICKS, 0));
		$this->loveTicks = max(0, $nbt->getInt(self::TAG_LOVE_TICKS, 0));
		$this->breedCooldownTicks = max(0, $nbt->getInt(self::TAG_BREED_COOLDOWN, 0));
		$this->setBaby($nbt->getByte(self::TAG_BABY, 0) !== 0);
	}

	private function saveBreedingState(CompoundTag $nbt) : CompoundTag{
		return $nbt->setByte(self::TAG_BABY, $this->baby ? 1 : 0)
			->setInt(self::TAG_BABY_GROWTH_TICKS, $this->babyGrowthTicks)
			->setInt(self::TAG_LOVE_TICKS, $this->loveTicks)
			->setInt(self::TAG_BREED_COOLDOWN, $this->breedCooldownTicks);
	}

	public function isBaby() : bool{ return $this->baby; }
	public function setBaby(bool $baby) : void{
		if($this->baby === $baby){ return; }
		$this->baby = $baby;
		$this->babyGrowthTicks = $baby ? self::BABY_GROWTH_TICKS : 0;
		$this->setScale($baby ? 0.5 : 1.0);
		$this->networkPropertiesDirty = true;
	}
	public function getLoveTicks() : int{ return $this->loveTicks; }
	public function setLoveTicks(int $ticks) : void{ $this->loveTicks = max(0, $ticks); $this->networkPropertiesDirty = true; }
	public function getBreedCooldownTicks() : int{ return $this->breedCooldownTicks; }
	public function setBreedCooldownTicks(int $ticks) : void{ $this->breedCooldownTicks = max(0, $ticks); }

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if(!$this->isAlive()){
			return false;
		}
		$held = $player->getInventory()->getItemInHand();
		if(!$this->isBreedingFood($held)){
			return false;
		}

		if($this->baby){
			$this->consumeOneBreedingItem($player, $held);
			$this->babyGrowthTicks = max(0, $this->babyGrowthTicks - (int) (self::BABY_GROWTH_TICKS * 0.1));
			if($this->babyGrowthTicks === 0){ $this->setBaby(false); }
			$this->getWorld()->addParticle($this->getPosition()->add(0, $this->getEyeHeight(), 0), new HappyVillagerParticle());
			return true;
		}
		if($this->loveTicks > 0 || $this->breedCooldownTicks > 0){
			return false;
		}

		$this->consumeOneBreedingItem($player, $held);
		$this->setLoveTicks(self::LOVE_DURATION_TICKS);
		$this->showLoveParticle();
		return true;
	}

	private int $animalGoalScan = 0;
	private ?\pocketmine\entity\Entity $animalGoalTarget = null;

	/**
	 * Vanilla BreedGoal and TemptGoal: animals in love walk to a partner and
	 * breed when close; others follow a player holding their food.
	 * Returns true when it moved the animal this tick.
	 */
	protected function tickAnimalGoals(int $tickDiff) : bool{
		if(!$this instanceof Mob){
			return false;
		}
		$this->animalGoalScan -= $tickDiff;
		if($this->animalGoalScan <= 0){
			$this->animalGoalScan = 10;
			$this->animalGoalTarget = null;
			if($this->loveTicks > 0 && !$this->baby && $this->breedCooldownTicks === 0){
				$partner = $this->findReadyPartner();
				if($partner instanceof \pocketmine\entity\Entity){
					$this->animalGoalTarget = $partner;
				}
			}
			if($this->animalGoalTarget === null){
				$closest = 100.0;
				foreach($this->getWorld()->getPlayers() as $player){
					if(!$player->isAlive() || $player->isSpectator()){
						continue;
					}
					$distance = $player->getPosition()->distanceSquared($this->getPosition());
					if($distance < $closest && $this->isBreedingFood($player->getInventory()->getItemInHand())){
						$closest = $distance;
						$this->animalGoalTarget = $player;
					}
				}
			}
		}
		$target = $this->animalGoalTarget;
		if($target === null || !$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->getWorld()){
			$this->animalGoalTarget = null;
			return false;
		}
		$navigator = $this->getNavigator();
		$distance = $target->getPosition()->distanceSquared($this->getPosition());
		if($target instanceof BreedableAnimal){
			if($distance < 9.0){
				$navigator->stop();
				if($target->getLoveTicks() > 0 && $this->loveTicks > 0){
					$this->breedWith($target);
				}
				$this->animalGoalTarget = null;
				return true;
			}
			$navigator->moveTo($target->getPosition(), $this->getWalkSpeed(), 1.5);
		}else{
			// TemptGoal: follow, but stop 2.5 blocks away and look at the player
			if($distance < 6.25){
				$navigator->stop();
			}else{
				$navigator->moveTo($target->getPosition(), $this->getWalkSpeed() * 1.2, 2.5);
			}
			$dx = $target->getPosition()->x - $this->getPosition()->x;
			$dz = $target->getPosition()->z - $this->getPosition()->z;
			$this->setRotation(rad2deg(atan2(-$dx, $dz)), 0.0);
		}
		$navigator->tick($tickDiff);
		return true;
	}

	protected function getBreedingFoods() : array{
		return match(static::getNetworkTypeId()){
			EntityIds::CHICKEN => [VanillaItems::WHEAT_SEEDS(), VanillaItems::BEETROOT_SEEDS()],
			EntityIds::COW, EntityIds::MOOSHROOM, EntityIds::SHEEP, EntityIds::GOAT => [VanillaItems::WHEAT()],
			EntityIds::PIG => [VanillaItems::CARROT(), VanillaItems::POTATO(), VanillaItems::BEETROOT()],
			EntityIds::CAMEL => [\pocketmine\block\VanillaBlocks::CACTUS()->asItem()],
			EntityIds::FOX => [VanillaItems::SWEET_BERRIES(), VanillaItems::GLOW_BERRIES()],
			EntityIds::FROG => [VanillaItems::SLIMEBALL()],
			EntityIds::PANDA => [\pocketmine\block\VanillaBlocks::BAMBOO()->asItem()],
			EntityIds::SNIFFER => [VanillaItems::TORCHFLOWER_SEEDS()],
			EntityIds::WOLF => [VanillaItems::RAW_BEEF(), VanillaItems::STEAK(), VanillaItems::RAW_PORKCHOP(), VanillaItems::COOKED_PORKCHOP(), VanillaItems::RAW_CHICKEN(), VanillaItems::COOKED_CHICKEN(), VanillaItems::RAW_MUTTON(), VanillaItems::COOKED_MUTTON(), VanillaItems::RAW_RABBIT(), VanillaItems::COOKED_RABBIT(), VanillaItems::ROTTEN_FLESH()],
			EntityIds::CAT, EntityIds::OCELOT => [VanillaItems::RAW_FISH(), VanillaItems::RAW_SALMON()],
			EntityIds::STRIDER => [\pocketmine\block\VanillaBlocks::WARPED_FUNGUS()->asItem()],
			default => []
		};
	}

	private function isBreedingFood(Item $item) : bool{
		foreach($this->getBreedingFoods() as $food){
			if($item->equals($food, false, false)){ return true; }
		}
		return false;
	}

	private function consumeOneBreedingItem(Player $player, Item $held) : void{
		if($player->hasFiniteResources()){
			$held->setCount($held->getCount() - 1);
			$player->getInventory()->setItemInHand($held);
		}
	}

	private function findReadyPartner() : ?BreedableAnimal{
		foreach($this->getWorld()->getNearbyEntities($this->getBoundingBox()->expandedCopy(8, 4, 8), $this) as $entity){
			if($entity instanceof BreedableAnimal && get_class($entity) === static::class && $entity->isAlive() && !$entity->isBaby() && $entity->getLoveTicks() > 0 && $entity->getBreedCooldownTicks() === 0){
				return $entity;
			}
		}
		return null;
	}

	private function breedWith(BreedableAnimal $partner) : void{
		$position = $this->getPosition();
		$other = $partner->getPosition();
		$child = new static(new Location(($position->x + $other->x) / 2, min($position->y, $other->y), ($position->z + $other->z) / 2, $this->getWorld(), (float) mt_rand(0, 359), 0.0));
		$child->setBaby(true);
		$child->spawnToAll();
		$this->setLoveTicks(0);
		$partner->setLoveTicks(0);
		$this->setBreedCooldownTicks(self::BREED_COOLDOWN_TICKS);
		$partner->setBreedCooldownTicks(self::BREED_COOLDOWN_TICKS);
		$this->showLoveParticle();
		$this->getWorld()->dropExperience($this->getPosition(), mt_rand(1, 7));
	}

	private function showLoveParticle() : void{
		$this->getWorld()->addParticle($this->getPosition()->add(0, $this->getEyeHeight(), 0), new HeartParticle());
	}

	private function tickBreedingState(int $tickDiff) : void{
		$this->breedCooldownTicks = max(0, $this->breedCooldownTicks - $tickDiff);
		if($this->baby && $this->babyGrowthTicks > 0){
			$this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $tickDiff);
			if($this->babyGrowthTicks === 0){ $this->setBaby(false); }
		}
		if($this->loveTicks > 0){
			$this->setLoveTicks($this->loveTicks - $tickDiff);
			$this->particleTicks += $tickDiff;
			if($this->particleTicks >= 20){ $this->particleTicks %= 20; $this->showLoveParticle(); }
		}
	}

	private function syncBreedingNetworkData(EntityMetadataCollection $properties) : void{
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		$properties->setGenericFlag(EntityMetadataFlags::INLOVE, $this->loveTicks > 0);
	}
}
