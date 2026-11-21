<?php

/*
 * BetterPMMP - RCON server (Source RCON protocol over TCP), runs on the main thread.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\BetterPMMPConfig;
use pocketmine\console\CapturingConsoleSender;
use pocketmine\console\menu\ConsoleApproveForm;
use pocketmine\console\menu\ConsoleMenu;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function random_int;
use function str_starts_with;
use function strtolower;
use function count;
use function feof;
use function fclose;
use function fread;
use function fwrite;
use function hash_equals;
use function in_array;
use function max;
use function is_resource;
use function microtime;
use function pack;
use function strlen;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_server;
use function strrpos;
use function substr;
use function trim;
use function unpack;
use const JSON_PRETTY_PRINT;

/**
 * Lets external tools (BetterPMMP-Konsole.exe or any RCON client) run console commands.
 * Packets: int32 size, int32 id, int32 type, body, two NUL bytes (little endian).
 * Login: a pairing code from /console koppeln (gives a token the client keeps), that token,
 * or rcon.password from betterpmmp.yml (optional, at least 8 characters).
 */
final class Rcon{

	private const TYPE_AUTH = 3;
	private const TYPE_EXEC = 2;
	private const TYPE_AUTH_RESPONSE = 2;
	private const TYPE_RESPONSE = 0;
	private const MAX_PACKET = 4096;
	private const MAX_RESPONSE_CHUNK = 4000;
	private const AUTH_TIMEOUT = 10.0;
	private const APPROVE_TIMEOUT = 60.0;
	private const IDLE_TIMEOUT = 600.0;

	/** @var resource|null */
	private static $socket = null;
	private static string $password = "";
	/** @var string[] */
	private static array $allowedIps = [];
	private static int $maxConnections = 5;
	/** @var array<int, array{conn: resource, ip: string, buf: string, auth: bool, since: float, last: float, player?: string, pending?: int}> */
	private static array $clients = [];
	private static int $nextId = 0;
	private static ?CapturingConsoleSender $sender = null;

	private const CODE_LIFETIME = 300.0;
	private const MAX_FAILS = 5;
	private const BLOCK_SECONDS = 300.0;
	private static string $tokenFile = "";
	/** @var array<string, string> token hash => player name */
	private static array $tokens = [];
	/** @var array<string, array{string, float}> code => [player name, expires] */
	private static array $codes = [];
	/** @var array<string, array{int, float}> ip => [failed logins, blocked until] */
	private static array $fails = [];

	public static function isRunning() : bool{
		return self::$socket !== null;
	}

	public static function port() : int{
		return BetterPMMPConfig::int("rcon", "port", 19140);
	}

	/** A new 6-digit pairing code for this player (valid 5 minutes, used once). */
	public static function createPairingCode(string $player) : string{
		$now = microtime(true);
		foreach(self::$codes as $code => [, $expires]){
			if($expires < $now){
				unset(self::$codes[$code]);
			}
		}
		do{
			$code = (string) random_int(100000, 999999);
		}while(isset(self::$codes[$code]));
		self::$codes[$code] = [$player, $now + self::CODE_LIFETIME];
		return $code;
	}

	/** Removes every pairing of this player; returns how many. */
	public static function unpair(string $player) : int{
		$n = 0;
		foreach(self::$tokens as $hash => $name){
			if(strtolower($name) === strtolower($player)){
				unset(self::$tokens[$hash]);
				++$n;
			}
		}
		self::saveTokens();
		return $n;
	}

	private static function loadTokens() : void{
		self::$tokens = [];
		$raw = @file_get_contents(self::$tokenFile);
		if($raw === false){
			return;
		}
		$data = json_decode($raw, true);
		if(is_array($data)){
			foreach($data as $hash => $name){
				if(is_string($hash) && is_string($name)){
					self::$tokens[$hash] = $name;
				}
			}
		}
	}

	private static function saveTokens() : void{
		if(self::$tokenFile !== ""){
			@file_put_contents(self::$tokenFile, json_encode(self::$tokens, JSON_PRETTY_PRINT));
		}
	}

