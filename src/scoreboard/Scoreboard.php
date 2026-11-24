<?php

/*
 * BetterPMMP - the vanilla scoreboard (/scoreboard): objectives, scores and display slots.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\scoreboard;

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use pocketmine\player\Player;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function crc32;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rename;
use const JSON_PRETTY_PRINT;

/**
 * One scoreboard for the whole server, like Bedrock. Participants are player names or free names ("fake
 * players"); scores are 32-bit integers. Saved to betterpmmp_scoreboard.json in the server folder.
 * The sidebar, the pause-menu list and the score below player names are shown to every player.
 */
final class Scoreboard{
	public const SLOTS = ["sidebar", "list", "belowname"];

	private static ?self $instance = null;

	/** @var array<string, array{displayName: string, criteria: string}> */
	private array $objectives = [];
	/** @var array<string, array<string, int>> objective => participant => score */
	private array $scores = [];
	/** @var array<string, array{objective: string, order: int}> slot => display */
	private array $displays = [];

	private function __construct(private string $file){
		$this->load();
	}

	public static function get() : self{
		return self::$instance ??= new self(Path::join(Server::getInstance()->getDataPath(), "betterpmmp_scoreboard.json"));
	}

	private function load() : void{
		if(!file_exists($this->file)){
			return;
		}
		$data = json_decode((string) file_get_contents($this->file), true);
		if(!is_array($data)){
			return;
		}
		foreach(is_array($data["objectives"] ?? null) ? $data["objectives"] : [] as $name => $objective){
			if(is_string($name) && is_array($objective)){
				$this->objectives[$name] = ["displayName" => (string) ($objective["displayName"] ?? $name), "criteria" => (string) ($objective["criteria"] ?? "dummy")];
			}
		}
		foreach(is_array($data["scores"] ?? null) ? $data["scores"] : [] as $objective => $scores){
			if(isset($this->objectives[$objective]) && is_array($scores)){
				foreach($scores as $participant => $score){
					if(is_int($score)){
						$this->scores[$objective][(string) $participant] = $score;
					}
				}
			}
		}
		foreach(is_array($data["displays"] ?? null) ? $data["displays"] : [] as $slot => $display){
			if(is_array($display) && isset($this->objectives[$display["objective"] ?? ""])){
				$this->displays[(string) $slot] = ["objective" => (string) $display["objective"], "order" => (int) ($display["order"] ?? 1)];
			}
		}
	}

	private function save() : void{
		$tmp = $this->file . ".tmp";
		if(@file_put_contents($tmp, json_encode(["objectives" => $this->objectives, "scores" => $this->scores, "displays" => $this->displays], JSON_PRETTY_PRINT)) !== false){
			@rename($tmp, $this->file);
		}
	}

	public function hasObjective(string $name) : bool{ return isset($this->objectives[$name]); }

	/** @return array<string, array{displayName: string, criteria: string}> */
	public function getObjectives() : array{ return $this->objectives; }

	public function addObjective(string $name, string $criteria, string $displayName) : bool{
		if(isset($this->objectives[$name])){
			return false;
		}
		$this->objectives[$name] = ["displayName" => $displayName, "criteria" => $criteria];
		$this->scores[$name] = [];
		$this->save();
		return true;
	}

	public function removeObjective(string $name) : bool{
		if(!isset($this->objectives[$name])){
			return false;
		}
		foreach($this->displays as $slot => $display){
			if($display["objective"] === $name){
				unset($this->displays[$slot]);
			}
		}
		unset($this->objectives[$name], $this->scores[$name]);
		$this->broadcast(RemoveObjectivePacket::create($name));
		$this->save();
		return true;
	}

	public function setDisplay(string $slot, ?string $objective, int $order) : void{
		$old = $this->displays[$slot]["objective"] ?? null;
		if($objective === null){
			unset($this->displays[$slot]);
		}else{
			$this->displays[$slot] = ["objective" => $objective, "order" => $order];
		}
		if($old !== null && !$this->isDisplayed($old)){
			$this->broadcast(RemoveObjectivePacket::create($old));
		}
		foreach(Server::getInstance()->getOnlinePlayers() as $player){
			$this->sendTo($player);
		}
		$this->save();
	}

	private function isDisplayed(string $objective) : bool{
		foreach($this->displays as $display){
			if($display["objective"] === $objective){
				return true;
			}
		}
		return false;
	}

	/** @return array<string, array{objective: string, order: int}> */
	public function getDisplays() : array{ return $this->displays; }

	public function getScore(string $objective, string $participant) : ?int{
		return $this->scores[$objective][$participant] ?? null;
	}

	/** @return array<string, int> participant => score */
	public function getScores(string $objective) : array{
		return $this->scores[$objective] ?? [];
	}

