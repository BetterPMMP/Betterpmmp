<?php

/*
 * BetterPMMP - /clone like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function max;
use function min;
use function strtolower;

/**
 * /clone <begin: x y z> <end: x y z> <destination: x y z> [replace|masked] [normal|force|move]
 * Copies blocks with their contents (chests, signs ...). Vanilla limit: 32768 blocks.
 */
final class CloneCommand extends WorldEditingCommand{
	private const MAX_BLOCKS = 32768;

	public function __construct(){
		parent::__construct("clone", "Clones blocks from one region to another.", "/clone <begin: x y z> <end: x y z> <destination: x y z> [replace|masked] [normal|force|move]");
		$this->setPermission(DefaultPermissionNames::COMMAND_CLONE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 9){
			throw new InvalidCommandSyntaxException();
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$a = $this->parsePosition($sender, $args, 0, $origin)->floor();
		$b = $this->parsePosition($sender, $args, 3, $origin)->floor();
		$d = $this->parsePosition($sender, $args, 6, $origin)->floor();
		$mask = strtolower($args[9] ?? "replace");
		$clone = strtolower($args[10] ?? "normal");
		if(($mask !== "replace" && $mask !== "masked") || ($clone !== "normal" && $clone !== "force" && $clone !== "move")){
			throw new InvalidCommandSyntaxException();
		}
		$minX = (int) min($a->x, $b->x);
		$minY = (int) min($a->y, $b->y);
		$minZ = (int) min($a->z, $b->z);
		$maxX = (int) max($a->x, $b->x);
		$maxY = (int) max($a->y, $b->y);
		$maxZ = (int) max($a->z, $b->z);
		$sizeX = $maxX - $minX;
		$sizeY = $maxY - $minY;
		$sizeZ = $maxZ - $minZ;
		$volume = ($sizeX + 1) * ($sizeY + 1) * ($sizeZ + 1);
		if($volume > self::MAX_BLOCKS){
			return $this->error($sender, "Too many blocks in the specified area ($volume > " . self::MAX_BLOCKS . ")");
		}
		$dx = (int) $d->x;
		$dy = (int) $d->y;
		$dz = (int) $d->z;
		$overlaps = $dx <= $maxX && $dx + $sizeX >= $minX && $dy <= $maxY && $dy + $sizeY >= $minY && $dz <= $maxZ && $dz + $sizeZ >= $minZ;
		if($overlaps && $clone !== "force" && $clone !== "move"){
			return $this->error($sender, "Source and destination can not overlap");
		}
		foreach([[$minX, $minY, $minZ, $maxX, $maxY, $maxZ], [$dx, $dy, $dz, $dx + $sizeX, $dy + $sizeY, $dz + $sizeZ]] as [$x1, $y1, $z1, $x2, $y2, $z2]){
			if(!$world->isInWorld($x1, $y1, $z1) || !$world->isInWorld($x2, $y2, $z2) || !$this->loadArea($world, $x1, $z1, $x2, $z2)){
				return $this->error($sender, "Cannot access blocks outside of the world");
			}
		}

		//read everything first (source and destination may overlap)
		/** @var list<array{int, int, int, Block, ?CompoundTag}> $copy */
		$copy = [];
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$block = $world->getBlockAt($x, $y, $z);
					if($mask === "masked" && $block->getTypeId() === BlockTypeIds::AIR){
						continue;
					}
					$tile = $world->getTileAt($x, $y, $z);
					$copy[] = [$x - $minX, $y - $minY, $z - $minZ, $block, $tile?->saveNBT()];
				}
			}
		}
		if($clone === "move"){
			$air = VanillaBlocks::AIR();
			foreach($copy as [$ox, $oy, $oz]){
				$tile = $world->getTileAt($minX + $ox, $minY + $oy, $minZ + $oz);
				if($tile !== null && $tile instanceof \pocketmine\block\tile\Container){
					$tile->getRealInventory()->clearAll(); //the contents move, they are not dropped
				}
				$world->setBlockAt($minX + $ox, $minY + $oy, $minZ + $oz, $air, false);
			}
		}
		$count = 0;
		foreach($copy as [$ox, $oy, $oz, $block, $tileNbt]){
			$x = $dx + $ox;
			$y = $dy + $oy;
			$z = $dz + $oz;
			$old = $world->getTileAt($x, $y, $z);
			if($old instanceof \pocketmine\block\tile\Container){
				$old->getRealInventory()->clearAll(); //replaced, not dropped
			}
			$world->setBlockAt($x, $y, $z, $block, false);
			if($tileNbt !== null){
				$created = $world->getTileAt($x, $y, $z);
				if($created !== null){
					$world->removeTile($created);
					$created->close();
				}
				$tileNbt->setInt(Tile::TAG_X, $x)->setInt(Tile::TAG_Y, $y)->setInt(Tile::TAG_Z, $z);
				try{
					$tile = TileFactory::getInstance()->createFromData($world, $tileNbt);
					if($tile !== null){
						$world->addTile($tile);
					}
				}catch(\Throwable){
					//contents that cannot be copied are left out
				}
			}
			++$count;
		}
		//neighbours (redstone, liquids, falling blocks) react once everything is in place
		for($x = $dx; $x <= $dx + $sizeX; ++$x){
			for($y = $dy; $y <= $dy + $sizeY; ++$y){
				for($z = $dz; $z <= $dz + $sizeZ; ++$z){
					$world->notifyNeighbourBlockUpdate(new \pocketmine\math\Vector3($x, $y, $z));
				}
			}
		}
		if($count === 0){
			return $this->error($sender, "No blocks cloned");
		}
		Command::broadcastCommandMessage($sender, "$count blocks cloned");
		return true;
	}
}