	/**
	 * Checks a login. Accepts the rcon.password, "pair:<code>" (answers with a new token)
	 * or "token:<token>". Returns [ok, player name or "", text sent back to the client].
	 * @return array{bool, string, string}
	 */
	private static function login(string $body) : array{
		if(self::$password !== "" && hash_equals(self::$password, $body)){
			return [true, "", ""];
		}
		if(str_starts_with($body, "pair:")){
			$code = trim(substr($body, 5));
			$entry = self::$codes[$code] ?? null;
			if($entry === null || $entry[1] < microtime(true)){
				return [false, "", ""];
			}
			unset(self::$codes[$code]);
			$token = bin2hex(random_bytes(24));
			self::$tokens[hash("sha256", $token)] = $entry[0];
			self::saveTokens();
			return [true, $entry[0], "token:" . $token . "\nplayer:" . $entry[0]];
		}
		if(str_starts_with($body, "token:")){
			$name = self::$tokens[hash("sha256", trim(substr($body, 6)))] ?? null;
			if($name !== null){
				return [true, $name, "player:" . $name];
			}
		}
		return [false, "", ""];
	}

	public static function start(Server $server) : void{
		if(!BetterPMMPConfig::flag("rcon", "enabled", true)){
			return;
		}
		$password = BetterPMMPConfig::string("rcon", "password", "");
		$logger = $server->getLogger();
		if($password !== "" && strlen($password) < 8){
			$logger->warning("[RCON] rcon.password ist kürzer als 8 Zeichen und wird ignoriert - Anmeldung nur per Kopplung (/console koppeln).");
			$password = "";
		}
		self::$tokenFile = Path::join($server->getDataPath(), "konsole-kopplungen.json");
		self::loadTokens();
		$port = BetterPMMPConfig::int("rcon", "port", 19140);
		$errno = 0;
		$errstr = "";
		$socket = @stream_socket_server("tcp://0.0.0.0:" . $port, $errno, $errstr);
		if($socket === false){
			$logger->warning("[RCON] Port $port (TCP) konnte nicht geöffnet werden: $errstr");
			return;
		}
		stream_set_blocking($socket, false);
		self::$socket = $socket;
		self::$password = $password;
		self::$allowedIps = [];
		foreach(BetterPMMPConfig::list("rcon", "allowed-ips") as $ip){
			self::$allowedIps[] = trim((string) $ip);
		}
		self::$maxConnections = max(1, BetterPMMPConfig::int("rcon", "max-connections", 5));
		$logger->info("[RCON] läuft auf TCP-Port $port (BetterPMMP-Konsole.exe: im Spiel /console koppeln)");
	}

	public static function stop() : void{
		foreach(self::$clients as $id => $client){
			self::close($id);
		}
		if(is_resource(self::$socket)){
			fclose(self::$socket);
		}
		self::$socket = null;
	}

	/** Called every server tick: accept, read and answer without blocking. */
	public static function tick(Server $server) : void{
		if(self::$socket === null){
			return;
		}
		self::accept($server);
		$now = microtime(true);
		foreach(self::$clients as $id => $client){
			if(isset($client["pending"])){
				if($now - $client["since"] > self::APPROVE_TIMEOUT){
					self::deny($id, "Im Spiel nicht rechtzeitig bestätigt.");
				}
				continue;
			}
			if((!$client["auth"] && $now - $client["since"] > self::AUTH_TIMEOUT) || $now - $client["last"] > self::IDLE_TIMEOUT){
				self::close($id);
				continue;
			}
			$data = @fread($client["conn"], 65536);
			if($data === false || ($data === "" && feof($client["conn"]))){
				self::close($id);
				continue;
			}
			if($data === ""){
				continue;
			}
			self::$clients[$id]["buf"] .= $data;
			self::$clients[$id]["last"] = $now;
			self::handleBuffer($server, $id);
		}
	}

