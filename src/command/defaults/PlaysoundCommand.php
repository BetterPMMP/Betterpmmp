<?php

/*
 * BetterPMMP - /playsound like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function count;
use function max;

/** /playsound <sound> [player] [x y z] [volume] [pitch] [minimumVolume] */
final class PlaysoundCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("playsound", "Plays a sound.", "/playsound <sound> [player] [position: x y z] [volume] [pitch] [minimumVolume]");
		$this->setPermission(DefaultPermissionNames::COMMAND_PLAYSOUND);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$targets = isset($args[1]) ? TargetSelector::selectPlayers($sender, $args[1]) : ($sender instanceof Player ? [$sender] : []);
		if(count($targets) === 0){
			return $this->error($sender, "No targets matched selector");
		}
		$volume = isset($args[5]) ? max(0.0, (float) $args[5]) : 1.0;
		$pitch = isset($args[6]) ? max(0.0, (float) $args[6]) : 1.0;
		$minimum = isset($args[7]) ? max(0.0, (float) $args[7]) : 0.0;
		$played = 0;
		foreach($targets as $player){
			$pos = count($args) >= 5 ? $this->parsePosition($sender, $args, 2, $player->getPosition()) : $player->getPosition()->asVector3();
			//vanilla: audible up to 16 blocks per volume; listeners farther away hear it at the minimum volume
			$distance = $player->getPosition()->distance($pos);
			$range = max(1.0, $volume) * 16;
			if($distance > $range){
				if($minimum <= 0){
					continue;
				}
				$pos = $player->getPosition()->asVector3();
				$volume = $minimum;
			}
			$player->getNetworkSession()->sendDataPacket(PlaySoundPacket::create($args[0], $pos->x, $pos->y, $pos->z, $volume, $pitch, 0, false, null, null));
			++$played;
		}
		if($played === 0){
			return $this->error($sender, "Player is too far away to hear the sound");
		}
		$sender->sendMessage("Played sound '" . $args[0] . "' to $played player(s)");
		return true;
	}
}
