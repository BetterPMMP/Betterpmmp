<?php

/*
 * BetterPMMP - shared helpers of the vanilla world commands (/setblock, /fill, /summon, /playsound ...).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;
use function cos;
use function deg2rad;
use function sin;
use function str_starts_with;
use function strtolower;
use function substr;

abstract class WorldEditingCommand extends VanillaCommand{

	/** Where the command runs: the sender's position, or the default world's spawn for the console. */
	protected function senderPosition(CommandSender $sender) : Position{
		if($sender instanceof \pocketmine\command\utils\ExecuteContext && $sender->position->isValid()){
			return $sender->position;
		}
		if($sender instanceof Entity){
			return $sender->getPosition();
		}
		$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){
			throw new InvalidCommandSyntaxException();
		}
		return $world->getSpawnLocation();
	}

	/**
	 * Three coordinates from $args starting at $offset: absolute, relative (~) or local (^ = left, up, forward).
	 * @param string[] $args
	 */
	protected function parsePosition(CommandSender $sender, array $args, int $offset, Vector3 $base) : Vector3{
		if(!isset($args[$offset], $args[$offset + 1], $args[$offset + 2])){
			throw new InvalidCommandSyntaxException();
		}
		$values = [$args[$offset], $args[$offset + 1], $args[$offset + 2]];
		if(str_starts_with($values[0], "^")){
			$left = self::number(substr($values[0], 1));
			$up = self::number(substr($values[1], 1));
			$forward = self::number(substr($values[2], 1));
			if($sender instanceof \pocketmine\command\utils\ExecuteContext){
				$yaw = deg2rad($sender->yaw);
				$pitch = deg2rad($sender->pitch);
				$eye = $sender->anchorHeight();
			}else{
				$yaw = $sender instanceof Entity ? deg2rad($sender->getLocation()->getYaw()) : 0.0;
				$pitch = $sender instanceof Entity ? deg2rad($sender->getLocation()->getPitch()) : 0.0;
				$eye = $sender instanceof Entity ? $sender->getEyeHeight() : 0.0;
			}
			//forward vector like the client: yaw 0 = south (+z)
			$fx = -sin($yaw) * cos($pitch);
			$fy = -sin($pitch);
			$fz = cos($yaw) * cos($pitch);
			$lx = cos($yaw);
			$lz = sin($yaw);
			return new Vector3(
				$base->x + $fx * $forward + $lx * $left,
				$base->y + $eye + $fy * $forward + $up,
				$base->z + $fz * $forward + $lz * $left
			);
		}
		return new Vector3(
			$this->getRelativeDouble($base->x, $sender, $values[0]),
			$this->getRelativeDouble($base->y, $sender, $values[1], World::Y_MIN, World::Y_MAX),
			$this->getRelativeDouble($base->z, $sender, $values[2])
		);
	}

	private static function number(string $value) : float{
		return $value === "" ? 0.0 : (float) $value;
	}

	/** A block from its name ("stone", "minecraft:oak_planks"); null if there is no such block. */
	protected function parseBlock(string $name) : ?Block{
		$name = strtolower($name);
		$item = StringToItemParser::getInstance()->parse($name);
		if($item === null){
			return null;
		}
		$block = $item->getBlock();
		$plain = str_starts_with($name, "minecraft:") ? substr($name, 10) : $name;
		if($block->getTypeId() === BlockTypeIds::AIR && $plain !== "air"){
			return null; //an item that is no block
		}
		return $block;
	}

	/** Makes sure the chunks of a block area exist (loads them); false if part of it is not generated. */
	protected function loadArea(World $world, int $minX, int $minZ, int $maxX, int $maxZ) : bool{
		for($cx = $minX >> 4; $cx <= $maxX >> 4; ++$cx){
			for($cz = $minZ >> 4; $cz <= $maxZ >> 4; ++$cz){
				if($world->loadChunk($cx, $cz) === null){
					return false;
				}
			}
		}
		return true;
	}

	protected function error(CommandSender $sender, string $message) : bool{
		$sender->sendMessage(TextFormat::RED . $message);
		return true;
	}
}