	private static function accept(Server $server) : void{
		for($i = 0; $i < 5; ++$i){
			$read = [self::$socket];
			$write = null;
			$except = null;
			if(@stream_select($read, $write, $except, 0) < 1){
				return;
			}
			$conn = @stream_socket_accept(self::$socket, 0, $peer);
			if($conn === false){
				return;
			}
			$pos = strrpos((string) $peer, ":");
			$ip = trim($pos === false ? (string) $peer : substr((string) $peer, 0, $pos), "[]");
			if((count(self::$allowedIps) > 0 && !in_array($ip, self::$allowedIps, true)) || count(self::$clients) >= self::$maxConnections){
				$server->getLogger()->info("[RCON] Verbindung von $ip abgelehnt");
				fclose($conn);
				continue;
			}
			stream_set_blocking($conn, false);
			$now = microtime(true);
			self::$clients[self::$nextId++] = ["conn" => $conn, "ip" => $ip, "buf" => "", "auth" => false, "since" => $now, "last" => $now];
		}
	}

	private static function handleBuffer(Server $server, int $id) : void{
		while(isset(self::$clients[$id])){
			$buf = self::$clients[$id]["buf"];
			if(strlen($buf) < 4){
				return;
			}
			$size = unpack("V", substr($buf, 0, 4))[1];
			if($size >= 0x80000000){
				$size -= 0x100000000;
			}
			if($size < 10 || $size > self::MAX_PACKET){
				self::close($id);
				return;
			}
			if(strlen($buf) < 4 + $size){
				return;
			}
			self::$clients[$id]["buf"] = substr($buf, 4 + $size);
			$fields = unpack("Vid/Vtype", substr($buf, 4, 8));
			$requestId = $fields["id"] >= 0x80000000 ? $fields["id"] - 0x100000000 : $fields["id"];
			$type = $fields["type"];
			$body = substr($buf, 12, $size - 10);
			self::handlePacket($server, $id, $requestId, $type, $body);
		}
	}

	private static function handlePacket(Server $server, int $id, int $requestId, int $type, string $body) : void{
		$client = self::$clients[$id];
		if($type === self::TYPE_AUTH && trim($body) === "auto"){
			self::autoLogin($server, $id, $requestId);
			return;
		}
		if($type === self::TYPE_AUTH){
			$ip = $client["ip"];
			$now = microtime(true);
			if(isset(self::$fails[$ip]) && self::$fails[$ip][1] > $now){
				self::send($id, -1, self::TYPE_AUTH_RESPONSE, "");
				self::close($id);
				return;
			}
			[$ok, $player, $reply] = self::login($body);
			if($ok){
				unset(self::$fails[$ip]);
				self::$clients[$id]["auth"] = true;
				self::$clients[$id]["player"] = $player;
				self::send($id, $requestId, self::TYPE_RESPONSE, $reply);
				self::send($id, $requestId, self::TYPE_AUTH_RESPONSE, "");
				$server->getLogger()->info("[RCON] $ip angemeldet" . ($player !== "" ? " (gekoppelt mit $player)" : ""));
			}else{
				$count = (self::$fails[$ip][0] ?? 0) + 1;
				self::$fails[$ip] = [$count, $count >= self::MAX_FAILS ? $now + self::BLOCK_SECONDS : 0.0];
				if($count >= self::MAX_FAILS){
					self::$fails[$ip][0] = 0;
				}
				self::send($id, -1, self::TYPE_AUTH_RESPONSE, "");
				$server->getLogger()->warning("[RCON] falsche Anmeldung von $ip");
				self::close($id);
			}
			return;
		}
		if(!$client["auth"]){
			self::close($id);
			return;
		}
		if($type !== self::TYPE_EXEC){
			self::send($id, $requestId, self::TYPE_RESPONSE, "");
			return;
		}
		$command = trim($body);
		if($command !== "" && $command[0] === "/"){
			$command = substr($command, 1);
		}
		$output = "";
		if($command !== ""){
			$who = ($client["player"] ?? "") !== "" ? $client["player"] . " (Konsole.exe)" : $client["ip"];
			$server->getLogger()->info("[RCON] $who führt aus: " . $command);
			self::$sender ??= new CapturingConsoleSender($server, $server->getLanguage());
			self::$sender->takeOutput();
			if(!$server->dispatchCommand(self::$sender, $command)){
				self::$sender->sendMessage(TextFormat::RED . "Unbekannter Befehl oder abgebrochen.");
			}
			$output = self::$sender->takeOutput();
		}
		$len = strlen($output);
		if($len === 0){
			self::send($id, $requestId, self::TYPE_RESPONSE, "");
		}
		for($off = 0; $off < $len; $off += self::MAX_RESPONSE_CHUNK){
			self::send($id, $requestId, self::TYPE_RESPONSE, substr($output, $off, self::MAX_RESPONSE_CHUNK));
		}
	}

