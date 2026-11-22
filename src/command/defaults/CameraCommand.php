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
use function array_slice;
use function count;
use function implode;
use function str_contains;
use function strtolower;

/**
 * /camera <players> fade [time <fadeIn> <hold> <fadeOut>] [color <red> <green> <blue>]
 * /camera <players> clear
 * /camera <players> set <preset> [ease <time> <type>] [default | pos <x y z> [rot <xRot> <yRot> | facing <x y z>|<entity>]]
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
			case "set":
				if(!isset($args[2])){
					throw new InvalidCommandSyntaxException();
				}
				$name = str_contains($args[2], ":") ? strtolower($args[2]) : "minecraft:" . strtolower($args[2]);
				$preset = \pocketmine\network\mcpe\camera\CameraPresets::index($name);
				if($preset === null){
					$sender->sendMessage("§cUnknown camera preset " . $args[2] . " (" . implode(", ", \pocketmine\network\mcpe\camera\CameraPresets::NAMES) . ")");
					return true;
				}
				foreach($players as $player){
					$player->getNetworkSession()->sendDataPacket(CameraInstructionPacket::create($this->setInstruction($sender, $player, $preset, array_slice($args, 3)), null, null, null, null, null, null, null, null));
				}
				$sender->sendMessage("Camera set to " . $name . " for " . count($players) . " player(s)");
				return true;
			default:
				throw new InvalidCommandSyntaxException();
		}
		foreach($players as $player){
			$player->getNetworkSession()->sendDataPacket($packet);
		}
		$sender->sendMessage("Camera instruction sent to " . count($players) . " player(s)");
		return true;
	}

	/** @param string[] $args the arguments after the preset */
	private function setInstruction(CommandSender $sender, \pocketmine\player\Player $player, int $preset, array $args) : \pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction{
		$ease = null;
		$position = null;
		$rotation = null;
		$facing = null;
		$default = null;
		$base = $player->getPosition();
		for($i = 0; $i < count($args); ++$i){
			switch(strtolower($args[$i])){
				case "ease":
					if(!isset($args[$i + 2])){
						throw new InvalidCommandSyntaxException();
					}
					$type = \pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEaseType::tryFrom(strtolower($args[$i + 2])) ?? throw new InvalidCommandSyntaxException();
					$ease = new \pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEase($type, (float) $args[$i + 1]);
					$i += 2;
					break;
				case "pos":
					if(!isset($args[$i + 3])){
						throw new InvalidCommandSyntaxException();
					}
					$position = new \pocketmine\math\Vector3(
						$this->getRelativeDouble($base->x, $sender, $args[$i + 1]),
						$this->getRelativeDouble($base->y, $sender, $args[$i + 2]),
						$this->getRelativeDouble($base->z, $sender, $args[$i + 3])
					);
					$i += 3;
					break;
				case "rot":
					if(!isset($args[$i + 2])){
						throw new InvalidCommandSyntaxException();
					}
					$rotation = new \pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionRotation((float) $args[$i + 1], (float) $args[$i + 2]);
					$i += 2;
					break;
				case "facing":
					if(!isset($args[$i + 1])){
						throw new InvalidCommandSyntaxException();
					}
					if(\pocketmine\command\utils\TargetSelector::isSelector($args[$i + 1])){
						$target = \pocketmine\command\utils\TargetSelector::select($sender, $args[$i + 1])[0] ?? null;
						$facing = $target?->getEyePos();
						$i += 1;
					}else{
						if(!isset($args[$i + 3])){
							throw new InvalidCommandSyntaxException();
						}
						$facing = new \pocketmine\math\Vector3(
							$this->getRelativeDouble($base->x, $sender, $args[$i + 1]),
							$this->getRelativeDouble($base->y, $sender, $args[$i + 2]),
							$this->getRelativeDouble($base->z, $sender, $args[$i + 3])
						);
						$i += 3;
					}
					break;
				case "default":
					$default = true;
					break;
				default:
					throw new InvalidCommandSyntaxException();
			}
		}
		return new \pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction($preset, $ease, $position, $rotation, $facing, null, null, $default, false);
	}
}
