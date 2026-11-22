<?php

/*
 * BetterPMMP - /tickingarea like vanilla Bedrock: areas that stay loaded and keep ticking without players.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\Server;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkTicker;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function spl_object_id;
use function strtolower;

/**
 * /tickingarea add <from: x y z> <to: x y z> [name] [preload]
 * /tickingarea add circle <center: x y z> <radius: chunks> [name] [preload]
 * /tickingarea remove <name | position>
 * /tickingarea remove_all
 * /tickingarea list [all-dimensions]
 * Vanilla limits: 10 areas per world, 100 chunks per area, circles up to radius 4.
 * Saved in betterpmmp_tickingareas.json.
 */
final class TickingareaCommand extends WorldEditingCommand{
	private const MAX_AREAS = 10;
	private const MAX_CHUNKS = 100;

	/** @var array<string, array<string, array{list<array{int, int}>}>>|null world => name => [chunks] */
	private static ?array $areas = null;
	/** @var array<string, array{ChunkLoader, ChunkTicker, string, int}> "world|name" => registration */
	private static array $active = [];

	public function __construct(){
		parent::__construct("tickingarea", "Add, remove, or list ticking areas.", "/tickingarea <add|remove|remove_all|list> ...");
		$this->setPermission(DefaultPermissionNames::COMMAND_TICKINGAREA);
	}

	private static function file() : string{
		return Path::join(Server::getInstance()->getDataPath(), "betterpmmp_tickingareas.json");
	}

	/** @return array<string, array<string, array{list<array{int, int}>}>> */
	private static function &areas() : array{
		if(self::$areas === null){
			self::$areas = [];
			if(file_exists(self::file())){
				$data = json_decode((string) file_get_contents(self::file()), true);
				if(is_array($data)){
					self::$areas = $data;
				}
			}
		}
		return self::$areas;
	}

	private static function save() : void{
		@file_put_contents(self::file(), json_encode(self::areas()));
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$worldName = $world->getFolderName();
		$areas = &self::areas();
		switch(strtolower($args[0])){
			case "add":
				$chunks = [];
				if(strtolower($args[1] ?? "") === "circle"){
					if(count($args) < 6){
						throw new InvalidCommandSyntaxException();
					}
					$center = $this->parsePosition($sender, $args, 2, $origin);
					$radius = (int) $args[5];
					if($radius < 1 || $radius > 4){
						return $this->error($sender, "The radius must be between 1 and 4 chunks");
					}
					$cx = ((int) $center->x) >> 4;
					$cz = ((int) $center->z) >> 4;
					for($x = -$radius; $x <= $radius; ++$x){
						for($z = -$radius; $z <= $radius; ++$z){
							if($x * $x + $z * $z <= $radius * $radius){
								$chunks[] = [$cx + $x, $cz + $z];
							}
						}
					}
					$name = $args[6] ?? null;
				}else{
					if(count($args) < 7){
						throw new InvalidCommandSyntaxException();
					}
					$a = $this->parsePosition($sender, $args, 1, $origin);
					$b = $this->parsePosition($sender, $args, 4, $origin);
					for($x = min((int) $a->x, (int) $b->x) >> 4; $x <= max((int) $a->x, (int) $b->x) >> 4; ++$x){
						for($z = min((int) $a->z, (int) $b->z) >> 4; $z <= max((int) $a->z, (int) $b->z) >> 4; ++$z){
							$chunks[] = [$x, $z];
						}
					}
					$name = $args[7] ?? null;
				}
				if(count($chunks) > self::MAX_CHUNKS){
					return $this->error($sender, "The area is too big (" . count($chunks) . " chunks, at most " . self::MAX_CHUNKS . ")");
				}
				if(count($areas[$worldName] ?? []) >= self::MAX_AREAS){
					return $this->error($sender, "There are already " . self::MAX_AREAS . " ticking areas in this world");
				}
				$name ??= "Area" . (count($areas[$worldName] ?? []) + 1);
				if(isset($areas[$worldName][$name])){
					return $this->error($sender, "A ticking area named " . $name . " already exists");
				}
				$areas[$worldName][$name] = [$chunks];
				self::save();
				self::activate($world, $name, $chunks);
				$sender->sendMessage("Added ticking area " . $name . " with " . count($chunks) . " chunks");
				return true;
			case "remove":
				if(!isset($args[1])){
					throw new InvalidCommandSyntaxException();
				}
				$removed = [];
				$target = null;
				if(count($args) >= 4){
					$p = $this->parsePosition($sender, $args, 1, $origin);
					$target = [((int) $p->x) >> 4, ((int) $p->z) >> 4];
				}
				foreach($areas[$worldName] ?? [] as $name => [$chunks]){
					$match = $target === null ? (string) $name === $args[1] : in_array($target, $chunks, true);
					if($match){
						$removed[] = (string) $name;
						self::deactivate($world, (string) $name);
						unset($areas[$worldName][$name]);
					}
				}
				self::save();
				$sender->sendMessage($removed === [] ? "§cNo ticking area found" : "Removed ticking area(s): " . implode(", ", $removed));
				return true;
			case "remove_all":
				foreach($areas[$worldName] ?? [] as $name => $_){
					self::deactivate($world, (string) $name);
				}
				$count = count($areas[$worldName] ?? []);
				unset($areas[$worldName]);
				self::save();
				$sender->sendMessage("Removed " . $count . " ticking area(s)");
				return true;
			case "list":
				$all = strtolower($args[1] ?? "") === "all-dimensions";
				foreach($areas as $wName => $list){
					if(!$all && $wName !== $worldName){
						continue;
					}
					foreach($list as $name => [$chunks]){
						$sender->sendMessage("- " . $name . " (" . $wName . "): " . count($chunks) . " chunks");
					}
				}
				return true;
		}
		throw new InvalidCommandSyntaxException();
	}

