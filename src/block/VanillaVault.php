<?php

/*
 * BetterPMMP - vault block (trial chambers).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/** Opens with a trial key, once per player (see {@link \pocketmine\world\trial\TrialManager::useVault()}). */
class VanillaVault extends GenericBedrockBlock{

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function getLightLevel() : int{
		return 6;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null){
			return false;
		}
		return $this->position->getWorld()->getTrialManager()->useVault($player, $this, $item);
	}

	/** After ejecting, the vault goes back to active. */
	public function onScheduledUpdate() : void{
		try{
			$this->position->getWorld()->setBlock($this->position, $this->withProperties(["vault_state" => "active"]));
		}catch(\InvalidArgumentException){
		}
	}
}
