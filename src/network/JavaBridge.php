<?php

/*
 * BetterPMMP - Java Edition bridge (managed ViaProxy).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\BetterPMMPConfig;
use pocketmine\Server;
use pocketmine\utils\Internet;
use Symfony\Component\Filesystem\Path;
use function array_search;
use function chmod;
use function clearstatcache;
use function end;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fopen;
use function glob;
use function is_array;
use function is_dir;
use function is_resource;
use function is_string;
use function json_decode;
use function dirname;
use function mkdir;
use function php_uname;
use function preg_match;
use function preg_match_all;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function str_ends_with;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function sprintf;
use function substr;
use function trim;
use function unlink;
use function usleep;
use const PHP_OS_FAMILY;

/**
 * Lets Java Edition players join without any extra setup: BetterPMMP gets a
 * Java runtime (if none is installed) and ViaProxy, starts ViaProxy together
 * with the server (Java port -> local Bedrock port), restarts it if it crashes
 * and stops it on shutdown.
 *
 * Players coming through this proxy (from 127.0.0.1, no Xbox account) skip the
 * Xbox check; ViaProxy checks their Mojang account instead (online-mode) and
 * their names get a prefix so they cannot take a Bedrock player's name.
 * Configured in the "java-bridge" section of betterpmmp.yml.
 */
final class JavaBridge{

	private const RELEASES_URL = "https://api.github.com/repos/ViaVersion/ViaProxy/releases/latest";
	private const JRE_URL = "https://api.adoptium.net/v3/binary/latest/21/ga/linux/%s/jre/hotspot/normal/eclipse";
	private const JAR = "ViaProxy.jar";
	private const MAX_RESTARTS = 5;

	/** @var resource|null */
	private static $process = null;
	private static bool $active = false;
	private static int $restarts = 0;
	/** @var list<string> */
	private static array $command = [];
	private static string $dir = "";
	private static int $logOffset = 0;
	private static int $versionChecks = 0;

	public static function isEnabled() : bool{
		return BetterPMMPConfig::flag("java-bridge", "enabled", true);
	}

	/** Whether a connection from this address comes through the proxy started by BetterPMMP. */
	public static function isBridgeAddress(string $ip) : bool{
		return self::$active && self::$process !== null && ($ip === "127.0.0.1" || $ip === "::1");
	}

	/** In-game name of a Java player (prefix + name, at most 16 characters). */
	public static function bridgeName(string $username) : string{
		$name = BetterPMMPConfig::string("java-bridge", "name-prefix", "J_") . $username;
		return strlen($name) > 16 ? substr($name, 0, 16) : $name;
	}

	public static function start(Server $server) : void{
		if(!self::isEnabled() || self::$process !== null){
			return;
		}
		$logger = $server->getLogger();
		self::$dir = Path::join($server->getDataPath(), "java-bridge");
		if(!is_dir(self::$dir) && !@mkdir(self::$dir, 0777, true)){
			$logger->error("[Java-Bridge] Ordner " . self::$dir . " kann nicht erstellt werden");
			return;
		}
		$java = self::findJava($server);
		if($java === null){
			return;
		}
		$jar = Path::join(self::$dir, self::JAR);
		if(!file_exists($jar) && !self::downloadViaProxy($jar, $server)){
			return;
		}
		$port = BetterPMMPConfig::int("java-bridge", "port", 25565);
		$online = BetterPMMPConfig::flag("java-bridge", "online-mode", true);
		self::$command = [
			$java,
			"-Xmx" . BetterPMMPConfig::string("java-bridge", "memory", "512M"),
			"-jar", $jar, "cli",
			"--bind-address", "0.0.0.0:" . $port,
			"--target-address", "127.0.0.1:" . $server->getPort(),
			"--target-version", BetterPMMPConfig::string("java-bridge", "target-version", "Bedrock 1.26.60"),
			"--proxy-online-mode", $online ? "true" : "false",
			"--auth-method", "NONE",
		];
		if(!self::launch()){
			$logger->error("[Java-Bridge] ViaProxy konnte nicht gestartet werden");
			return;
		}
		self::$active = true;
		$logger->info("[Java-Bridge] Java-Spieler können jetzt joinen: Port $port (Log: java-bridge/proxy.log)");
		if(!$online){
			$logger->warning("[Java-Bridge] online-mode ist aus: Java-Spieler können jeden Namen benutzen.");
		}
	}

