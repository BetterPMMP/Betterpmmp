<?php

/*
 * BetterPMMP - /hud like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\network\mcpe\protocol\SetHudPacket;
use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function strtolower;

/** /hud <target> <hide|reset> [all|paperdoll|armor|tooltips|touch_controls|crosshair|hotbar|health|progress_bar|hunger|air_bubbles|horse_health|status_effects|item_text] */
final class HudCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("hud", "Changes the visibility of hud elements.", "/hud <target> <hide|reset> [hud_element]");
		$this->setPermission(DefaultPermissionNames::COMMAND_HUD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$visibility = match(strtolower($args[1])){
			"hide" => HudVisibility::HIDE,
			"reset" => HudVisibility::RESET,
			default => throw new InvalidCommandSyntaxException(),
		};
		$name = strtolower($args[2] ?? "all");
		$elements = match($name){
			"all" => HudElement::cases(),
			"paperdoll" => [HudElement::PAPER_DOLL],
			"armor" => [HudElement::ARMOR],
			"tooltips" => [HudElement::TOOLTIPS],
			"touch_controls" => [HudElement::TOUCH_CONTROLS],
			"crosshair" => [HudElement::CROSSHAIR],
			"hotbar" => [HudElement::HOTBAR],
			"health" => [HudElement::HEALTH],
			"progress_bar", "xp" => [HudElement::XP],
			"hunger", "food" => [HudElement::FOOD],
			"air_bubbles" => [HudElement::AIR_BUBBLES],
			"horse_health" => [HudElement::HORSE_HEALTH],
			"status_effects" => [HudElement::STATUS_EFFECTS],
			"item_text" => [HudElement::ITEM_TEXT],
			default => throw new InvalidCommandSyntaxException(),
		};
		$players = TargetSelector::selectPlayers($sender, $args[0]);
		if($players === []){
			$sender->sendMessage("§cNo targets matched selector");
			return true;
		}
		foreach($players as $player){
			$player->getNetworkSession()->sendDataPacket(SetHudPacket::create($elements, $visibility));
		}
		$sender->sendMessage("HUD changed for " . count($players) . " player(s)");
		return true;
	}
}
