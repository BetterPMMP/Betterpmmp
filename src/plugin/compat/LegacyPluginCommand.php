<?php

/*
 * BetterPMMP - a PluginCommand that old plugins may extend.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\plugin\compat;

use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;

/**
 * API 3 plugins extended PluginCommand for their commands; in PocketMine-MP 5 it is final. Old plugins get this
 * class instead (the rewriter replaces the name), with the API 3 constructor (name, plugin).
 */
class LegacyPluginCommand extends Command implements PluginOwned{

	private ?CommandExecutor $executor;

	public function __construct(string $name, private Plugin $owner, ?CommandExecutor $executor = null){
		parent::__construct($name);
		$this->executor = $executor ?? ($owner instanceof CommandExecutor ? $owner : null);
		$this->usageMessage = "";
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$this->owner->isEnabled() || $this->executor === null){
			return false;
		}
		$success = $this->executor->onCommand($sender, $this, $commandLabel, $args);
		if(!$success && $this->usageMessage !== ""){
			throw new InvalidCommandSyntaxException();
		}
		return $success;
	}

	public function getOwningPlugin() : Plugin{
		return $this->owner;
	}

	/** @deprecated API 3 */
	public function getPlugin() : Plugin{
		return $this->owner;
	}

	public function getExecutor() : ?CommandExecutor{
		return $this->executor;
	}

	public function setExecutor(CommandExecutor $executor) : void{
		$this->executor = $executor;
	}
}