	private static function launch() : bool{
		$log = Path::join(self::$dir, "proxy.log");
		clearstatcache();
		self::$logOffset = file_exists($log) ? (int) filesize($log) : 0;
		$out = @fopen($log, "ab");
		if($out === false){
			return false;
		}
		$process = @proc_open(self::$command, [0 => ["pipe", "r"], 1 => $out, 2 => $out], $pipes, self::$dir);
		fclose($out);
		if(!is_resource($process)){
			return false;
		}
		self::$process = $process;
		return true;
	}

	/** Restarts ViaProxy if it stopped (called every 10 seconds from the server tick). */
	public static function watchdog(Server $server) : void{
		if(self::$process === null || !self::$active){
			return;
		}
		if(self::$versionChecks < 6){ // first minute after start
			++self::$versionChecks;
			if(self::fixTargetVersion($server->getLogger())){
				self::$versionChecks = 0;
				return;
			}
		}
		$status = proc_get_status(self::$process);
		if($status["running"]){
			return;
		}
		proc_close(self::$process);
		self::$process = null;
		if(++self::$restarts > self::MAX_RESTARTS){
			self::$active = false;
			$server->getLogger()->error("[Java-Bridge] ViaProxy stürzt immer wieder ab (Exit-Code " . $status["exitcode"] . "), siehe java-bridge/proxy.log");
			return;
		}
		$server->getLogger()->warning("[Java-Bridge] ViaProxy wurde beendet (Exit-Code " . $status["exitcode"] . "), starte neu...");
		if(!self::launch()){
			self::$active = false;
		}
	}

	public static function stop() : void{
		self::$active = false;
		if(self::$process === null){
			return;
		}
		$process = self::$process;
		self::$process = null;
		@proc_terminate($process);
		for($i = 0; $i < 30 && proc_get_status($process)["running"]; ++$i){
			usleep(100_000);
		}
		if(proc_get_status($process)["running"]){
			@proc_terminate($process, 9);
		}
		proc_close($process);
	}

	/**
	 * ViaProxy renames its Bedrock version with every update ("Bedrock 1.26.70" ...).
	 * If it did not know the configured name it prints its list of versions:
	 * take the Bedrock one from there and restart with it.
	 */
	private static function fixTargetVersion(\Logger $logger) : bool{
		$log = @file_get_contents(Path::join(self::$dir, "proxy.log"), false, null, self::$logOffset);
		if(!is_string($log) || !str_contains($log, "Invalid target server version")){
			return false;
		}
		if(preg_match_all('/(Bedrock[^\r\n]*?\d+\.\d+(?:\.\d+)*)\s*$/m', $log, $m) < 1){
			return false;
		}
		$version = trim(end($m[1]));
		$index = array_search("--target-version", self::$command, true);
		if($index === false || !isset(self::$command[$index + 1]) || self::$command[$index + 1] === $version){
			return false;
		}
		self::$command[$index + 1] = $version;
		$logger->info("[Java-Bridge] ViaProxy nutzt jetzt \"$version\", starte neu...");
		$process = self::$process;
		self::$process = null;
		if($process !== null){
			@proc_terminate($process);
			for($i = 0; $i < 30 && proc_get_status($process)["running"]; ++$i){
				usleep(100_000);
			}
			proc_close($process);
		}
		if(!self::launch()){
			self::$active = false;
		}
		return true;
	}

