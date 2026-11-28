<?php

declare(strict_types=1);

namespace leondev\bettermultiworld;

use pocketmine\BetterPMMPConfig;
use pocketmine\block\Liquid;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\WorldManager;
use pocketmine\world\generator\flat\Flat;
use pocketmine\world\generator\vanilla\Overworld;
use pocketmine\world\generator\vanilla\VanillaEnd;
use pocketmine\world\generator\vanilla\VanillaNether;
use function array_keys;
use function array_map;
use function array_replace;
use function array_slice;
use function bin2hex;
use function class_exists;
use function copy;
use function count;
use function date;
use function filter_var;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function natcasesort;
use function preg_match;
use function random_bytes;
use function scandir;
use function str_contains;
use function str_ends_with;
use function strcasecmp;
use function strtolower;

final class Main extends PluginBase{

	private const PREFIX = "§b[MultiWorld] §r";

	private Config $worlds;
	private array $pending = [];

	protected function onLoad() : void{
		VoidGenerator::register();
	}

	protected function onEnable() : void{
		$this->worlds = new Config($this->getDataFolder() . "worlds.json", Config::JSON, ["worlds" => []]);
		foreach($this->records() as $name => $record){
			if(!($record["autoload"] ?? false)){
				continue;
			}
			try{
				if(!$this->manager()->loadWorld((string) $name)){
					$this->getLogger()->warning("Welt nicht geladen: " . $name);
				}
			}catch(\Throwable $e){
				$this->getLogger()->logException($e);
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($command->getName() === "mwtp"){
			$args = ["tp", ...$args];
		}
		try{
			$this->run($sender, $args);
		}catch(\DomainException $e){
			$sender->sendMessage(self::PREFIX . "§e" . $e->getMessage());
		}catch(\Throwable $e){
			$this->getLogger()->logException($e);
			$sender->sendMessage(self::PREFIX . "§cWelt-Aktion fehlgeschlagen. Details stehen im Serverlog, vorhandene Welten werden nicht ersetzt.");
		}
		return true;
	}

	private function run(CommandSender $sender, array $args) : void{
		$sub = strtolower($args[0] ?? "help");
		if($sub === "help" || $sub === "hilfe"){
			$sender->sendMessage(self::PREFIX . "§f/mw create NAME [normal|flat|nether|end|void] [SEED]\n/mw list | /mw info NAME | /mw load NAME | /mw unload NAME\n/mw tp NAME | /mwtp NAME | /mw setspawn | /mw save NAME | /mw farm NAME");
			return;
		}
		if($sub === "list" || $sub === "ls"){
			$this->list($sender);
			return;
		}
		if($sub === "setspawn"){
			$this->setSpawn($sender);
			return;
		}
		if($sub === "create" && strtolower($args[1] ?? "") === "void" && isset($args[2])){
			$args = [$sub, $args[2], "void", $args[3] ?? null];
		}
		$name = $sub === "create" ? FarmWorlds::name($args[1] ?? "") : $this->existing(implode(" ", array_slice($args, 1)));
		switch($sub){
			case "create":
				$default = strtolower($name) === "nether" || str_ends_with(strtolower($name), "_nether") ? "nether" : "normal";
				$this->create($sender, $name, $args[2] ?? $default, $args[3] ?? null);
				break;
			case "load":
				if(!$this->manager()->loadWorld($name)){
					throw new \DomainException("Welt konnte nicht geladen werden. /mw create erstellt neue Welten.");
				}
				if(isset($this->records()[$name])){
					$this->save($name, array_replace($this->own($name), ["autoload" => true]));
				}
				$sender->sendMessage(self::PREFIX . "§aWelt geladen: " . $name);
				break;
			case "unload":
				$this->unload($sender, $name);
				break;
			case "save":
				$this->own($name);
				$world = $this->manager()->getWorldByName($name) ?? throw new \DomainException("Welt zuerst laden.");
				$world->save(true);
				$sender->sendMessage(self::PREFIX . "§aWelt gespeichert: " . $name);
				break;
			case "farm":
				$record = $this->own($name);
				if(!in_array($record["type"], ["normal", "flat", "nether"], true)){
					throw new \DomainException("Dieser Welttyp ist keine Farmwelt.");
				}
				$this->farm($name);
				$sender->sendMessage(self::PREFIX . "§aFarmwelt eingetragen und BetterPMMP-Konfiguration neu geladen.");
				break;
			case "tp":
			case "teleport":
				if(!$sender instanceof Player){
					throw new \DomainException("Bitte im Spiel verwenden.");
				}
				$this->teleport($sender, $name);
				break;
			case "info":
				$this->info($sender, $name);
				break;
			default:
				throw new \DomainException("Unbekannter Unterbefehl. /mw help");
		}
	}

	private function manager() : WorldManager{
		return $this->getServer()->getWorldManager();
	}

	private function records() : array{
		return $this->worlds->get("worlds", []);
	}

	private function save(string $name, array $record) : void{
		$records = $this->records();
		$records[$name] = $record;
		$this->worlds->set("worlds", $records);
		$this->worlds->save();
	}

	private function own(string $name) : array{
		return $this->records()[$name] ?? throw new \DomainException("Diese Welt wird nicht von BetterMultiWorld verwaltet.");
	}

	private function available() : array{
		$names = [];
		foreach($this->manager()->getWorlds() as $world){
			$names[$world->getFolderName()] = true;
		}
		$root = $this->getServer()->getDataPath() . "worlds/";
		foreach(is_dir($root) ? (scandir($root) ?: []) : [] as $folder){
			if($folder === "." || $folder === ".." || !is_dir($root . $folder) || is_link($root . $folder)){
				continue;
			}
			if($this->manager()->isWorldGenerated($folder)){
				$names[$folder] = true;
			}
		}
		foreach(array_keys($this->records()) as $name){
			$names[(string) $name] = true;
		}
		$list = array_map("strval", array_keys($names));
		natcasesort($list);
		return $list;
	}

	private function existing(string $name) : string{
		if($name === "" || str_contains($name, "/") || str_contains($name, "\\") || str_contains($name, "\0") || $name === "." || $name === ".."){
			throw new \DomainException("Weltname ungültig. /mw list zeigt vorhandene Welten.");
		}
		$available = $this->available();
		foreach($available as $world){
			if($world === $name){
				return $world;
			}
		}
		foreach($available as $world){
			if(strcasecmp($world, $name) === 0){
				return $world;
			}
		}
		throw new \DomainException("Welt nicht gefunden. /mw list zeigt vorhandene Welten.");
	}

	private function list(CommandSender $sender) : void{
		$rows = [];
		foreach($this->available() as $name){
			$world = $this->manager()->getWorldByName($name);
			$rows[] = $world !== null ? "$name (geladen, " . count($world->getPlayers()) . " Spieler)" : "$name (nicht geladen)";
		}
		$sender->sendMessage(self::PREFIX . "§fWelten:\n" . implode("\n", $rows));
	}

	private function info(CommandSender $sender, string $name) : void{
		$world = $this->manager()->getWorldByName($name);
		$record = $this->records()[$name] ?? [];
		$sender->sendMessage(self::PREFIX . "§f$name | " . ($world !== null ? "geladen" : "entladen") . " | Typ: " . ($record["type"] ?? "extern") . " | Seed: " . ($record["seed"] ?? "extern") . " | Autoload: " . (($record["autoload"] ?? false) ? "an" : "aus"));
		if($world !== null && class_exists(BetterPMMPConfig::class)){
			$sender->sendMessage("§7Natürliches Mob-Spawning: " . (BetterPMMPConfig::mobSpawningEnabled($world) ? "an" : "aus") . " | Monster: " . (BetterPMMPConfig::spawns($world, "monsters") ? "an" : "aus") . " | Tiere: " . (BetterPMMPConfig::spawns($world, "animals") ? "an" : "aus"));
		}
	}

	private function setSpawn(CommandSender $sender) : void{
		if(!$sender instanceof Player){
			throw new \DomainException("Bitte im Spiel verwenden.");
		}
		$world = $sender->getWorld();
		$this->own($world->getFolderName());
		$position = $sender->getPosition();
		if(!$this->safe($world, $position)){
			throw new \DomainException("Stelle dich auf festen, sicheren Boden.");
		}
		$world->setSpawnLocation($position);
		$world->save(true);
		$sender->sendMessage(self::PREFIX . "§aWelt-Spawn gespeichert.");
	}

	private function unload(CommandSender $sender, string $name) : void{
		$record = $this->own($name);
		$world = $this->manager()->getWorldByName($name);
		if($world !== null){
			if($world === $this->manager()->getDefaultWorld() || count($world->getPlayers()) > 0){
				throw new \DomainException("Die Hauptwelt oder eine Welt mit Spielern kann nicht entladen werden.");
			}
			$world->save(true);
			if(!$this->manager()->unloadWorld($world)){
				throw new \DomainException("Entladen wurde abgelehnt.");
			}
		}
		$this->save($name, array_replace($record, ["autoload" => false]));
		$sender->sendMessage(self::PREFIX . "§aWelt entladen, die Dateien bleiben erhalten.");
	}

	private function create(CommandSender $sender, string $name, string $type, ?string $seed) : void{
		$type = strtolower($type);
		$class = match($type){
			"normal" => Overworld::class,
			"flat" => Flat::class,
			"nether" => VanillaNether::class,
			"end" => VanillaEnd::class,
			"void" => VoidGenerator::class,
			default => throw new \DomainException("Typ: normal, flat, nether, end oder void.")
		};
		if(($type !== "void" && !class_exists(BetterPMMPConfig::class)) || !class_exists($class)){
			throw new \DomainException("Dieser Welttyp benötigt BetterPMMP.");
		}
		foreach(scandir($this->getServer()->getDataPath() . "worlds/") ?: [] as $folder){
			if(strcasecmp($folder, $name) === 0){
				throw new \DomainException("Weltordner existiert bereits. Verwende /mw load " . $folder);
			}
		}
		if($this->manager()->isWorldGenerated($name) || isset($this->records()[$name])){
			throw new \DomainException("Welt existiert bereits.");
		}
		$options = WorldCreationOptions::create()->setGeneratorClass($class);
		if($seed !== null){
			$number = filter_var($seed, FILTER_VALIDATE_INT);
			if($number === false){
				throw new \DomainException("Seed muss eine ganze Zahl sein.");
			}
			$options->setSeed($number);
		}
		$options->setSpawnPosition(match($type){
			"normal" => Overworld::findSpawn($options->getSeed()),
			"nether" => new Vector3(0.5, 70, 0.5),
			"end" => new Vector3(100.5, 49, 0.5),
			"void" => new Vector3(0.5, 64, 0.5),
			default => new Vector3(0.5, 5, 0.5)
		});
		if(!$this->manager()->generateWorld($name, $options, false)){
			throw new \DomainException("Welt konnte nicht erstellt werden.");
		}
		$this->save($name, ["type" => $type, "seed" => $options->getSeed(), "autoload" => true]);
		$farm = in_array($type, ["normal", "nether"], true);
		if($farm){
			try{
				$this->farm($name);
			}catch(\Throwable $e){
				$this->getLogger()->logException($e);
				$sender->sendMessage(self::PREFIX . "§eWelt erstellt, aber die Mob-Konfiguration wurde nicht aktualisiert. Nach der Korrektur /mw farm $name ausführen.");
				return;
			}
		}
		$sender->sendMessage(self::PREFIX . "§aWelt $name erstellt" . ($farm ? " und für natürliche Mobs freigegeben" : "") . ". §f/mw tp $name");
	}

	private function farm(string $name) : void{
		$path = $this->getServer()->getDataPath();
		$file = $path . "betterpmmp.yml";
		if(!is_file($file) || !class_exists(BetterPMMPConfig::class)){
			throw new \DomainException("betterpmmp.yml fehlt im Serverordner.");
		}
		$config = new Config($file, Config::YAML);
		$next = FarmWorlds::enable($config->getAll(), $name);
		if($next !== $config->getAll()){
			$backup = $file . "." . date("Ymd-His") . "-" . bin2hex(random_bytes(3)) . ".bak";
			if(!copy($file, $backup)){
				throw new \RuntimeException("Sicherung von betterpmmp.yml fehlgeschlagen");
			}
			$config->setAll($next);
			$config->save();
		}
		BetterPMMPConfig::load($path, $this->manager()->getDefaultWorld()?->getFolderName() ?? "world");
	}

	private function safe(World $world, Vector3 $position) : bool{
		$x = $position->getFloorX();
		$y = $position->getFloorY();
		$z = $position->getFloorZ();
		if($y <= $world->getMinY() || $y >= $world->getMaxY() - 1){
			return false;
		}
		$below = $world->getBlockAt($x, $y - 1, $z);
		$feet = $world->getBlockAt($x, $y, $z);
		$head = $world->getBlockAt($x, $y + 1, $z);
		foreach([$below, $feet, $head] as $block){
			if($block instanceof Liquid || preg_match('/lava|fire|magma|cactus/i', $block->getName()) === 1){
				return false;
			}
		}
		return $below->isFullCube() && !$feet->isSolid() && !$head->isSolid();
	}

	private function teleport(Player $player, string $name) : void{
		if(!$this->manager()->loadWorld($name) || ($world = $this->manager()->getWorldByName($name)) === null){
			throw new \DomainException("Zielwelt konnte nicht geladen werden.");
		}
		$spawn = $world->getSpawnLocation();
		$token = new \stdClass();
		$id = $player->getId();
		$this->pending[$id] = $token;
		$player->sendMessage(self::PREFIX . "§7Welt-Spawn wird geladen. Bitte kurz stehen bleiben.");
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($id, $token, $player) : void{
			if(($this->pending[$id] ?? null) === $token){
				unset($this->pending[$id]);
				if($player->isConnected()){
					$player->sendMessage(self::PREFIX . "§eTeleport abgelaufen. /mw tp erneut versuchen.");
				}
			}
		}), 600);
		$world->orderChunkPopulation($spawn->getFloorX() >> 4, $spawn->getFloorZ() >> 4, null)->onCompletion(
			function() use ($player, $world, $name, $spawn, $id, $token) : void{
				if(($this->pending[$id] ?? null) !== $token){
					return;
				}
				unset($this->pending[$id]);
				if(!$player->isConnected() || !$player->isAlive() || !$player->hasPermission("bettermultiworld.command") || !$world->isLoaded()){
					return;
				}
				try{
					$target = $world->getSafeSpawn($spawn);
				}catch(\Throwable){
					$target = $spawn;
				}
				if(!$player->teleport($target)){
					$player->sendMessage(self::PREFIX . "§eTeleport wurde abgelehnt.");
					return;
				}
				if(!$this->safe($world, $target)){
					$player->setGamemode(GameMode::CREATIVE());
					$player->sendMessage(self::PREFIX . "§eKein sicherer Boden, Kreativmodus für die Weltbearbeitung aktiviert.");
				}
				$player->resetFallDistance();
				$player->sendMessage(self::PREFIX . "§aWillkommen in $name.");
			},
			function() use ($id, $token, $player) : void{
				if(($this->pending[$id] ?? null) === $token){
					unset($this->pending[$id]);
					if($player->isConnected()){
						$player->sendMessage(self::PREFIX . "§eWelt-Laden fehlgeschlagen. Bitte erneut versuchen.");
					}
				}
			}
		);
	}
}
