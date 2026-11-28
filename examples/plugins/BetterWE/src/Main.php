<?php

declare(strict_types=1);

namespace leondev\betterwe;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\ItemBlock;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\world\World;
use function array_column;
use function array_keys;
use function array_pop;
use function array_reverse;
use function array_search;
use function array_slice;
use function array_sum;
use function base64_decode;
use function base64_encode;
use function count;
use function end;
use function explode;
use function hrtime;
use function implode;
use function intdiv;
use function in_array;
use function is_callable;
use function ltrim;
use function min;
use function preg_match;
use function random_int;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;

final class Main extends PluginBase implements Listener{

	private const PREFIX = "§d[BetterWE] §r";
	private const HISTORY = 3;

	private array $selection = [];
	private array $clipboard = [];
	private array $jobs = [];
	private array $undo = [];
	private array $redo = [];
	private array $wandOff = [];

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn() => $this->tick()), 1);
	}

	protected function onDisable() : void{
		foreach(array_keys($this->jobs) as $key){
			$this->finish($key, true);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("Bitte im Spiel verwenden.");
			return true;
		}
		try{
			if($command->getName() === "itemid"){
				$this->itemId($sender);
			}else{
				$this->run($sender, ltrim($command->getName(), "/"), $args);
			}
		}catch(\DomainException|\InvalidArgumentException $e){
			$sender->sendMessage(self::PREFIX . "§e" . $e->getMessage());
		}
		return true;
	}

	private function itemId(Player $player) : void{
		$item = $player->getInventory()->getItemInHand();
		$id = ItemIds::name($item) ?? throw new \DomainException("Für diese Item-Variante ist keine Text-ID registriert.");
		$player->sendMessage(self::PREFIX . "§f" . $item->getName() . " §7| Item-ID: §b$id §7| Kurzform: §b" . substr($id, 10));
	}

	private function run(Player $player, string $command, array $args) : void{
		$key = $this->key($player);
		$world = $player->getWorld();
		if($command === "help"){
			$player->sendMessage("//wand | //pos1 //pos2 | //set BLOCK | //replace FROM TO | //walls BLOCK | //outline BLOCK | //copy //cut //paste [-a] | //rotate 90 | //flip x|y|z | //sphere BLOCK RADIUS | //cyl BLOCK RADIUS HEIGHT | //expand //contract //shift N RICHTUNG | //undo //redo //cancel");
			return;
		}
		if($command === "cancel"){
			$this->finish($key, true);
			return;
		}
		if(isset($this->jobs[$key])){
			throw new \DomainException($this->jobs[$key]["mode"] === "copy" ? "Kopieren läuft noch: " . $this->jobs[$key]["count"] . " Blöcke gelesen." : "Ein Auftrag läuft bereits. //cancel stoppt ihn.");
		}
		switch($command){
			case "wand":
				unset($this->wandOff[$key]);
				if(!$player->getInventory()->canAddItem(VanillaItems::WOODEN_AXE())){
					throw new \DomainException("Kein freier Inventarplatz.");
				}
				$player->getInventory()->addItem(VanillaItems::WOODEN_AXE());
				$player->sendMessage(self::PREFIX . "§aHolzaxt: Linksklick Pos1, Rechtsklick Pos2. //togglewand schaltet sie um.");
				return;
			case "togglewand":
				$this->wandOff[$key] = !($this->wandOff[$key] ?? false);
				$player->sendMessage(self::PREFIX . "§eAxt-Auswahl " . ($this->wandOff[$key] ? "aus" : "an"));
				return;
			case "pos1":
			case "pos2":
				$this->select($player, $command === "pos1" ? 0 : 1, $player->getPosition());
				return;
			case "desel":
				unset($this->selection[$key]);
				$player->sendMessage(self::PREFIX . "§aAuswahl gelöscht.");
				return;
			case "undo":
			case "redo":
				$this->history($player, $command);
				return;
			case "rotate":
			case "flip":
				$this->transform($player, $command, $args);
				return;
			case "paste":
				$this->paste($player, in_array("-a", $args, true));
				return;
			case "sphere":
			case "hsphere":
			case "cyl":
			case "hcyl":
				$pattern = $this->pattern($args[0] ?? "");
				$radius = $this->integer($args[1] ?? "", 1, 30);
				[$x, $y, $z] = $this->coords($player->getPosition());
				$sphere = str_contains($command, "sphere");
				$height = $sphere ? $radius * 2 + 1 : $this->integer($args[2] ?? "1", 1, 100);
				$bounds = [$x - $radius, $sphere ? $y - $radius : $y, $z - $radius, $x + $radius, $sphere ? $y + $radius : $y + $height - 1, $z + $radius];
				Region::validate($bounds, $world->getMinY(), $world->getMaxY());
				$this->start($player, Region::positions($bounds, $command), "edit", $pattern);
				return;
		}
		$bounds = $this->bounds($player);
		switch($command){
			case "size":
				$player->sendMessage(self::PREFIX . "§bAuswahl: " . Region::volume($bounds) . " Blöcke | " . ($bounds[3] - $bounds[0] + 1) . " × " . ($bounds[4] - $bounds[1] + 1) . " × " . ($bounds[5] - $bounds[2] + 1));
				return;
			case "expand":
			case "contract":
			case "shift":
				$this->resize($player, $command, $bounds, $args);
				return;
			case "copy":
			case "cut":
				$this->start($player, Region::positions($bounds), $command, $command === "cut" ? [[1, VanillaBlocks::AIR()->getStateId()]] : null);
				return;
			case "set":
			case "replace":
			case "walls":
			case "outline":
				$replace = $command === "replace" ? $this->block($args[0] ?? "")->getStateId() : null;
				$pattern = $this->pattern($args[$command === "replace" ? 1 : 0] ?? "");
				$shape = $command === "walls" || $command === "outline" ? $command : "box";
				$this->start($player, Region::positions($bounds, $shape), "edit", $pattern, $replace);
				return;
		}
		$player->sendMessage(self::PREFIX . "§eUnbekannter Befehl. //help");
	}

	private function key(Player $player) : string{
		return strtolower($player->getName());
	}

	private function coords(Position $position) : array{
		return [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
	}

	private function integer(string $raw, int $min, int $max) : int{
		if(preg_match('/^-?\d+$/D', $raw) !== 1 || ($number = (int) $raw) < $min || $number > $max){
			throw new \DomainException("Zahl zwischen $min und $max erforderlich.");
		}
		return $number;
	}

	private function block(string $text) : Block{
		if(in_array(strtolower($text), ["air", "minecraft:air"], true)){
			return VanillaBlocks::AIR();
		}
		if(str_starts_with($text, "state:")){
			return RuntimeBlockStateRegistry::getInstance()->fromStateId($this->integer(substr($text, 6), 0, PHP_INT_MAX));
		}
		$item = ItemIds::parse($text);
		if(!$item instanceof ItemBlock){
			throw new \DomainException("Unbekannter Block. Nutze die ID aus /id, zum Beispiel stone oder minecraft:stone.");
		}
		return $item->getBlock();
	}

	private function pattern(string $text) : array{
		$parts = explode(",", $text);
		if(count($parts) > 16){
			throw new \DomainException("Maximal 16 Blöcke pro Muster.");
		}
		$pattern = [];
		foreach($parts as $part){
			$fields = explode("%", $part, 2);
			$weight = count($fields) === 2 ? $this->integer($fields[0], 1, 1000) : 1;
			$pattern[] = [$weight, $this->block(end($fields))->getStateId()];
		}
		return $pattern;
	}

	private function select(Player $player, int $which, Position $position) : void{
		$key = $this->key($player);
		$world = $position->getWorld()->getFolderName();
		if(($this->selection[$key]["world"] ?? "") !== $world){
			$this->selection[$key] = ["world" => $world];
		}
		$coords = $this->coords($position);
		if(($this->selection[$key][$which] ?? null) === $coords){
			return;
		}
		$this->selection[$key][$which] = $coords;
		$player->sendMessage(self::PREFIX . "§dPos" . ($which + 1) . ": " . implode(", ", $coords));
	}

	private function bounds(Player $player) : array{
		$selection = $this->selection[$this->key($player)] ?? [];
		if(!isset($selection[0], $selection[1]) || ($selection["world"] ?? "") !== $player->getWorld()->getFolderName()){
			throw new \DomainException("Wähle Pos1 und Pos2 in dieser Welt: //pos1 und //pos2 oder die Holzaxt.");
		}
		$bounds = Region::bounds($selection[0], $selection[1]);
		Region::validate($bounds, $player->getWorld()->getMinY(), $player->getWorld()->getMaxY());
		return $bounds;
	}

	private function resize(Player $player, string $command, array $bounds, array $args) : void{
		$amount = $this->integer($args[0] ?? "", 1, 1000);
		$directions = ["east" => [0, 1], "west" => [0, -1], "up" => [1, 1], "down" => [1, -1], "south" => [2, 1], "north" => [2, -1]];
		$direction = strtolower($args[1] ?? "up");
		if(!isset($directions[$direction])){
			throw new \DomainException("Richtung: north, south, east, west, up oder down.");
		}
		[$axis, $sign] = $directions[$direction];
		if($command === "shift"){
			$bounds[$axis] += $amount * $sign;
			$bounds[$axis + 3] += $amount * $sign;
		}else{
			$index = $sign > 0 ? $axis + 3 : $axis;
			$bounds[$index] += $amount * $sign * ($command === "expand" ? 1 : -1);
		}
		if($bounds[0] > $bounds[3] || $bounds[1] > $bounds[4] || $bounds[2] > $bounds[5]){
			throw new \DomainException("Die Auswahl wäre leer.");
		}
		Region::validate($bounds, $player->getWorld()->getMinY(), $player->getWorld()->getMaxY());
		$this->selection[$this->key($player)] = ["world" => $player->getWorld()->getFolderName(), 0 => array_slice($bounds, 0, 3), 1 => array_slice($bounds, 3, 3)];
		$player->sendMessage(self::PREFIX . "§aAuswahl angepasst.");
	}

	private function history(Player $player, string $command) : void{
		$key = $this->key($player);
		$history = $command === "undo" ? ($this->undo[$key] ?? []) : ($this->redo[$key] ?? []);
		$entry = array_pop($history) ?? throw new \DomainException("Kein $command vorhanden.");
		if($entry["world"] !== $player->getWorld()->getFolderName()){
			throw new \DomainException("Wechsle zuerst in die Welt " . $entry["world"] . ".");
		}
		$changes = (static function() use ($entry) : \Generator{
			foreach(array_reverse($entry["changes"]) as $change){
				yield [$change[0], $change[1], $change[2], $change[3]];
			}
		})();
		$this->start($player, $changes, "edit", null, null, $command === "undo" ? "redo" : "undo");
		if($command === "undo"){
			$this->undo[$key] = $history;
		}else{
			$this->redo[$key] = $history;
		}
	}

	private function transform(Player $player, string $command, array $args) : void{
		$key = $this->key($player);
		if(!isset($this->clipboard[$key])){
			throw new \DomainException("Zuerst //copy verwenden.");
		}
		$turns = 0;
		$axis = strtolower($args[0] ?? "x");
		if($command === "rotate"){
			$angle = $this->integer($args[0] ?? "90", -1080, 1080);
			if($angle % 90 !== 0){
				throw new \DomainException("Drehung nur in 90-Grad-Schritten.");
			}
			$turns = intdiv((($angle % 360) + 360) % 360, 90);
		}elseif(!in_array($axis, ["x", "y", "z"], true)){
			throw new \DomainException("//flip x|y|z");
		}
		foreach($this->clipboard[$key] as &$entry){
			if($command === "rotate"){
				[$entry[0], $entry[1], $entry[2]] = Region::rotate([$entry[0], $entry[1], $entry[2]], $turns);
			}else{
				$index = array_search($axis, ["x", "y", "z"], true);
				$entry[$index] = -$entry[$index];
			}
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($entry[3]["state"]);
			if(is_callable([$block, "getFacing"]) && is_callable([$block, "setFacing"])){
				$facing = $block->getFacing();
				if($command === "rotate"){
					for($i = 0; $i < $turns; ++$i){
						$facing = [2 => 5, 5 => 3, 3 => 4, 4 => 2][$facing] ?? $facing;
					}
				}else{
					$pairs = match($axis){
						"x" => [4 => 5, 5 => 4],
						"z" => [2 => 3, 3 => 2],
						default => [0 => 1, 1 => 0]
					};
					$facing = $pairs[$facing] ?? $facing;
				}
				$block->setFacing($facing);
			}
			if($command === "rotate" && $turns % 2 === 1 && is_callable([$block, "getAxis"]) && is_callable([$block, "setAxis"])){
				if($block->getAxis() === Axis::X){
					$block->setAxis(Axis::Z);
				}elseif($block->getAxis() === Axis::Z){
					$block->setAxis(Axis::X);
				}
			}
			$entry[3]["state"] = $block->getStateId();
		}
		unset($entry);
		$player->sendMessage(self::PREFIX . "§aZwischenablage verändert.");
	}

	private function paste(Player $player, bool $skipAir) : void{
		$clipboard = $this->clipboard[$this->key($player)] ?? throw new \DomainException("Zuerst //copy verwenden.");
		$world = $player->getWorld();
		$origin = $this->coords($player->getPosition());
		$air = VanillaBlocks::AIR()->getStateId();
		$bottom = null;
		foreach($clipboard as $entry){
			if($skipAir && $entry[3]["state"] === $air){
				continue;
			}
			$bottom = $bottom === null ? $entry[1] : min($bottom, $entry[1]);
		}
		if($bottom === null){
			throw new \DomainException("Keine Blöcke zum Einfügen in der Zwischenablage.");
		}
		$origin[1] -= $bottom;
		foreach($clipboard as $entry){
			if($skipAir && $entry[3]["state"] === $air){
				continue;
			}
			$x = $origin[0] + $entry[0];
			$y = $origin[1] + $entry[1];
			$z = $origin[2] + $entry[2];
			Region::validate([$x, $y, $z, $x, $y, $z], $world->getMinY(), $world->getMaxY());
		}
		$blocks = (static function() use ($clipboard, $origin, $skipAir, $air) : \Generator{
			foreach($clipboard as $entry){
				if($skipAir && $entry[3]["state"] === $air){
					continue;
				}
				yield [$origin[0] + $entry[0], $origin[1] + $entry[1], $origin[2] + $entry[2], $entry[3]];
			}
		})();
		$this->start($player, $blocks);
	}

	private function wand(Player $player) : bool{
		return $player->hasPermission("betterwe.use") && !($this->wandOff[$this->key($player)] ?? false) && $player->getInventory()->getItemInHand()->getTypeId() === VanillaItems::WOODEN_AXE()->getTypeId();
	}

	/**
	 * @priority HIGHEST
	 * @handleCancelled
	 */
	public function onInteract(PlayerInteractEvent $event) : void{
		if($this->wand($event->getPlayer())){
			$event->cancel();
			$this->select($event->getPlayer(), $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK ? 1 : 0, $event->getBlock()->getPosition());
		}
	}

	/**
	 * @priority HIGHEST
	 * @handleCancelled
	 */
	public function onBreak(BlockBreakEvent $event) : void{
		if($this->wand($event->getPlayer())){
			$event->cancel();
			$this->select($event->getPlayer(), 0, $event->getBlock()->getPosition());
		}
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$this->finish($this->key($event->getPlayer()), true);
	}

	private function start(Player $player, \Generator $blocks, string $mode = "edit", ?array $pattern = null, ?int $replace = null, string $history = "new") : void{
		foreach($this->jobs as $job){
			if($job["world"] === $player->getWorld()){
				throw new \DomainException("In dieser Welt läuft bereits ein WorldEdit-Auftrag.");
			}
		}
		$this->jobs[$this->key($player)] = [
			"token" => new \stdClass(),
			"player" => $player,
			"world" => $player->getWorld(),
			"blocks" => $blocks,
			"mode" => $mode,
			"pattern" => $pattern,
			"replace" => $replace,
			"history" => $history,
			"changes" => [],
			"copy" => [],
			"origin" => $this->coords($player->getPosition()),
			"waiting" => false,
			"count" => 0
		];
		$player->sendMessage(self::PREFIX . "§aWorldEdit läuft in kleinen Schritten. //cancel zum Stoppen.");
	}

	private function snapshot(World $world, int $x, int $y, int $z) : array{
		$tile = $world->getTileAt($x, $y, $z);
		return [
			"state" => $world->getBlockAt($x, $y, $z)->getStateId(),
			"tile" => $tile === null ? null : base64_encode((new BigEndianNbtSerializer())->write(new TreeRoot($tile->saveNBT())))
		];
	}

	private function apply(World $world, int $x, int $y, int $z, array $snapshot) : void{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($snapshot["state"]);
		$tag = null;
		if($snapshot["tile"] !== null){
			$tag = (new BigEndianNbtSerializer())->read(base64_decode($snapshot["tile"], true))->mustGetCompoundTag();
			$tag->setInt("x", $x)->setInt("y", $y)->setInt("z", $z);
		}
		$world->getTileAt($x, $y, $z)?->close();
		$world->setBlockAt($x, $y, $z, $block);
		if($tag !== null && ($tile = $world->getTileAt($x, $y, $z)) !== null){
			$tile->readSaveData($tag);
			if(is_callable([$tile, "setDirty"])){
				$tile->setDirty();
			}
		}
	}

	private function tick() : void{
		$deadline = hrtime(true) + 8000000;
		foreach(array_keys($this->jobs) as $key){
			$job = &$this->jobs[$key];
			$player = $job["player"];
			$world = $job["world"];
			if(!$player->isConnected() || !$player->hasPermission("betterwe.use") || ($job["mode"] !== "copy" && $player->getWorld() !== $world) || !$world->isLoaded()){
				unset($job);
				$this->finish($key, true);
				continue;
			}
			if($job["waiting"]){
				unset($job);
				continue;
			}
			$ready = [];
			try{
				for($i = 0; $i < 4096 && $job["blocks"]->valid(); ++$i){
					if($i >= 256 && $i % 64 === 0 && hrtime(true) >= $deadline){
						break;
					}
					[$x, $y, $z, $desired] = $job["blocks"]->current();
					$chunk = ($x >> 4) . ":" . ($z >> 4);
					if(!isset($ready[$chunk])){
						$loaded = $world->getChunk($x >> 4, $z >> 4);
						if($loaded === null || !$loaded->isPopulated()){
							$job["waiting"] = true;
							$token = $job["token"];
							$world->requestChunkPopulation($x >> 4, $z >> 4, null)->onCompletion(
								function() use ($key, $token) : void{
									if(($this->jobs[$key]["token"] ?? null) === $token){
										$this->jobs[$key]["waiting"] = false;
									}
								},
								function() use ($key, $token) : void{
									if(($this->jobs[$key]["token"] ?? null) === $token){
										$this->finish($key, true);
									}
								}
							);
							break;
						}
						$ready[$chunk] = true;
					}
					$job["blocks"]->next();
					$before = $this->snapshot($world, $x, $y, $z);
					if($job["mode"] === "copy" || $job["mode"] === "cut"){
						$job["copy"][] = [$x - $job["origin"][0], $y - $job["origin"][1], $z - $job["origin"][2], $before];
					}
					if($job["mode"] !== "copy" && ($job["replace"] === null || $before["state"] === $job["replace"])){
						if($desired === null){
							$ticket = random_int(1, array_sum(array_column($job["pattern"], 0)));
							foreach($job["pattern"] as [$weight, $state]){
								$ticket -= $weight;
								if($ticket <= 0){
									$desired = ["state" => $state, "tile" => null];
									break;
								}
							}
						}
						if($desired !== $before){
							$job["changes"][] = [$x, $y, $z, $before, $desired];
							$this->apply($world, $x, $y, $z, $desired);
						}
					}
					++$job["count"];
				}
				$done = !$job["blocks"]->valid();
				unset($job);
				if($done){
					$this->finish($key, false);
				}
			}catch(\Throwable $e){
				unset($job);
				$player->sendMessage(self::PREFIX . "§cAuftrag gestoppt: " . $e->getMessage());
				$this->getLogger()->warning($e->getMessage());
				$this->finish($key, true);
			}
		}
	}

	private function finish(string $key, bool $cancelled) : void{
		$job = $this->jobs[$key] ?? null;
		if($job === null){
			return;
		}
		unset($this->jobs[$key]);
		$player = $job["player"];
		if(($job["mode"] === "copy" && !$cancelled) || ($job["mode"] === "cut" && $job["copy"] !== [])){
			$this->clipboard[$key] = $job["copy"];
		}
		if($job["mode"] === "copy"){
			if($player->isConnected()){
				$player->sendMessage(self::PREFIX . ($cancelled ? "§eKopieren abgebrochen. Die bisherige Zwischenablage bleibt erhalten." : "§aKopiert: " . count($job["copy"]) . " Blöcke. Mit //paste in jeder Welt einfügen."));
			}
			return;
		}
		if($job["changes"] !== []){
			$entry = ["world" => $job["world"]->getFolderName(), "changes" => $job["changes"]];
			if($job["history"] === "redo"){
				$this->redo[$key][] = $entry;
				$this->redo[$key] = array_slice($this->redo[$key], -self::HISTORY);
			}else{
				$this->undo[$key][] = $entry;
				$this->undo[$key] = array_slice($this->undo[$key], -self::HISTORY);
				if($job["history"] === "new"){
					$this->redo[$key] = [];
				}
			}
		}
		if($player->isConnected()){
			$player->sendMessage(self::PREFIX . ($cancelled ? "§eGestoppt" : "§aFertig") . " §7| " . $job["count"] . " Blöcke geprüft, " . count($job["changes"]) . " geändert. //undo macht Änderungen rückgängig.");
		}
	}
}
