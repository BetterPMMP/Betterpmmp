<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Location;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use function array_shift;
use function count;
use function round;

class TeleportCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"tp",
			KnownTranslationFactory::pocketmine_command_tp_description(),
			KnownTranslationFactory::commands_tp_usage(),
			["teleport"]
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_TELEPORT_OTHER
		]);
	}

	private function findPlayer(CommandSender $sender, string $playerName) : ?Player{
		$subject = $sender->getServer()->getPlayerByPrefix($playerName);
		if($subject === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_error_playerNotFound($playerName)->prefix(TextFormat::RED));
			return null;
		}
		return $subject;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(($handled = $this->teleportEntities($sender, $args)) !== null){
			return $handled;
		}
		switch(count($args)){
			case 1: // /tp targetPlayer
			case 3: // /tp x y z
			case 5: // /tp x y z yaw pitch - TODO: 5 args could be target x y z yaw :(
				$subjectName = null; //self
				break;
			case 2: // /tp player1 player2
			case 4: // /tp player1 x y z - TODO: 4 args could be x y z yaw :(
			case 6: // /tp player1 x y z yaw pitch
				$subjectName = array_shift($args);
				break;
			default:
				throw new InvalidCommandSyntaxException();
		}

		$subject = $this->fetchPermittedPlayerTarget($sender, $subjectName, DefaultPermissionNames::COMMAND_TELEPORT_SELF, DefaultPermissionNames::COMMAND_TELEPORT_OTHER);
		if($subject === null){
			return true;
		}

		switch(count($args)){
			case 1:
				$targetPlayer = $this->findPlayer($sender, $args[0]);
				if($targetPlayer === null){
					return true;
				}

				$subject->teleport($targetPlayer->getLocation());
				Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success($subject->getName(), $targetPlayer->getName()));

				return true;
			case 3:
			case 5:
				$base = $subject->getLocation();
				if(count($args) === 5){
					$yaw = (float) $args[3];
					$pitch = (float) $args[4];
				}else{
					$yaw = $base->yaw;
					$pitch = $base->pitch;
				}

				$x = $this->getRelativeDouble($base->x, $sender, $args[0]);
				$y = $this->getRelativeDouble($base->y, $sender, $args[1], World::Y_MIN, World::Y_MAX);
				$z = $this->getRelativeDouble($base->z, $sender, $args[2]);
				$targetLocation = new Location($x, $y, $z, $base->getWorld(), $yaw, $pitch);

				$subject->teleport($targetLocation);
				Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success_coordinates(
					$subject->getName(),
					(string) round($targetLocation->x, 2),
					(string) round($targetLocation->y, 2),
					(string) round($targetLocation->z, 2)
				));
				return true;
			default:
				throw new AssumptionFailedError("This branch should be unreachable (for now)");
		}
	}

	/**
	 * BetterPMMP: /tp with selectors for any entity (/tp @e[type=cow] @p, /tp @a ~ ~10 ~) and inside
	 * /execute (/execute as @e run tp @s ~ ~1 ~). Null when the plain player form below applies.
	 * @param string[] $args
	 */
	private function teleportEntities(CommandSender $sender, array $args) : ?bool{
		$count = count($args);
		$context = $sender instanceof \pocketmine\command\utils\ExecuteContext ? $sender : null;
		$hasSelector = false;
		foreach($args as $arg){
			if(\pocketmine\command\utils\TargetSelector::isSelector($arg)){
				$hasSelector = true;
			}
		}
		if(!$hasSelector && $context === null){
			return null;
		}
		if($count === 2 || $count === 4 || $count === 6){
			$subjects = \pocketmine\command\utils\TargetSelector::select($sender, $args[0]);
			$rest = array_slice($args, 1);
		}elseif($count === 1 || $count === 3 || $count === 5){
			$self = $context !== null ? $context->executor : ($sender instanceof \pocketmine\entity\Entity ? $sender : null);
			if($self === null){
				throw new InvalidCommandSyntaxException();
			}
			$subjects = [$self];
			$rest = $args;
		}else{
			throw new InvalidCommandSyntaxException();
		}
		if($subjects === []){
			$sender->sendMessage(\pocketmine\utils\TextFormat::RED . "No targets matched selector");
			return true;
		}
		$others = false;
		foreach($subjects as $subject){
			if($subject !== $sender && !($context !== null && $subject === $context->executor && $context->origin === $subject)){
				$others = true;
			}
		}
		if(!$this->testPermission($sender, $others ? DefaultPermissionNames::COMMAND_TELEPORT_OTHER : DefaultPermissionNames::COMMAND_TELEPORT_SELF)){
			return true;
		}
		if(count($rest) === 1){
			$destination = \pocketmine\command\utils\TargetSelector::select($sender, $rest[0])[0] ?? null;
			if($destination === null){
				$sender->sendMessage(\pocketmine\utils\TextFormat::RED . "No targets matched selector");
				return true;
			}
			foreach($subjects as $subject){
				$subject->teleport($destination->getLocation());
			}
			$name = $destination instanceof \pocketmine\player\Player ? $destination->getName() : ($destination->getNameTag() !== "" ? $destination->getNameTag() : $destination::getNetworkTypeId());
			Command::broadcastCommandMessage($sender, "Teleported " . count($subjects) . " entities to " . $name);
			return true;
		}
		foreach($subjects as $subject){
			//~ starts at the execute position (inside /execute), otherwise at the entity itself
			$base = $context !== null ? $context->position : $subject->getLocation();
			$location = $subject->getLocation();
			$yaw = count($rest) === 5 ? (float) $rest[3] : $location->yaw;
			$pitch = count($rest) === 5 ? (float) $rest[4] : $location->pitch;
			$subject->teleport(new Location(
				$this->getRelativeDouble($base->x, $sender, $rest[0]),
				$this->getRelativeDouble($base->y, $sender, $rest[1], World::Y_MIN, World::Y_MAX),
				$this->getRelativeDouble($base->z, $sender, $rest[2]),
				$base->getWorld(),
				$yaw,
				$pitch
			));
		}
		Command::broadcastCommandMessage($sender, "Teleported " . count($subjects) . " entities");
		return true;
	}
}