	/** @param list<array{int, int}> $chunks */
	private static function activate(World $world, string $name, array $chunks) : void{
		$key = $world->getFolderName() . "|" . $name;
		if(isset(self::$active[$key])){
			if(self::$active[$key][3] === spl_object_id($world)){
				return;
			}
			unset(self::$active[$key], self::$chunksOf[$key]); //the world was unloaded and loaded again
		}
		$loader = new class implements ChunkLoader{};
		$ticker = new ChunkTicker();
		$inArea = [];
		foreach($chunks as [$x, $z]){
			$inArea[World::chunkHash($x, $z)] = true;
		}
		foreach($chunks as [$x, $z]){
			//neighbours are loaded too, chunks only tick with all neighbours present
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					$world->registerChunkLoader($loader, $x + $dx, $z + $dz, true);
				}
			}
			$world->registerTickingChunk($ticker, $x, $z);
		}
		self::$active[$key] = [$loader, $ticker, $name, spl_object_id($world)];
		self::$chunksOf[$key] = $chunks;
	}

	/** @var array<string, list<array{int, int}>> */
	private static array $chunksOf = [];

	private static function deactivate(World $world, string $name) : void{
		$key = $world->getFolderName() . "|" . $name;
		if(!isset(self::$active[$key])){
			return;
		}
		[$loader, $ticker] = self::$active[$key];
		foreach(self::$chunksOf[$key] as [$x, $z]){
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					$world->unregisterChunkLoader($loader, $x + $dx, $z + $dz);
				}
			}
			$world->unregisterTickingChunk($ticker, $x, $z);
		}
		unset(self::$active[$key], self::$chunksOf[$key]);
	}

	/** Every few seconds: areas of worlds that are (re)loaded become active. */
	public static function tick(Server $server) : void{
		if($server->getTick() % 100 !== 0){
			return;
		}
		$areas = &self::areas();
		foreach($areas as $worldName => $list){
			$world = $server->getWorldManager()->getWorldByName((string) $worldName);
			foreach($list as $name => [$chunks]){
				$key = $worldName . "|" . $name;
				if($world === null){
					unset(self::$active[$key], self::$chunksOf[$key]); //unloaded world: register again when it is back
					continue;
				}
				self::activate($world, (string) $name, $chunks);
			}
		}
	}
}
