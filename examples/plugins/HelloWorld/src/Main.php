<?php

declare(strict_types=1);

namespace leondev\helloworld;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Main extends PluginBase implements Listener{

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getLogger()->info("Running on " . $this->getServer()->getName());
	}

	public function onJoin(PlayerJoinEvent $event) : void{
		$event->getPlayer()->sendMessage(TextFormat::GREEN . "Welcome to the server!");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($command->getName() === "hello"){
			$sender->sendMessage("Hello, " . ($args[0] ?? $sender->getName()) . "!");
			return true;
		}
		return false;
	}
}
