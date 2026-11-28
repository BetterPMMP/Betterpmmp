<?php

/*
 * BetterPMMP - llama taming, chest (3 slots per strength) and carpet.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\block\Carpet;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\particle\SmokeParticle;
use function min;
use function mt_rand;

/**
 * Llamas like vanilla: strength 1-5 (random at birth), tamed by trying to get on them (each try works
 * with temper/100, feeding wheat or hay raises the temper), a tamed adult takes a chest with
 * 3 x strength slots and wears a carpet. Sneak + use opens the inventory.
 */
trait LlamaCarrierTrait{
	use ChestCarrierTrait;

	private int $llamaStrength = 3;
	private bool $llamaTamed = false;
	private int $llamaTemper = 0;
	private ?Item $llamaCarpet = null;

	protected function chestSlots() : int{ return 3 * $this->llamaStrength; }

	protected function canOpenMountInventory() : bool{ return $this->llamaTamed && !$this->isBaby(); }

	public function isTamed() : bool{ return $this->llamaTamed; }

	public function getStrength() : int{ return $this->llamaStrength; }

	protected function currentMountEquipment() : array{
		return [MountInventory::SLOT_SADDLE => VanillaItems::AIR(), MountInventory::SLOT_ARMOR => $this->llamaCarpet ?? VanillaItems::AIR()];
	}

	protected function onMountEquipmentChanged(int $slot, Item $item) : void{
		if($slot === MountInventory::SLOT_ARMOR){
			$this->llamaCarpet = self::isCarpet($item) ? (clone $item)->setCount(1) : null;
			$this->broadcastEquipment();
		}
	}

	private static function isCarpet(Item $item) : bool{
		return $item instanceof ItemBlock && $item->getBlock() instanceof Carpet;
	}

	public function getBodyArmor() : Item{
		return $this->llamaCarpet ?? VanillaItems::AIR();
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->llamaStrength = $nbt->getInt("Strength", [1, 1, 2, 2, 3, 3, 4, 5][mt_rand(0, 7)]);
		$this->llamaTamed = $nbt->getByte("Tamed", 0) !== 0;
		$this->llamaTemper = $nbt->getInt("Temper", 0);
		$carpet = $nbt->getCompoundTag("BetterPMMPCarpet");
		if($carpet !== null){
			try{ $this->llamaCarpet = Item::nbtDeserialize($carpet); }catch(\Throwable){ $this->llamaCarpet = null; }
		}
		$this->chestCarrierInit($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt("Strength", $this->llamaStrength);
		$nbt->setByte("Tamed", $this->llamaTamed ? 1 : 0);
		$nbt->setInt("Temper", $this->llamaTemper);
		if($this->llamaCarpet !== null){
			$nbt->setTag("BetterPMMPCarpet", $this->llamaCarpet->nbtSerialize());
		}
		$this->chestCarrierSave($nbt);
		return $nbt;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$held = $player->getInventory()->getItemInHand();
		if($this->chestCarrierInteract($player, $held)){
			return true;
		}
		if($this->llamaTamed && !$this->isBaby() && self::isCarpet($held)){
			if($this->llamaCarpet !== null){
				$this->getWorld()->dropItem($this->getPosition(), $this->llamaCarpet);
			}
			$this->llamaCarpet = (clone $held)->setCount(1);
			if($player->hasFiniteResources()){
				$held->pop();
				$player->getInventory()->setItemInHand($held);
			}
			$this->broadcastEquipment();
			$this->syncMountEquipment();
			return true;
		}
		if(!$this->llamaTamed && !$this->isBaby()){
			$temper = match($held->getTypeId()){
				VanillaItems::WHEAT()->getTypeId() => 3,
				\pocketmine\block\VanillaBlocks::HAY_BALE()->asItem()->getTypeId() => 6,
				default => 0,
			};
			if($temper > 0){
				$this->llamaTemper = min(100, $this->llamaTemper + $temper);
				if($player->hasFiniteResources()){
					$held->pop();
					$player->getInventory()->setItemInHand($held);
				}
				$this->getWorld()->addParticle($this->getPosition()->add(0, 1.5, 0), new HeartParticle());
				return true;
			}
		}
		if(parent::onInteract($player, $clickPos)){
			return true; //breeding, leash ...
		}
		if(!$this->llamaTamed && !$this->isBaby() && $held->isNull() && !$player->isSneaking()){
			//trying to get on: works with temper/100, otherwise the llama throws the player off (smoke)
			if(mt_rand(0, 99) < $this->llamaTemper){
				$this->llamaTamed = true;
				for($i = 0; $i < 7; ++$i){
					$this->getWorld()->addParticle($this->getPosition()->add(mt_rand(-10, 10) / 10, 1.5, mt_rand(-10, 10) / 10), new HeartParticle());
				}
			}else{
				$this->llamaTemper = min(100, $this->llamaTemper + 5);
				for($i = 0; $i < 7; ++$i){
					$this->getWorld()->addParticle($this->getPosition()->add(mt_rand(-10, 10) / 10, 1.5, mt_rand(-10, 10) / 10), new SmokeParticle());
				}
			}
			$this->networkPropertiesDirty = true;
			return true;
		}
		return false;
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		if($this->llamaCarpet !== null){
			$drops[] = $this->llamaCarpet;
		}
		foreach($this->chestCarrierDrops() as $drop){
			$drops[] = $drop;
		}
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->llamaTamed);
		$properties->setInt(EntityMetadataProperties::STRENGTH, $this->llamaStrength);
		$properties->setInt(EntityMetadataProperties::MAX_STRENGTH, 5);
		if($this->llamaTamed){
			$this->chestCarrierSync($properties);
		}
	}

	protected function onDispose() : void{
		$this->chestCarrierDispose();
		parent::onDispose();
	}
}