	/**
	 * Login without a code: an online player with console rights plays from the same IP as the program.
	 * That player confirms once in the game (unless rcon.auto-login-ask is false); then the program gets a token.
	 */
	private static function autoLogin(Server $server, int $id, int $requestId) : void{
		$ip = self::$clients[$id]["ip"];
		if(!BetterPMMPConfig::flag("rcon", "auto-login", true)){
			self::deny($id, "Automatische Anmeldung ist aus (rcon.auto-login) - im Spiel /console koppeln.", $requestId);
			return;
		}
		$ask = BetterPMMPConfig::flag("rcon", "auto-login-ask", true);
		$candidates = [];
		foreach($server->getOnlinePlayers() as $player){
			if($player->getNetworkSession()->getIp() !== $ip || !ConsoleMenu::canUse($player)){
				continue;
			}
			if(!$ask && JavaBridge::isJavaPlayer($player)){
				continue; // bridge players all come from 127.0.0.1
			}
			$candidates[] = $player;
		}
		if(count($candidates) === 0){
			self::deny($id, "Kein OP-Spieler von deiner IP online. Geh erst auf den Server (mit OP), dann nochmal verbinden.", $requestId);
			return;
		}
		if(!$ask && count($candidates) === 1){
			self::approve($server, $id, $requestId, $candidates[0]->getName());
			return;
		}
		self::$clients[$id]["pending"] = $requestId;
		self::$clients[$id]["since"] = microtime(true);
		foreach($candidates as $player){
			$player->sendForm(new ConsoleApproveForm($id, $ip));
		}
		$server->getLogger()->info("[RCON] $ip will sich ohne Code anmelden - warte auf Bestätigung im Spiel");
	}

	/** Answer from the in-game popup. */
	public static function answerApproval(int $id, string $player, bool $allow) : void{
		if(!isset(self::$clients[$id]["pending"])){
			return;
		}
		$requestId = self::$clients[$id]["pending"];
		unset(self::$clients[$id]["pending"]);
		if($allow){
			self::approve(Server::getInstance(), $id, $requestId, $player);
		}else{
			self::deny($id, "$player hat die Anmeldung im Spiel abgelehnt.", $requestId);
		}
	}

	private static function approve(Server $server, int $id, int $requestId, string $player) : void{
		$token = bin2hex(random_bytes(24));
		self::$tokens[hash("sha256", $token)] = $player;
		self::saveTokens();
		self::$clients[$id]["auth"] = true;
		self::$clients[$id]["player"] = $player;
		self::$clients[$id]["last"] = microtime(true);
		self::send($id, $requestId, self::TYPE_RESPONSE, "token:" . $token . "\nplayer:" . $player);
		self::send($id, $requestId, self::TYPE_AUTH_RESPONSE, "");
		$server->getLogger()->info("[RCON] " . self::$clients[$id]["ip"] . " ohne Code angemeldet (gekoppelt mit $player)");
	}

	private static function deny(int $id, string $reason, ?int $requestId = null) : void{
		$requestId ??= self::$clients[$id]["pending"] ?? 0;
		self::send($id, $requestId, self::TYPE_RESPONSE, "error:" . $reason);
		self::send($id, -1, self::TYPE_AUTH_RESPONSE, "");
		self::close($id);
	}

	private static function send(int $id, int $requestId, int $type, string $body) : void{
		if(!isset(self::$clients[$id])){
			return;
		}
		$payload = pack("V", $requestId & 0xffffffff) . pack("V", $type) . $body . "\x00\x00";
		$data = pack("V", strlen($payload)) . $payload;
		$conn = self::$clients[$id]["conn"];
		stream_set_blocking($conn, true);
		$ok = @fwrite($conn, $data);
		stream_set_blocking($conn, false);
		if($ok === false){
			self::close($id);
		}
	}

	private static function close(int $id) : void{
		if(isset(self::$clients[$id])){
			@fclose(self::$clients[$id]["conn"]);
			unset(self::$clients[$id]);
		}
	}
}
