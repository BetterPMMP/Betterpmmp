<?php

/*
 * BetterPMMP - /structure like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\structure\McStructure;
use Symfony\Component\Filesystem\Path;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function max;
use function min;
use function mkdir;
use function mt_rand;
use function preg_match;
use function str_replace;
use function strtolower;
use function unlink;

/**
 * /structure save <name> <from: x y z> <to: x y z> [includeEntities] [memory|disk] [includeBlocks]
 * /structure load <name> <to: x y z> [0_degrees|90_degrees|180_degrees|270_degrees] [none|x|z|xz] [includeEntities] [includeBlocks] [waterlogged] [integrity] [seed]
 * /structure delete <name>
 * Files are .mcstructure (the format of the game) in "structures/" of the server folder ("ns:name" =
 * structures/ns/name.mcstructure); behavior_packs/<pack>/structures/ are searched when loading.
 * Vanilla limit: 64 x 384 x 64.
 */
final class StructureCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("structure", "Saves or loads a structure in the world.", "/structure <save|load|delete> <name> ...");
		$this->setPermission(DefaultPermissionNames::COMMAND_STRUCTURE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$name = strtolower($args[1]);
		if(preg_match('/^[a-z0-9_\-.]+(:[a-z0-9_\-.\/]+)?$/', $name) !== 1 || str_contains($name, "..")){
			return $this->error($sender, "Invalid structure name: " . $args[1]);
		}
		$dataPath = $sender->getServer()->getDataPath();
		$file = Path::join($dataPath, "structures", str_replace(":", "/", $name) . ".mcstructure");
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		switch(strtolower($args[0])){
			case "save":
				if(count($args) < 8){
					throw new InvalidCommandSyntaxException();
				}
				$a = $this->parsePosition($sender, $args, 2, $origin)->floor();
				$b = $this->parsePosition($sender, $args, 5, $origin)->floor();
				$min = new Vector3(min($a->x, $b->x), min($a->y, $b->y), min($a->z, $b->z));
				$max = new Vector3(max($a->x, $b->x), max($a->y, $b->y), max($a->z, $b->z));
				if($max->x - $min->x >= 64 || $max->y - $min->y >= 384 || $max->z - $min->z >= 64){
					return $this->error($sender, "The structure is too big (at most 64 x 384 x 64)");
				}
				if(!$world->isInWorld((int) $min->x, (int) $min->y, (int) $min->z) || !$world->isInWorld((int) $max->x, (int) $max->y, (int) $max->z) || !$this->loadArea($world, (int) $min->x, (int) $min->z, (int) $max->x, (int) $max->z)){
					return $this->error($sender, "Cannot save blocks outside of the world");
				}
				$includeEntities = strtolower($args[8] ?? "true") !== "false";
				$includeBlocks = strtolower($args[10] ?? "true") !== "false";
				$structure = McStructure::capture($world, $min, $max, $includeEntities, $includeBlocks);
				if(!is_dir(dirname($file)) && !@mkdir(dirname($file), 0777, true)){
					return $this->error($sender, "Could not create " . dirname($file));
				}
				if(@file_put_contents($file, $structure->write($min)) === false){
					return $this->error($sender, "Could not write " . $file);
				}
				Command::broadcastCommandMessage($sender, "Saved structure " . $name . " (" . $structure->sizeX . " x " . $structure->sizeY . " x " . $structure->sizeZ . ")");
				return true;
			case "load":
				if(count($args) < 5){
					throw new InvalidCommandSyntaxException();
				}
				$found = $this->find($dataPath, $name, $file);
				if($found === null){
					return $this->error($sender, "Structure " . $name . " not found");
				}
				try{
					$structure = McStructure::read((string) file_get_contents($found));
				}catch(\Throwable $e){
					return $this->error($sender, "Structure " . $name . " could not be read: " . $e->getMessage());
				}
				$at = $this->parsePosition($sender, $args, 2, $origin)->floor();
				$turns = match(strtolower($args[5] ?? "0_degrees")){
					"0_degrees" => 0,
					"90_degrees" => 1,
					"180_degrees" => 2,
					"270_degrees" => 3,
					default => throw new InvalidCommandSyntaxException(),
				};
				$mirror = strtolower($args[6] ?? "none");
				if($mirror !== "none" && $mirror !== "x" && $mirror !== "z" && $mirror !== "xz"){
					throw new InvalidCommandSyntaxException();
				}
				$includeEntities = strtolower($args[7] ?? "true") !== "false";
				$includeBlocks = strtolower($args[8] ?? "true") !== "false";
				$integrity = isset($args[10]) ? max(0.0, min(100.0, (float) $args[10])) / 100 : 1.0;
				$seed = isset($args[11]) ? crc32($args[11]) : mt_rand();
				[$sx, $sy, $sz] = $structure->rotatedSize($turns);
				if(!$this->loadArea($world, (int) $at->x, (int) $at->z, (int) $at->x + $sx - 1, (int) $at->z + $sz - 1)){
					return $this->error($sender, "Cannot place blocks outside of the world");
				}
				$placed = $structure->place($world, $at, $turns, $mirror, $includeEntities, $includeBlocks, $integrity, $seed);
				Command::broadcastCommandMessage($sender, "Loaded structure " . $name . " (" . $placed . " blocks)");
				return true;
			case "delete":
				if(!is_file($file)){
					return $this->error($sender, "Structure " . $name . " not found");
				}
				@unlink($file);
				Command::broadcastCommandMessage($sender, "Deleted structure " . $name);
				return true;
		}
		throw new InvalidCommandSyntaxException();
	}

	private function find(string $dataPath, string $name, string $file) : ?string{
		if(is_file($file)){
			return $file;
		}
		$relative = str_replace(":", "/", $name) . ".mcstructure";
		foreach(glob(Path::join($dataPath, "behavior_packs", "*", "structures"), GLOB_ONLYDIR) ?: [] as $dir){
			if(is_file($candidate = Path::join($dir, $relative))){
				return $candidate;
			}
		}
		return null;
	}
}
