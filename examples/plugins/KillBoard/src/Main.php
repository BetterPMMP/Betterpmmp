<?php

declare(strict_types=1);

namespace leondev\killboard;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scoreboard\Scoreboard;
use pocketmine\utils\TextFormat;
use function arsort;
use function array_slice;
use function class_exists;
use function count;

/**
 * Counts player kills on the BetterPMMP scoreboard and shows them in the sidebar.
 * The scores are the same ones /scoreboard uses, so they can be read and changed with commands too.
 */
final class Main extends PluginBase implements Listener{

	private const OBJECTIVE = "kills";

	protected function onEnable() : void{
		if(!class_exists(Scoreboard::class)){
			$this->getLogger()->error("KillBoard needs BetterPMMP (the scoreboard is not available on this server)");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		$board = Scoreboard::get();
		if(!$board->hasObjective(self::OBJECTIVE)){
			$board->addObjective(self::OBJECTIVE, "dummy", TextFormat::GOLD . "Kills");
		}
		if(!isset($board->getDisplays()["sidebar"])){
			$board->setDisplay("sidebar", self::OBJECTIVE, 1); // highest first
		}
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onDeath(PlayerDeathEvent $event) : void{
		$cause = $event->getPlayer()->getLastDamageCause();
		if(!$cause instanceof EntityDamageByEntityEvent){
			return;
		}
		$killer = $cause->getDamager();
		if(!$killer instanceof Player || $killer === $event->getPlayer()){
			return;
		}
		$board = Scoreboard::get();
		$name = $killer->getName();
		$board->setScore(self::OBJECTIVE, $name, ($board->getScore(self::OBJECTIVE, $name) ?? 0) + 1);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$board = Scoreboard::get();
		if(($args[0] ?? "") === "reset"){
			if(!$sender->hasPermission("killboard.command.reset")){
				$sender->sendMessage(TextFormat::RED . "You may not reset kills.");
				return true;
			}
			if(!isset($args[1])){
				return false;
			}
			$board->resetScore($args[1], self::OBJECTIVE);
			$sender->sendMessage("Kills of " . $args[1] . " reset.");
			return true;
		}
		$scores = $board->getScores(self::OBJECTIVE);
		if(count($scores) === 0){
			$sender->sendMessage("No kills yet.");
			return true;
		}
		arsort($scores);
		$sender->sendMessage(TextFormat::GOLD . "Top kills:");
		$rank = 0;
		foreach(array_slice($scores, 0, 10, true) as $name => $kills){
			$sender->sendMessage(TextFormat::GRAY . ++$rank . ". " . TextFormat::WHITE . $name . TextFormat::GRAY . " – " . $kills);
		}
		return true;
	}
}
