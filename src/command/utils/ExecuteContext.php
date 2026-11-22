<?php

/*
 * BetterPMMP - the sender of a command run by /execute (executor, position and rotation).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\utils;

use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * Runs a command "as" an entity and "at" a position like vanilla /execute: @s is the executor, ~ and ^
 * start at the position and rotation, while permissions and messages stay with whoever ran /execute.
 */
final class ExecuteContext implements CommandSender{
	use PermissibleDelegateTrait;

	public function __construct(
		public readonly CommandSender $origin,
		public readonly ?Entity $executor,
		public readonly Position $position,
		public readonly float $yaw,
		public readonly float $pitch,
		public readonly bool $anchoredEyes = false
	){
		$this->perm = $origin;
	}

	public static function of(CommandSender $sender) : self{
		if($sender instanceof self){
			return $sender;
		}
		if($sender instanceof Entity){
			$location = $sender->getLocation();
			return new self($sender, $sender, $sender->getPosition(), $location->getYaw(), $location->getPitch());
		}
		$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
		$position = $world !== null ? $world->getSpawnLocation() : new Position(0, 0, 0, null);
		return new self($sender, null, $position, 0.0, 0.0);
	}

	public function with(?Entity $executor = null, ?Position $position = null, ?float $yaw = null, ?float $pitch = null, ?bool $anchoredEyes = null) : self{
		return new self($this->origin, $executor ?? $this->executor, $position ?? $this->position, $yaw ?? $this->yaw, $pitch ?? $this->pitch, $anchoredEyes ?? $this->anchoredEyes);
	}

	/** Height of the anchor above the position: the executor's eyes after "anchored eyes", otherwise 0. */
	public function anchorHeight() : float{
		return $this->anchoredEyes && $this->executor instanceof \pocketmine\entity\Living ? $this->executor->getEyeHeight() : 0.0;
	}

	public function getPosition() : Position{ return $this->position; }

	public function getLanguage() : Language{ return $this->origin->getLanguage(); }

	public function sendMessage(Translatable|string $message) : void{ $this->origin->sendMessage($message); }

	public function getServer() : Server{ return $this->origin->getServer(); }

	public function getName() : string{
		if($this->executor instanceof \pocketmine\player\Player){
			return $this->executor->getName();
		}
		return $this->executor !== null && $this->executor->getNameTag() !== "" ? $this->executor->getNameTag() : $this->origin->getName();
	}

	public function getScreenLineHeight() : int{ return $this->origin->getScreenLineHeight(); }

	public function setScreenLineHeight(?int $height) : void{ $this->origin->setScreenLineHeight($height); }
}
