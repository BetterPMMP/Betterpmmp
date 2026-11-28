<?php

/*
 * BetterPMMP - inventory of horses, donkeys, mules and llamas.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\entity\Entity;
use pocketmine\inventory\SimpleInventory;

/**
 * The Bedrock horse screen: slot 0 saddle, slot 1 armor (horse) or carpet (llama), from slot 2 the chest.
 */
final class MountInventory extends SimpleInventory{

	public const SLOT_SADDLE = 0;
	public const SLOT_ARMOR = 1;
	public const CHEST_START = 2;

	public function __construct(private Entity $holder, int $chestSlots){
		parent::__construct(self::CHEST_START + $chestSlots);
	}

	public function getHolder() : Entity{
		return $this->holder;
	}
}
