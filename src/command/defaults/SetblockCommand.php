<?php

/*
 * BetterPMMP - /setblock like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\block\BlockTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function strtolower;

/** /setblock <x> <y> <z> <block> [replace|destroy|keep] */
final class SetblockCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("setblock", "Changes a block to another block.", "/setblock <position: x y z> <block> [replace|destroy|keep]");
		$this->setPermission(DefaultPermissionNames::COMMAND_SETBLOCK);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 4){
			throw new InvalidCommandSyntaxException();
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$pos = $this->parsePosition($sender, $args, 0, $origin)->floor();
		$block = $this->parseBlock($args[3]);
		if($block === null){
			return $this->error($sender, "Unknown block: " . $args[3]);
		}
		$mode = strtolower($args[4] ?? "replace");
		$x = (int) $pos->x;
		$y = (int) $pos->y;
		$z = (int) $pos->z;
		if(!$world->isInWorld($x, $y, $z)){
			return $this->error($sender, "Cannot place block outside of the world");
		}
		if(!$this->loadArea($world, $x, $z, $x, $z)){
			return $this->error($sender, "Cannot place block outside of the world");
		}
		$current = $world->getBlockAt($x, $y, $z);
		if($mode === "keep" && $current->getTypeId() !== BlockTypeIds::AIR){
			return $this->error($sender, "Could not place the block");
		}
		if($current->getStateId() === $block->getStateId()){
			return $this->error($sender, "The block couldn't be placed");
		}
		if($mode === "destroy"){
			$world->useBreakOn($pos);
		}
		$world->setBlockAt($x, $y, $z, $block);
		Command::broadcastCommandMessage($sender, "Block placed");
		return true;
	}
}