	/** @return string[] everyone with at least one score */
	public function getParticipants() : array{
		$all = [];
		foreach($this->scores as $scores){
			foreach($scores as $participant => $score){
				$all[(string) $participant] = true;
			}
		}
		return array_keys($all);
	}

	public function setScore(string $objective, string $participant, int $score) : void{
		//vanilla scores are 32-bit and wrap around
		$score = (($score + 2147483648) & 0xffffffff) - 2147483648;
		$this->scores[$objective][$participant] = $score;
		if($this->isDisplayed($objective)){
			$entry = $this->entry($objective, $participant, $score);
			if(($identity = self::identities([$entry])) !== null){
				$this->broadcast($identity);
			}
			$this->broadcast(SetScorePacket::create([$entry]));
		}
		$this->save();
	}

	public function resetScore(string $participant, ?string $objective) : bool{
		$changed = false;
		foreach($objective === null ? array_keys($this->scores) : [$objective] as $name){
			if(isset($this->scores[$name][$participant])){
				unset($this->scores[$name][$participant]);
				$changed = true;
				if($this->isDisplayed($name)){
					$this->broadcast(SetScorePacket::create([$this->removal($name, $participant)]));
				}
			}
		}
		if($changed){
			$this->save();
		}
		return $changed;
	}

	/** Shows the displayed objectives to a player (on join and after a change). */
	public function sendTo(Player $player) : void{
		$session = $player->getNetworkSession();
		$sent = [];
		foreach($this->displays as $slot => $display){
			$name = $display["objective"];
			if(!isset($this->objectives[$name])){
				continue;
			}
			if(!isset($sent[$name])){
				$session->sendDataPacket(RemoveObjectivePacket::create($name));
			}
			$session->sendDataPacket(SetDisplayObjectivePacket::create($slot, $name, $this->objectives[$name]["displayName"], $this->objectives[$name]["criteria"], $display["order"]));
			if(!isset($sent[$name])){
				$entries = [];
				foreach($this->scores[$name] ?? [] as $participant => $score){
					$entries[] = $this->entry($name, (string) $participant, $score);
				}
				if($entries !== []){
					if(($identity = self::identities($entries)) !== null){
						$session->sendDataPacket($identity);
					}
					$session->sendDataPacket(SetScorePacket::create($entries));
				}
			}
			$sent[$name] = true;
		}
	}

	private function entry(string $objective, string $participant, int $score) : ScorePacketEntry{
		$entry = new ScorePacketEntry();
		$entry->scoreboardId = self::id($participant);
		$entry->objectiveName = $objective;
		$entry->score = $score;
		$player = Server::getInstance()->getPlayerExact($participant);
		if($player !== null){
			//a real player: the client shows the score below the name and uses the player's name
			$entry->action = ScorePacketEntryAction::CHANGE_PLAYER;
			$entry->actorUniqueId = $player->getId();
		}else{
			$entry->action = ScorePacketEntryAction::CHANGE_FAKE_PLAYER;
			$entry->customName = $participant;
		}
		return $entry;
	}

	private function removal(string $objective, string $participant) : ScorePacketEntry{
		$entry = new ScorePacketEntry();
		$entry->scoreboardId = self::id($participant);
		$entry->objectiveName = $objective;
		$entry->score = 0;
		$entry->action = ScorePacketEntryAction::REMOVE;
		return $entry;
	}

	/**
	 * Player entries need the player registered as scoreboard identity first (like the vanilla server does).
	 * @param ScorePacketEntry[] $entries
	 */
	private static function identities(array $entries) : ?\pocketmine\network\mcpe\protocol\SetScoreboardIdentityPacket{
		$list = [];
		foreach($entries as $entry){
			if($entry->action === ScorePacketEntryAction::CHANGE_PLAYER){
				$identity = new \pocketmine\network\mcpe\protocol\types\ScoreboardIdentityPacketEntry();
				$identity->scoreboardId = $entry->scoreboardId;
				$identity->actorUniqueId = $entry->actorUniqueId;
				$list[] = $identity;
			}
		}
		return $list === [] ? null : \pocketmine\network\mcpe\protocol\SetScoreboardIdentityPacket::create(\pocketmine\network\mcpe\protocol\SetScoreboardIdentityPacket::TYPE_REGISTER_IDENTITY, $list);
	}

	private static function id(string $participant) : int{
		return crc32($participant) + 1;
	}

	private function broadcast(\pocketmine\network\mcpe\protocol\ClientboundPacket $packet) : void{
		foreach(Server::getInstance()->getOnlinePlayers() as $player){
			$player->getNetworkSession()->sendDataPacket($packet);
		}
	}
}
