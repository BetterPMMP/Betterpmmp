<?php

/*
 * BetterPMMP - /execute like vanilla Bedrock (1.19.50+ syntax).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\SimpleCommandMap;
use pocketmine\command\utils\ExecuteContext;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\data\bedrock\DimensionIds;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\scoreboard\Scoreboard;
use pocketmine\world\Position;
use function atan2;
use function count;
use function explode;
use function floor;
use function implode;
use function in_array;
use function rad2deg;
use function sqrt;
use function str_contains;
use function strtolower;

/**
 * /execute <subcommands ...> run <command>
 * as, at, positioned [as], rotated [as], facing [entity], align, anchored, in, if|unless (entity, block, score), run.
 */
final class ExecuteCommand extends WorldEditingCommand{
	private const MAX_RUNS = 10000;

	public function __construct(){
		parent::__construct("execute", "Executes a command on behalf of one or more entities.", "/execute <as|at|positioned|rotated|facing|align|anchored|in|if|unless> ... run <command>");
		$this->setPermission(DefaultPermissionNames::COMMAND_EXECUTE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$contexts = [ExecuteContext::of($sender)];
		$i = 0;
		$n = count($args);
		while($i < $n){
			$word = strtolower($args[$i]);
			if($word === "run"){
				$command = SimpleCommandMap::rawArgumentsAfter($i + 1) ?? implode(" ", array_slice($args, $i + 1));
				if($command === ""){
					throw new InvalidCommandSyntaxException();
				}
				if($command[0] === "/"){
					$command = substr($command, 1);
				}
				$runs = 0;
				foreach($contexts as $context){
					if(++$runs > self::MAX_RUNS){
						break;
					}
					//"@s" of a player executor becomes their name, so every command (plugins too) understands it
					$line = $context->executor instanceof Player ? preg_replace('/(?<=^|\s)@s(?=\s|$)/', $context->executor->getName(), $command) ?? $command : $command;
					$sender->getServer()->getCommandMap()->dispatch($context, $line);
				}
				return true;
			}
			$next = [];
			switch($word){
				case "as":
					$this->need($args, $i, 1);
					foreach($contexts as $c){
						foreach(TargetSelector::select($c, $args[$i + 1]) as $entity){
							$next[] = $c->with(executor: $entity);
						}
					}
					$i += 2;
					break;
				case "at":
					$this->need($args, $i, 1);
					foreach($contexts as $c){
						foreach(TargetSelector::select($c, $args[$i + 1]) as $entity){
							$location = $entity->getLocation();
							$next[] = $c->with(position: $entity->getPosition(), yaw: $location->getYaw(), pitch: $location->getPitch());
						}
					}
					$i += 2;
					break;
				case "positioned":
					$this->need($args, $i, 1);
					if(strtolower($args[$i + 1]) === "as"){
						$this->need($args, $i, 2);
						foreach($contexts as $c){
							foreach(TargetSelector::select($c, $args[$i + 2]) as $entity){
								$next[] = $c->with(position: $entity->getPosition());
							}
						}
						$i += 3;
					}else{
						$this->need($args, $i, 3);
						foreach($contexts as $c){
							$next[] = $c->with(position: Position::fromObject($this->parsePosition($c, $args, $i + 1, $c->position), $c->position->getWorld()));
						}
						$i += 4;
					}
					break;
				case "rotated":
					$this->need($args, $i, 1);
					if(strtolower($args[$i + 1]) === "as"){
						$this->need($args, $i, 2);
						foreach($contexts as $c){
							foreach(TargetSelector::select($c, $args[$i + 2]) as $entity){
								$next[] = $c->with(yaw: $entity->getLocation()->getYaw(), pitch: $entity->getLocation()->getPitch());
							}
						}
						$i += 3;
					}else{
						$this->need($args, $i, 2);
						foreach($contexts as $c){
							$next[] = $c->with(yaw: $this->relative($args[$i + 1], $c->yaw), pitch: $this->relative($args[$i + 2], $c->pitch));
						}
						$i += 3;
					}
					break;
				case "facing":
					$this->need($args, $i, 1);
					if(strtolower($args[$i + 1]) === "entity"){
						$this->need($args, $i, 3);
						foreach($contexts as $c){
							foreach(TargetSelector::select($c, $args[$i + 2]) as $entity){
								$target = $entity->getPosition()->add(0, strtolower($args[$i + 3]) === "eyes" && $entity instanceof Living ? $entity->getEyeHeight() : 0, 0);
								$next[] = $this->face($c, $target);
							}
						}
						$i += 4;
					}else{
						$this->need($args, $i, 3);
						foreach($contexts as $c){
							$next[] = $this->face($c, $this->parsePosition($c, $args, $i + 1, $c->position));
						}
						$i += 4;
					}
					break;
				case "align":
					$this->need($args, $i, 1);
					$axes = strtolower($args[$i + 1]);
					foreach($contexts as $c){
						$p = $c->position;
						$next[] = $c->with(position: new Position(
							str_contains($axes, "x") ? floor($p->x) : $p->x,
							str_contains($axes, "y") ? floor($p->y) : $p->y,
							str_contains($axes, "z") ? floor($p->z) : $p->z,
							$p->getWorld()
						));
					}
					$i += 2;
					break;
				case "anchored":
					$this->need($args, $i, 1);
					$eyes = match(strtolower($args[$i + 1])){
						"eyes" => true,
						"feet" => false,
						default => throw new InvalidCommandSyntaxException(),
					};
					foreach($contexts as $c){
						$next[] = $c->with(anchoredEyes: $eyes);
					}
					$i += 2;
					break;
				case "in":
					$this->need($args, $i, 1);
					$dimension = match(strtolower($args[$i + 1])){
						"overworld" => DimensionIds::OVERWORLD,
						"nether" => DimensionIds::NETHER,
						"the_end" => DimensionIds::THE_END,
						default => throw new InvalidCommandSyntaxException(),
					};
					$world = null;
					foreach($sender->getServer()->getWorldManager()->getWorlds() as $candidate){
						if($candidate->getDimensionId() === $dimension){
							$world = $candidate;
							break;
						}
					}
					if($world === null){
						return $this->error($sender, "No loaded world for dimension " . $args[$i + 1]);
					}
					foreach($contexts as $c){
						$next[] = $c->with(position: Position::fromObject($c->position, $world));
					}
					$i += 2;
					break;
				case "if":
				case "unless":
					$this->need($args, $i, 1);
					[$used, $next] = $this->condition($word === "if", $contexts, $args, $i + 1);
					$i += 1 + $used;
					break;
				default:
					throw new InvalidCommandSyntaxException();
			}
			$contexts = $next;
			if($contexts === []){
				//vanilla: a branch without contexts ends silently; the last if/unless reports the result
				if($word === "if" || $word === "unless"){
					$sender->sendMessage("§cTest failed");
				}
				return true;
			}
		}
		//no "run": the result of the conditions
		$sender->sendMessage("Test passed, count: " . count($contexts));
		return true;
	}

	/**
	 * @param ExecuteContext[] $contexts
	 * @param string[] $args
	 * @return array{int, ExecuteContext[]} arguments used, contexts that pass
	 */
	private function condition(bool $want, array $contexts, array $args, int $at) : array{
		$kind = strtolower($args[$at]);
		$passed = [];
		switch($kind){
			case "entity":
				$this->need($args, $at, 1);
				foreach($contexts as $c){
					if((count(TargetSelector::select($c, $args[$at + 1])) > 0) === $want){
						$passed[] = $c;
					}
				}
				return [2, $passed];
			case "block":
				$this->need($args, $at, 4);
				$block = $this->parseBlock($args[$at + 4]);
				if($block === null){
					throw new InvalidCommandSyntaxException();
				}
				foreach($contexts as $c){
					$pos = $this->parsePosition($c, $args, $at + 1, $c->position)->floor();
					$world = $c->position->getWorld();
					$found = $world->isInWorld((int) $pos->x, (int) $pos->y, (int) $pos->z) && $world->isInLoadedTerrain($pos) && $world->getBlock($pos)->getTypeId() === $block->getTypeId();
					if($found === $want){
						$passed[] = $c;
					}
				}
				return [5, $passed];
			case "score":
				$this->need($args, $at, 3);
				$board = Scoreboard::get();
				$objective = $args[$at + 2];
				$operator = $args[$at + 3];
				$used = $operator === "matches" ? 5 : 6;
				$this->need($args, $at, $used - 1);
				foreach($contexts as $c){
					$ok = false;
					foreach($this->names($c, $args[$at + 1]) as $name){
						$score = $board->getScore($objective, $name);
						if($score === null){
							continue;
						}
						if($operator === "matches"){
							$ok = self::inRange($score, $args[$at + 4]);
						}else{
							foreach($this->names($c, $args[$at + 4]) as $other){
								$b = $board->getScore($args[$at + 5], $other);
								if($b === null){
									continue;
								}
								$ok = match($operator){
									"=" => $score === $b,
									"<" => $score < $b,
									"<=" => $score <= $b,
									">" => $score > $b,
									">=" => $score >= $b,
									default => throw new InvalidCommandSyntaxException(),
								};
							}
						}
					}
					if($ok === $want){
						$passed[] = $c;
					}
				}
				return [$used, $passed];
		}
		throw new InvalidCommandSyntaxException();
	}

	/** @return string[] */
	private function names(ExecuteContext $c, string $target) : array{
		if(!TargetSelector::isSelector($target)){
			return [$target];
		}
		$names = [];
		foreach(TargetSelector::select($c, $target) as $entity){
			$names[] = ScoreboardCommand::scoreName($entity);
		}
		return $names;
	}

	/** "5", "1..", "..10", "3..7", "!3..7" */
	private static function inRange(int $score, string $range) : bool{
		$negate = str_starts_with($range, "!");
		if($negate){
			$range = substr($range, 1);
		}
		if(str_contains($range, "..")){
			[$min, $max] = explode("..", $range, 2);
			$ok = ($min === "" || $score >= (int) $min) && ($max === "" || $score <= (int) $max);
		}else{
			$ok = $score === (int) $range;
		}
		return $ok !== $negate;
	}

	private function face(ExecuteContext $c, Vector3 $target) : ExecuteContext{
		$d = $target->subtractVector($c->position->add(0, $c->anchorHeight(), 0));
		$horizontal = sqrt($d->x * $d->x + $d->z * $d->z);
		return $c->with(yaw: rad2deg(atan2(-$d->x, $d->z)), pitch: rad2deg(-atan2($d->y, $horizontal)));
	}

	private function relative(string $value, float $base) : float{
		if(str_starts_with($value, "~")){
			return $base + (float) substr($value, 1);
		}
		return (float) $value;
	}

	/** @param string[] $args */
	private function need(array $args, int $at, int $count) : void{
		if(!isset($args[$at + $count])){
			throw new InvalidCommandSyntaxException();
		}
	}
}
