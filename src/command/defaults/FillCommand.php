<?php

/*
 * BetterPMMP - /fill like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function max;
use function min;
use function strtolower;

/**
 * /fill <from: x y z> <to: x y z> <block> [replace [filter block]|destroy|keep|hollow|outline]
 * Vanilla limit: 32768 blocks.
 */
final class FillCommand extends WorldEditingCommand{
	private const MAX_BLOCKS = 32768;

	public function __construct(){
		parent::__construct("fill", "Fills all or parts of a region with a specific block.", "/fill <from: x y z> <to: x y z> <block> [replace [filter]|destroy|keep|hollow|outline]");
		$this->setPermission(DefaultPermissionNames::COMMAND_FILL);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 7){
			throw new InvalidCommandSyntaxException();
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$a = $this->parsePosition($sender, $args, 0, $origin)->floor();
		$b = $this->parsePosition($sender, $args, 3, $origin)->floor();
		$block = $this->parseBlock($args[6]);
		if($block === null){
			return $this->error($sender, "Unknown block: " . $args[6]);
		}
		$mode = strtolower($args[7] ?? "replace");
		$filter = null;
		if($mode === "replace" && isset($args[8])){
			$filter = $this->parseBlock($args[8]);
			if($filter === null){
				return $this->error($sender, "Unknown block: " . $args[8]);
			}
		}
		$minX = (int) min($a->x, $b->x);
		$minY = (int) min($a->y, $b->y);
		$minZ = (int) min($a->z, $b->z);
		$maxX = (int) max($a->x, $b->x);
		$maxY = (int) max($a->y, $b->y);
		$maxZ = (int) max($a->z, $b->z);
		$volume = ($maxX - $minX + 1) * ($maxY - $minY + 1) * ($maxZ - $minZ + 1);
		if($volume > self::MAX_BLOCKS){
			return $this->error($sender, "Too many blocks in the specified area ($volume > " . self::MAX_BLOCKS . ")");
		}
		if(!$world->isInWorld($minX, $minY, $minZ) || !$world->isInWorld($maxX, $maxY, $maxZ) || !$this->loadArea($world, $minX, $minZ, $maxX, $maxZ)){
			return $this->error($sender, "Cannot place blocks outside of the world");
		}
		$air = VanillaBlocks::AIR();
		$changed = 0;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					$edge = $x === $minX || $x === $maxX || $y === $minY || $y === $maxY || $z === $minZ || $z === $maxZ;
					$current = $world->getBlockAt($x, $y, $z);
					$target = $block;
					switch($mode){
						case "keep":
							if($current->getTypeId() !== BlockTypeIds::AIR){
								continue 2;
							}
							break;
						case "outline":
							if(!$edge){
								continue 2;
							}
							break;
						case "hollow":
							if(!$edge){
								$target = $air;
							}
							break;
						case "destroy":
							if($current->getTypeId() !== BlockTypeIds::AIR){
								$world->useBreakOn(new Vector3($x, $y, $z));
							}
							break;
						case "replace":
							//a filter without block states matches every state of that block, like on Bedrock
							if($filter !== null && $current->getTypeId() !== $filter->getTypeId()){
								continue 2;
							}
							break;
						default:
							throw new InvalidCommandSyntaxException();
					}
					if($current->getStateId() === $target->getStateId()){
						continue;
					}
					$world->setBlockAt($x, $y, $z, $target);
					++$changed;
				}
			}
		}
		if($changed === 0){
			return $this->error($sender, "No blocks were filled");
		}
		Command::broadcastCommandMessage($sender, "$changed blocks filled");
		return true;
	}
}