	/** Java 17+ from java-path, the bundled runtime, or a freshly downloaded one. */
	private static function findJava(Server $server) : ?string{
		$logger = $server->getLogger();
		$configured = BetterPMMPConfig::string("java-bridge", "java-path", "java");
		foreach([$configured, self::bundledJava()] as $candidate){
			if($candidate !== null && ($version = self::javaVersion($candidate)) !== null && $version >= 17){
				return $candidate;
			}
		}
		if(!BetterPMMPConfig::flag("java-bridge", "auto-download", true)){
			$logger->error("[Java-Bridge] Java 17 oder neuer fehlt. Installieren oder java-path in betterpmmp.yml setzen.");
			return null;
		}
		if(PHP_OS_FAMILY !== "Linux"){
			$logger->error("[Java-Bridge] Java 17 oder neuer fehlt und kann nur unter Linux automatisch geladen werden. Bitte Java installieren.");
			return null;
		}
		$arch = strtolower(php_uname("m"));
		$arch = match(true){
			$arch === "x86_64" || $arch === "amd64" => "x64",
			$arch === "aarch64" || $arch === "arm64" => "aarch64",
			default => null,
		};
		if($arch === null){
			$logger->error("[Java-Bridge] Java fehlt und für diese CPU gibt es keinen automatischen Download. Bitte Java 17+ installieren.");
			return null;
		}
		$logger->info("[Java-Bridge] Java nicht gefunden, lade Java 21 (Eclipse Temurin) herunter...");
		$archive = Path::join(self::$dir, "jre.tar.gz");
		$result = Internet::getURL(sprintf(self::JRE_URL, $arch), 300, ["User-Agent: BetterPMMP"], $err);
		if($result === null || $result->getCode() !== 200 || strlen($result->getBody()) < 1_000_000 || @file_put_contents($archive, $result->getBody()) === false){
			$logger->error("[Java-Bridge] Java-Download fehlgeschlagen" . (is_string($err) && $err !== "" ? ": $err" : "") . ". Bitte Java 17+ installieren.");
			return null;
		}
		try{
			(new \PharData($archive))->extractTo(Path::join(self::$dir, "jre"), null, true);
		}catch(\Throwable $e){
			$logger->error("[Java-Bridge] Java konnte nicht entpackt werden: " . $e->getMessage());
			return null;
		}finally{
			@unlink($archive);
			@unlink(Path::join(self::$dir, "jre.tar"));
		}
		$java = self::bundledJava();
		if($java === null){
			$logger->error("[Java-Bridge] Entpacktes Java nicht gefunden");
			return null;
		}
		@chmod($java, 0755);
		foreach(glob(Path::join(dirname($java, 2), "lib", "jspawnhelper")) ?: [] as $helper){
			@chmod($helper, 0755);
		}
		if(self::javaVersion($java) === null){
			$logger->error("[Java-Bridge] Heruntergeladenes Java startet nicht");
			return null;
		}
		$logger->info("[Java-Bridge] Java 21 bereit");
		return $java;
	}

	private static function bundledJava() : ?string{
		$found = glob(Path::join(self::$dir, "jre", "*", "bin", "java"));
		return is_array($found) && isset($found[0]) ? $found[0] : null;
	}

	private static function javaVersion(string $java) : ?int{
		$process = @proc_open([$java, "-version"], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		if(!is_resource($process)){
			return null;
		}
		$output = stream_get_contents($pipes[2]) . stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if(proc_close($process) !== 0 || preg_match('/version "(\d+)(?:\.(\d+))?/', (string) $output, $m) !== 1){
			return null;
		}
		return $m[1] === "1" && isset($m[2]) ? (int) $m[2] : (int) $m[1];
	}

	private static function downloadViaProxy(string $target, Server $server) : bool{
		$logger = $server->getLogger();
		if(!BetterPMMPConfig::flag("java-bridge", "auto-download", true)){
			$logger->error("[Java-Bridge] $target fehlt. ViaProxy.jar herunterladen und dort ablegen (oder auto-download: true).");
			return false;
		}
		$logger->info("[Java-Bridge] Lade ViaProxy herunter...");
		$release = Internet::getURL(self::RELEASES_URL, 15, ["User-Agent: BetterPMMP", "Accept: application/vnd.github+json"], $err);
		$data = $release === null ? null : json_decode($release->getBody(), true);
		$url = null;
		$name = "";
		if(is_array($data) && is_array($data["assets"] ?? null)){
			foreach($data["assets"] as $asset){
				$assetName = is_array($asset) && is_string($asset["name"] ?? null) ? $asset["name"] : "";
				if(str_ends_with($assetName, ".jar") && !str_contains(strtolower($assetName), "java8") && is_string($asset["browser_download_url"] ?? null)){
					$url = $asset["browser_download_url"];
					$name = $assetName;
					break;
				}
			}
		}
		if($url === null){
			$logger->error("[Java-Bridge] ViaProxy-Release nicht gefunden" . (is_string($err) && $err !== "" ? ": $err" : "") . ". ViaProxy.jar selbst in java-bridge/ ablegen.");
			return false;
		}
		$jar = Internet::getURL($url, 120, ["User-Agent: BetterPMMP"], $err);
		if($jar === null || $jar->getCode() !== 200 || strlen($jar->getBody()) < 100_000 || substr($jar->getBody(), 0, 2) !== "PK"){
			$logger->error("[Java-Bridge] Download von $name fehlgeschlagen" . (is_string($err) && $err !== "" ? ": $err" : ""));
			return false;
		}
		if(@file_put_contents($target, $jar->getBody()) === false){
			$logger->error("[Java-Bridge] $target kann nicht gespeichert werden");
			return false;
		}
		$logger->info("[Java-Bridge] $name heruntergeladen (" . (int) (filesize($target) / 1024) . " KB)");
		return true;
	}
}
