<?php

/*
 * BetterPMMP - anvil use.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\Anvil;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\block\utils\AnvilHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\AnvilUseSound;
use function count;
use function mt_rand;

/**
 * Repairing, combining, enchanting with books and renaming at an anvil.
 * The server recomputes the result with {@link AnvilHelper} and only accepts
 * it if the client took exactly that item and paid the right material.
 */
class AnvilTransaction extends InventoryTransaction{

	private Item $input;
	private Item $material;

	public function __construct(
		Player $source,
		private readonly AnvilInventory $anvil,
		private readonly Item $expectedResult,
		private readonly int $levelCost,
		private readonly int $materialUsed
	){
		parent::__construct($source);
		$this->input = $anvil->getItem(AnvilInventory::SLOT_INPUT);
		$this->material = $anvil->getItem(AnvilInventory::SLOT_MATERIAL);
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		if(count($outputs) !== 1 || !$outputs[0]->equalsExact($this->expectedResult)){
			throw new TransactionValidationException("Unexpected anvil result");
		}
		$inputPaid = 0;
		$materialPaid = 0;
		foreach($inputs as $item){
			if(!$this->input->isNull() && $item->equals($this->input) && $inputPaid === 0){
				$inputPaid = $item->getCount();
			}elseif(!$this->material->isNull() && $item->equals($this->material)){
				$materialPaid += $item->getCount();
			}else{
				throw new TransactionValidationException("Unexpected item consumed by the anvil");
			}
		}
		if($inputPaid !== 1){
			throw new TransactionValidationException("The anvil input item must be consumed exactly once");
		}
		if($materialPaid !== $this->materialUsed){
			throw new TransactionValidationException("Expected $this->materialUsed material, got $materialPaid");
		}
		if($this->source->hasFiniteResources() && $this->source->getXpManager()->getXpLevel() < $this->levelCost){
			throw new TransactionValidationException("Not enough experience levels");
		}
	}

	public function execute() : void{
		parent::execute();
		if($this->source->hasFiniteResources()){
			$this->source->getXpManager()->subtractXpLevels($this->levelCost);
		}
		// vanilla: 12 % chance per use to damage the anvil
		$pos = $this->anvil->getHolder();
		$world = $pos->getWorld();
		$block = $world->getBlock($pos);
		if($block instanceof Anvil && $this->source->hasFiniteResources() && mt_rand(0, 99) < 12){
			if($block->getDamage() >= Anvil::VERY_DAMAGED){
				$world->setBlock($pos, VanillaBlocks::AIR());
				$world->addSound($pos, new AnvilBreakSound());
				$this->source->removeCurrentWindow();
				return;
			}
			$world->setBlock($pos, $block->setDamage($block->getDamage() + 1));
		}
		$world->addSound($pos, new AnvilUseSound());
	}
}
