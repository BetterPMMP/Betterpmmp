<?php

/*
 * BetterPMMP - /camera (fade and clear) like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionColor;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionTime;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function strtolower;

/**
 * /camera <players> fade [time <fadeIn> <hold> <fadeOut>] [color <red> <green> <blue>]
 * /camera <players> clear
 * (camera presets - "set" - need preset data the server does not send yet)
 */
final class CameraCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("camera", "Issues a camera instruction.", "/camera <players> <fade|clear> [time <fadeIn> <hold> <fadeOut>] [color <r> <g> <b>]");
		$this->setPermission(DefaultPermissionNames::COMMAND_CAMERA);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$players = TargetSelector::selectPlayers($sender, $args[0]);
		if($players === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		switch(strtolower($args[1])){
			case "clear":
				$packet = CameraInstructionPacket::create(null, true, null, null, null, null, null, null, null);
				break;
			case "fade":
				$time = null;
				$color = null;
				for($i = 2; $i < count($args); ++$i){
					$word = strtolower($args[$i]);
					if($word === "time" && isset($args[$i + 3])){
						$time = new CameraFadeInstructionTime((float) $args[$i + 1], (float) $args[$i + 2], (float) $args[$i + 3]);
						$i += 3;
					}elseif($word === "color" && isset($args[$i + 3])){
						//vanilla takes 0-255
						$color = new CameraFadeInstructionColor((float) $args[$i + 1] / 255, (float) $args[$i + 2] / 255, (float) $args[$i + 3] / 255);
						$i += 3;
					}else{
						throw new InvalidCommandSyntaxException();
					}
				}
				$packet = CameraInstructionPacket::create(null, null, new CameraFadeInstruction($time, $color), null, null, null, null, null, null);
				break;
			default:
				$sender->sendMessage("§cOnly 'fade' and 'clear' are supported");
				return true;
		}
		foreach($players as $player){
			$player->getNetworkSession()->sendDataPacket($packet);
		}
		$sender->sendMessage("Camera instruction sent to " . count($players) . " player(s)");
		return true;
	}
}
