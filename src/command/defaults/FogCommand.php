<?php

/*
 * BetterPMMP - /fog like vanilla Bedrock (fog settings of resource packs).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\network\mcpe\protocol\PlayerFogPacket;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function array_column;
use function array_splice;
use function count;
use function strtolower;

/**
 * /fog <victim> push <fogId> <userProvidedId>
 * /fog <victim> <pop|remove> <userProvidedId>
 * Every player has a stack of fog settings; the client shows the top one.
 */
final class FogCommand extends VanillaCommand{

	/** @var array<int, list<array{string, string}>> player id => [fog id, user id] */
	private static array $stacks = [];

	public function __construct(){
		parent::__construct("fog", "Add or remove fog settings file.", "/fog <victim> <push|pop|remove> <fogId|userProvidedId> [userProvidedId]");
		$this->setPermission(DefaultPermissionNames::COMMAND_FOG);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 3){
			throw new InvalidCommandSyntaxException();
		}
		$players = TargetSelector::selectPlayers($sender, $args[0]);
		if($players === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		$mode = strtolower($args[1]);
		foreach($players as $player){
			$stack = self::$stacks[$player->getId()] ?? [];
			if($mode === "push"){
				if(!isset($args[3])){
					throw new InvalidCommandSyntaxException();
				}
				$stack[] = [$args[2], $args[3]];
			}elseif($mode === "pop" || $mode === "remove"){
				for($i = count($stack) - 1; $i >= 0; --$i){
					if($stack[$i][1] === $args[2]){
						array_splice($stack, $i, 1);
						if($mode === "pop"){
							break; //pop removes the newest, remove all with that id
						}
					}
				}
			}else{
				throw new InvalidCommandSyntaxException();
			}
			self::$stacks[$player->getId()] = $stack;
			self::send($player);
		}
		$sender->sendMessage("Fog settings changed for " . count($players) . " player(s)");
		return true;
	}

	public static function send(Player $player) : void{
		$player->getNetworkSession()->sendDataPacket(PlayerFogPacket::create(array_column(self::$stacks[$player->getId()] ?? [], 0)));
	}
}
