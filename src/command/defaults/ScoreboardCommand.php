<?php

/*
 * BetterPMMP - /scoreboard like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\entity\Entity;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\scoreboard\Scoreboard;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function intdiv;
use function is_numeric;
use function max;
use function min;
use function mt_rand;
use function strtolower;
use function trim;

/**
 * /scoreboard objectives add <name> dummy [display name] | remove <name> | list | setdisplay <list|sidebar|belowname> [objective] [ascending|descending]
 * /scoreboard players list [target] | set|add|remove <targets> <objective> <count> | reset <targets> [objective]
 *                     | random <targets> <objective> <min> <max> | test <targets> <objective> <min|*> [max|*]
 *                     | operation <targets> <objective> <+= -= *= /= %= = < > ><> <source> <objective>
 */
final class ScoreboardCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("scoreboard", "Tracks and displays scores for various objectives.", "/scoreboard <objectives|players> ...");
		$this->setPermission(DefaultPermissionNames::COMMAND_SCOREBOARD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$board = Scoreboard::get();
		return match(strtolower($args[0])){
			"objectives" => $this->objectives($sender, $board, $args),
			"players" => $this->players($sender, $board, $args),
			default => throw new InvalidCommandSyntaxException(),
		};
	}

	/** @param string[] $args */
	private function objectives(CommandSender $sender, Scoreboard $board, array $args) : bool{
		switch(strtolower($args[1])){
			case "add":
				if(count($args) < 4){
					throw new InvalidCommandSyntaxException();
				}
				if(strtolower($args[3]) !== "dummy"){
					return $this->fail($sender, "Invalid criteria '" . $args[3] . "' (only dummy exists on Bedrock)");
				}
				$display = count($args) > 4 ? trim(implode(" ", array_slice($args, 4)), "\"") : $args[2];
				if(!$board->addObjective($args[2], "dummy", $display)){
					return $this->fail($sender, "An objective already exists by the name '" . $args[2] . "'");
				}
				Command::broadcastCommandMessage($sender, "Added new objective '" . $args[2] . "' successfully");
				return true;
			case "remove":
				if(count($args) < 3){
					throw new InvalidCommandSyntaxException();
				}
				if(!$board->removeObjective($args[2])){
					return $this->fail($sender, "No objective was found by the name '" . $args[2] . "'");
				}
				Command::broadcastCommandMessage($sender, "Removed objective '" . $args[2] . "' successfully");
				return true;
			case "list":
				$objectives = $board->getObjectives();
				$sender->sendMessage("There are " . count($objectives) . " objective(s):");
				foreach($objectives as $name => $objective){
					$sender->sendMessage("- " . $name . ": displays as '" . $objective["displayName"] . "' and is type '" . $objective["criteria"] . "'");
				}
				return true;
			case "setdisplay":
				if(count($args) < 3){
					throw new InvalidCommandSyntaxException();
				}
				$slot = strtolower($args[2]);
				if(!in_array($slot, Scoreboard::SLOTS, true)){
					throw new InvalidCommandSyntaxException();
				}
				if(!isset($args[3])){
					$board->setDisplay($slot, null, 0);
					Command::broadcastCommandMessage($sender, "Cleared objective display slot '" . $slot . "'");
					return true;
				}
				if(!$board->hasObjective($args[3])){
					return $this->fail($sender, "No objective was found by the name '" . $args[3] . "'");
				}
				//Bedrock: 0 = ascending, 1 = descending (default)
				$order = strtolower($args[4] ?? "descending") === "ascending" ? 0 : 1;
				$board->setDisplay($slot, $args[3], $order);
				Command::broadcastCommandMessage($sender, "Set display slot '" . $slot . "' to show objective '" . $args[3] . "'");
				return true;
		}
		throw new InvalidCommandSyntaxException();
	}

	/** @param string[] $args */
	private function players(CommandSender $sender, Scoreboard $board, array $args) : bool{
		$action = strtolower($args[1]);
		if($action === "list"){
			if(!isset($args[2])){
				$participants = $board->getParticipants();
				$sender->sendMessage("There are " . count($participants) . " tracked entities: " . implode(", ", $participants));
				return true;
			}
			foreach($this->participants($sender, $args[2], $board) as $participant){
				$lines = [];
				foreach($board->getObjectives() as $name => $objective){
					$score = $board->getScore($name, $participant);
					if($score !== null){
						$lines[] = "- " . $objective["displayName"] . ": " . $score . " (" . $name . ")";
					}
				}
				$sender->sendMessage("Showing " . count($lines) . " tracked objective(s) for " . $participant . ":");
				foreach($lines as $line){
					$sender->sendMessage($line);
				}
			}
			return true;
		}
		if(count($args) < 3){
			throw new InvalidCommandSyntaxException();
		}
		$targets = $this->participants($sender, $args[2], $board);
		if($targets === []){
			return $this->fail($sender, "No targets matched selector");
		}
		if($action === "reset"){
			$objective = $args[3] ?? null;
			if($objective !== null && !$board->hasObjective($objective)){
				return $this->fail($sender, "No objective was found by the name '" . $objective . "'");
			}
			foreach($targets as $participant){
				$board->resetScore($participant, $objective);
			}
			Command::broadcastCommandMessage($sender, "Reset score(s) of " . implode(", ", $targets));
			return true;
		}
		if(!isset($args[3]) || !$board->hasObjective($args[3])){
			return $this->fail($sender, "No objective was found by the name '" . ($args[3] ?? "") . "'");
		}
		$objective = $args[3];
		switch($action){
			case "set":
			case "add":
			case "remove":
				if(!isset($args[4]) || !is_numeric($args[4])){
					throw new InvalidCommandSyntaxException();
				}
				$value = (int) $args[4];
				foreach($targets as $participant){
					$current = $board->getScore($objective, $participant) ?? 0;
					$board->setScore($objective, $participant, match($action){
						"set" => $value,
						"add" => $current + $value,
						default => $current - $value,
					});
				}
				Command::broadcastCommandMessage($sender, ($action === "set" ? "Set" : ($action === "add" ? "Added " . $value . " to" : "Removed " . $value . " from")) . " [" . $objective . "] for " . implode(", ", $targets) . ($action === "set" ? " to " . $value : ""));
				return true;
			case "random":
				if(!isset($args[4], $args[5]) || !is_numeric($args[4]) || !is_numeric($args[5]) || (int) $args[4] > (int) $args[5]){
					throw new InvalidCommandSyntaxException();
				}
				foreach($targets as $participant){
					$board->setScore($objective, $participant, mt_rand((int) $args[4], (int) $args[5]));
				}
				Command::broadcastCommandMessage($sender, "Set [" . $objective . "] for " . implode(", ", $targets) . " to random values");
				return true;
			case "test":
				if(!isset($args[4])){
					throw new InvalidCommandSyntaxException();
				}
				$min = $args[4] === "*" ? PHP_INT_MIN : (int) $args[4];
				$max = !isset($args[5]) || $args[5] === "*" ? PHP_INT_MAX : (int) $args[5];
				foreach($targets as $participant){
					$score = $board->getScore($objective, $participant);
					if($score === null || $score < $min || $score > $max){
						return $this->fail($sender, "Score " . ($score ?? "none") . " is NOT in range " . $args[4] . " to " . ($args[5] ?? "*"));
					}
					$sender->sendMessage("Score " . $score . " is in range " . $args[4] . " to " . ($args[5] ?? "*"));
				}
				return true;
			case "operation":
				if(count($args) < 7 || !$board->hasObjective($args[6])){
					throw new InvalidCommandSyntaxException();
				}
				$sources = $this->participants($sender, $args[5], $board);
				foreach($targets as $participant){
					foreach($sources as $source){
						$a = $board->getScore($objective, $participant) ?? 0;
						$b = $board->getScore($args[6], $source);
						if($b === null){
							continue;
						}
						switch($args[4]){
							case "+=": $a += $b; break;
							case "-=": $a -= $b; break;
							case "*=": $a *= $b; break;
							case "/=": if($b !== 0){ $a = intdiv($a, $b); } break;
							case "%=": if($b !== 0){ $a %= $b; } break;
							case "=": $a = $b; break;
							case "<": $a = min($a, $b); break;
							case ">": $a = max($a, $b); break;
							case "><":
								$board->setScore($args[6], $source, $a);
								$a = $b;
								break;
							default: throw new InvalidCommandSyntaxException();
						}
						$board->setScore($objective, $participant, $a);
					}
				}
				Command::broadcastCommandMessage($sender, "Operation applied to [" . $objective . "] of " . implode(", ", $targets));
				return true;
		}
		throw new InvalidCommandSyntaxException();
	}

	/** @return string[] scoreboard names of the targets: players by name, other entities by name tag */
	private function participants(CommandSender $sender, string $target, Scoreboard $board) : array{
		if($target === "*"){
			return $board->getParticipants();
		}
		if(!TargetSelector::isSelector($target)){
			return [trim($target, "\"")];
		}
		$names = [];
		foreach(TargetSelector::select($sender, $target) as $entity){
			$name = self::scoreName($entity);
			if($name !== ""){
				$names[] = $name;
			}
		}
		return $names;
	}

	public static function scoreName(Entity $entity) : string{
		return $entity instanceof Player ? $entity->getName() : $entity->getNameTag();
	}

	private function fail(CommandSender $sender, string $message) : bool{
		$sender->sendMessage("§c" . $message);
		return true;
	}
}
