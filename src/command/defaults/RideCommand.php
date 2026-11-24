<?php

/*
 * BetterPMMP - /ride like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\entity\riding\RidingManager;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function strtolower;

/**
 * /ride <riders> start_riding <ride> [teleport_rider|teleport_ride]
 * /ride <riders> stop_riding
 * /ride <rides> evict_riders
 */
final class RideCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("ride", "Makes entities ride other entities, stops entities from riding, makes rides evict their riders.", "/ride <riders> <start_riding|stop_riding|evict_riders> [ride]");
		$this->setPermission(DefaultPermissionNames::COMMAND_RIDE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$entities = TargetSelector::select($sender, $args[0]);
		if($entities === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		$done = 0;
		switch(strtolower($args[1])){
			case "start_riding":
				if(!isset($args[2])){
					throw new InvalidCommandSyntaxException();
				}
				$ride = TargetSelector::select($sender, $args[2])[0] ?? null;
				if($ride === null){
					$sender->sendMessage("§cNo ride matched selector");
					return true;
				}
				$teleportRide = strtolower($args[3] ?? "teleport_rider") === "teleport_ride";
				foreach($entities as $rider){
					if($rider === $ride){
						continue;
					}
					if($teleportRide){
						$ride->teleport($rider->getLocation());
					}else{
						$rider->teleport($ride->getLocation());
					}
					if(RidingManager::isRiding($rider)){
						RidingManager::dismount($rider);
					}
					if(RidingManager::mount($rider, $ride)){
						++$done;
					}
				}
				break;
			case "stop_riding":
				foreach($entities as $rider){
					if(RidingManager::isRiding($rider)){
						RidingManager::dismount($rider);
						++$done;
					}
				}
				break;
			case "evict_riders":
				foreach($entities as $ride){
					if(RidingManager::getRiders($ride) !== []){
						RidingManager::ejectAll($ride);
						++$done;
					}
				}
				break;
			default:
				throw new InvalidCommandSyntaxException();
		}
		$sender->sendMessage($done === 0 ? "§cNothing changed" : "Done for " . $done . " entities");
		return true;
	}
}
