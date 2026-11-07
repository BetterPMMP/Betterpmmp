<?php

/*
 * BetterPMMP - cartography table.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\inventory\CartographyTableInventory;
use pocketmine\item\GenericBedrockItem;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\map\MapManager;
use pocketmine\world\sound\VanillaSound;
use function count;

/**
 * Vanilla cartography table (no experience cost): filled map + paper =
 * zoomed out map, + empty map = two copies, + glass pane = locked map, and
 * any of them (or the map alone) can be renamed.
 */
class CartographyTransaction extends InventoryTransaction{

	private Item $input;
	private Item $additional;

	public function __construct(
		Player $source,
		private readonly CartographyTableInventory $table,
		private readonly Item $expectedResult,
		private readonly int $additionalUsed
	){
		parent::__construct($source);
		$this->input = $table->getItem(0);
		$this->additional = $table->getItem(1);
	}

	private static function bedrockName(Item $item) : string{
		return $item instanceof GenericBedrockItem ? $item->getBedrockName() : "";
	}

	/**
	 * @return array{Item, int}|null result and how many of the second item it uses
	 */
	public static function calculate(Item $input, Item $additional, ?string $rename) : ?array{
		if(self::bedrockName($input) !== "minecraft:filled_map" || MapManager::idOf($input) === null){
			return null;
		}
		$id = MapManager::idOf($input);
		$addName = self::bedrockName($additional);
		$used = 1;
		if($additional->isNull()){
			$result = (clone $input)->setCount(1);
			$used = 0;
		}elseif($additional->getTypeId() === VanillaItems::PAPER()->getTypeId()){
			$result = MapManager::derive($input, "zoom");
		}elseif($addName === "minecraft:empty_map"){
			$result = (clone $input)->setCount(2);
		}elseif($additional->getTypeId() === \pocketmine\block\VanillaBlocks::GLASS_PANE()->asItem()->getTypeId() && !MapManager::isLocked($id)){
			$result = MapManager::derive($input, "lock");
		}else{
			return null;
		}
		if($result === null){
			return null;
		}
		if($rename !== null && $rename !== "" && $rename !== $result->getCustomName()){
			$result->setCustomName($rename);
		}elseif($used === 0){
			return null; // nothing to do
		}
		return [$result, $used];
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		if(count($outputs) !== 1 || !$outputs[0]->equalsExact($this->expectedResult)){
			throw new TransactionValidationException("Unexpected cartography table result");
		}
		$inputPaid = 0;
		$additionalPaid = 0;
		foreach($inputs as $item){
			if($item->equals($this->input) && $inputPaid === 0){
				$inputPaid = $item->getCount();
			}elseif(!$this->additional->isNull() && $item->equals($this->additional)){
				$additionalPaid += $item->getCount();
			}else{
				throw new TransactionValidationException("Unexpected item consumed by the cartography table");
			}
		}
		if($inputPaid !== 1 || $additionalPaid !== $this->additionalUsed){
			throw new TransactionValidationException("Wrong items consumed by the cartography table");
		}
	}

	public function execute() : void{
		parent::execute();
		MapManager::persist($this->expectedResult);
		$pos = $this->table->getHolder();
		$pos->getWorld()->addSound($pos, new VanillaSound(LevelSoundEvent::UI_CARTOGRAPHY_TABLE_TAKE_RESULT));
	}
}
