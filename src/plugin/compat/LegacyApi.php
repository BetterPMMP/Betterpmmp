<?php

/*
 * BetterPMMP - loads plugins written for API 3 and 4 on PocketMine-MP 5.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\plugin\compat;

use pocketmine\BetterPMMPConfig;
use pocketmine\plugin\PluginDescription;
use pocketmine\utils\VersionString;
use Symfony\Component\Filesystem\Path;
use function class_alias;
use function class_exists;
use function basename;
use function count;
use function debug_backtrace;
use function defined;
use function fclose;
use function function_exists;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function implode;
use function interface_exists;
use function ltrim;
use function is_dir;
use function is_file;
use function md5;
use function mkdir;
use function spl_autoload_register;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trait_exists;
use const DEBUG_BACKTRACE_PROVIDE_OBJECT;
use const E_ALL;
use const E_DEPRECATED;
use const PHP_BINARY;

/**
 * Multi-API: plugins whose plugin.yml asks for API 3.x or 4.x are loaded anyway.
 *
 * - Their source is copied to plugin_cache/ and rewritten by {@link LegacySourceRewriter} so that PHP accepts
 *   it next to the PocketMine-MP 5 classes (return types, parameters) and the most common renamed methods work.
 * - Classes that only moved get an alias (pocketmine\Player -> pocketmine\player\Player,
 *   pocketmine\level\Level -> pocketmine\world\World ...).
 * - Classes that no longer exist are provided again: ItemFactory, BlockFactory, ItemIds, BlockLegacyIds with the
 *   numeric IDs of the world converter, Item::getId() and Block::getId().
 *
 * Simple plugins (commands, events, messages, forms, configs, tasks) work this way. Plugins that depend deeply on
 * internals that changed (packets, NBT, chunks, entity spawning of API 3) can still fail; the error is logged and
 * only that plugin is not loaded.
 */
final class LegacyApi{

	/** bump when the rewriter changes, so cached copies are rebuilt */
	private const VERSION = 5;

	/** classes that only moved or were renamed: old name (lowercase) => new name */
	private const CLASSES = [
		"pocketmine\\player" => "pocketmine\\player\\Player",
		"pocketmine\\offlineplayer" => "pocketmine\\player\\OfflinePlayer",
		"pocketmine\\iplayer" => "pocketmine\\player\\IPlayer",
		"pocketmine\\level\\level" => "pocketmine\\world\\World",
		"pocketmine\\level\\position" => "pocketmine\\world\\Position",
		"pocketmine\\level\\location" => "pocketmine\\entity\\Location",
		"pocketmine\\level\\chunkmanager" => "pocketmine\\world\\ChunkManager",
		"pocketmine\\level\\format\\chunk" => "pocketmine\\world\\format\\Chunk",
		"pocketmine\\lang\\translationcontainer" => "pocketmine\\lang\\Translatable",
		"pocketmine\\lang\\textcontainer" => "pocketmine\\lang\\Translatable",
		"pocketmine\\entity\\effectinstance" => "pocketmine\\entity\\effect\\EffectInstance",
		"pocketmine\\entity\\effect" => "pocketmine\\entity\\effect\\Effect",
		"pocketmine\\block\\blockids" => "pocketmine\\block\\BlockLegacyIds",
		"pocketmine\\event\\entity\\entitylevelchangeevent" => "pocketmine\\event\\entity\\EntityTeleportEvent",
	];

	/** moved namespaces: old prefix (lowercase) => new prefix; "Level" in the class name becomes "World" */
	private const PREFIXES = [
		"pocketmine\\event\\level\\" => "pocketmine\\event\\world\\",
		"pocketmine\\level\\particle\\" => "pocketmine\\world\\particle\\",
		"pocketmine\\level\\sound\\" => "pocketmine\\world\\sound\\",
		"pocketmine\\level\\generator\\" => "pocketmine\\world\\generator\\",
		"pocketmine\\level\\biome\\" => "pocketmine\\world\\biome\\",
		"pocketmine\\level\\format\\" => "pocketmine\\world\\format\\",
		"pocketmine\\level\\" => "pocketmine\\world\\",
		"pocketmine\\tile\\" => "pocketmine\\block\\tile\\",
	];

	/** classes that no longer exist and are provided by BetterPMMP: lowercase name => file in shims/ */
	private const SHIMS = [
		"pocketmine\\item\\itemfactory" => "ItemFactory.php",
		"pocketmine\\block\\blockfactory" => "BlockFactory.php",
		"pocketmine\\item\\itemids" => "ItemIds.php",
		"pocketmine\\block\\blocklegacyids" => "BlockLegacyIds.php",
		"pocketmine\\command\\pluginidentifiablecommand" => "PluginIdentifiableCommand.php",
	];

	private static bool $autoloader = false;

	public static function enabled() : bool{
		return BetterPMMPConfig::flag("plugins", "legacy-api", true);
	}

	/**
	 * Whether a plugin asks only for API 3 or 4 (and so needs the compatibility layer).
	 */
	public static function isLegacy(PluginDescription $description) : bool{
		$apis = $description->getCompatibleApis();
		if(count($apis) === 0 || !self::enabled()){
			return false;
		}
		foreach($apis as $api){
			if(!VersionString::isValidBaseVersion($api)){
				return false;
			}
			$major = (new VersionString($api))->getMajor();
			if($major >= 5){
				return false; // the plugin also supports API 5: load it normally
			}
			if($major < 3){
				return false;
			}
		}
		return true;
	}

	/** The new name of a class that moved, or the name itself. */
	public static function modernClass(string $class) : string{
		$class = ltrim($class, "\\");
		$lower = strtolower($class);
		if(isset(self::CLASSES[$lower])){
			return self::CLASSES[$lower];
		}
		foreach(self::PREFIXES as $old => $new){
			if(str_starts_with($lower, $old)){
				return $new . str_replace("Level", "World", substr($class, strlen($old)));
			}
		}
		return $class;
	}

	/** API 3 game modes were numbers: 0 survival, 1 creative, 2 adventure, 3 spectator. */
	public static function gameMode(mixed $mode) : \pocketmine\player\GameMode{
		if($mode instanceof \pocketmine\player\GameMode){
			return $mode;
		}
		return match((int) $mode){
			1 => \pocketmine\player\GameMode::CREATIVE(),
			2 => \pocketmine\player\GameMode::ADVENTURE(),
			3 => \pocketmine\player\GameMode::SPECTATOR(),
			default => \pocketmine\player\GameMode::SURVIVAL(),
		};
	}

	/**
	 * API 3 let commands use permissions that were never declared in plugin.yml; PocketMine-MP 5 throws. When
	 * the call comes from a plugin in compatibility mode, the permission is created (for operators, like the
	 * default of API 3) and true is returned.
	 */
	public static function registerMissingPermission(string $permission) : bool{
		if(!self::fromLegacyPlugin()){
			return false;
		}
		$manager = \pocketmine\permission\PermissionManager::getInstance();
		if($manager->getPermission($permission) === null){
			$manager->addPermission(new \pocketmine\permission\Permission($permission));
			$manager->getPermission(\pocketmine\permission\DefaultPermissions::ROOT_OPERATOR)?->addChild($permission, true);
		}
		return true;
	}

	/**
	 * API 3 commands without a permission could be used by everyone; PocketMine-MP 5 refuses to register them.
	 * For plugins in compatibility mode the command gets its own permission that everyone has.
	 */
	public static function giveOpenPermission(\pocketmine\command\Command $command, string $prefix) : bool{
		if(!self::fromLegacyPlugin()){
			return false;
		}
		$name = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]/', "", $prefix . ".command." . $command->getName()));
		$manager = \pocketmine\permission\PermissionManager::getInstance();
		if($manager->getPermission($name) === null){
			$manager->addPermission(new \pocketmine\permission\Permission($name));
			$manager->getPermission(\pocketmine\permission\DefaultPermissions::ROOT_USER)?->addChild($name, true);
		}
		$command->setPermission($name);
		return true;
	}

	/** Whether the current call comes from a plugin loaded in compatibility mode. */
	private static function fromLegacyPlugin() : bool{
		if(!self::$autoloader){
			return false; // no plugin was loaded in compatibility mode
		}
		foreach(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 8) as $frame){
			$file = str_replace("\\", "/", $frame["file"] ?? "");
			if(str_contains($file, "/plugin_cache/")){
				return true;
			}
			$object = $frame["object"] ?? null;
			if($object instanceof \pocketmine\plugin\Plugin && self::isLegacy($object->getDescription())){
				return true;
			}
		}
		return false;
	}

	/** Whether a class, interface or trait exists (loading it if needed). */
	public static function classExists(string $class) : bool{
		try{
			return class_exists($class) || interface_exists($class) || trait_exists($class);
		}catch(\Throwable){
			return false;
		}
	}

	public static function registerAutoloader() : void{
		if(self::$autoloader){
			return;
		}
		self::$autoloader = true;
		// the fixed list of moved classes is aliased right away, so names in strings and group imports work too
		foreach(self::CLASSES as $old => $new){
			if(!class_exists($old, false) && !interface_exists($old, false) && self::classExists($new)){
				@class_alias($new, $old);
			}
		}
		spl_autoload_register(static function(string $class) : void{
			$lower = strtolower(ltrim($class, "\\"));
			if(isset(self::SHIMS[$lower])){
				require_once Path::join(__DIR__, "shims", self::SHIMS[$lower]);
				return;
			}
			$modern = self::modernClass($class);
			if(strtolower($modern) !== $lower && (class_exists($modern) || interface_exists($modern) || trait_exists($modern))){
				class_alias($modern, $class);
			}
		});
	}

	/**
	 * Copies the plugin's source to plugin_cache/ and rewrites it. Returns the folder to load the classes from.
	 *
	 * @param \Logger $logger
	 */
	public static function prepare(string $pluginPath, PluginDescription $description, string $dataPath, \Logger $logger) : string{
		self::registerAutoloader();
		$isPhar = is_file($pluginPath) && str_ends_with($pluginPath, ".phar");
		$srcRoot = $isPhar ? "phar://" . $pluginPath . "/src" : Path::join($pluginPath, "src");
		$stamp = md5($pluginPath . "|" . (@filemtime($pluginPath) ?: 0) . "|" . (@filesize($pluginPath) ?: 0) . "|" . self::VERSION);
		$target = Path::join($dataPath, "plugin_cache", $description->getName() . "-" . substr($stamp, 0, 12), "src");
		if(is_dir($target) && file_exists(Path::join(dirname($target), ".done"))){
			return $target;
		}

		$files = [];
		if(is_dir($srcRoot)){
			$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS));
			foreach($it as $file){
				/** @var \SplFileInfo $file */
				$path = $file->getPathname();
				$files[substr($path, strlen($srcRoot) + 1)] = $path;
			}
		}

		$rewriter = new LegacySourceRewriter();
		$sources = [];
		foreach($files as $relative => $path){
			if(str_ends_with(strtolower($relative), ".php")){
				$sources[$relative] = (string) file_get_contents($path);
				try{
					$rewriter->scan($sources[$relative]);
				}catch(\Throwable){
					// not valid PHP 8 syntax: copied as it is, PHP will report the problem when loading
				}
			}
		}
		foreach($files as $relative => $path){
			$out = Path::join($target, $relative);
			@mkdir(dirname($out), 0777, true);
			if(isset($sources[$relative])){
				try{
					$code = $rewriter->rewrite($sources[$relative], $relative);
				}catch(\Throwable $e){
					$logger->debug("Could not rewrite $relative: " . $e->getMessage());
					$code = $sources[$relative];
				}
				file_put_contents($out, $code);
			}else{
				file_put_contents($out, (string) file_get_contents($path));
			}
		}
		$changes = $rewriter->getChanges();
		file_put_contents(Path::join(dirname($target), "changes.txt"), implode("\n", $changes) . "\n");
		$error = self::check($target, $description->getSrcNamespacePrefix(), $rewriter->getClassNames());
		if($error !== null){
			throw new \RuntimeException($error);
		}
		file_put_contents(Path::join(dirname($target), ".done"), "");
		$logger->debug(count($changes) . " changes for " . $description->getName() . ", see " . Path::join(dirname($target), "changes.txt"));
		return $target;
	}

	/**
	 * Loads every class of the rewritten plugin in a separate PHP process. A class PHP still refuses (a
	 * signature the rewriter could not fix) would otherwise stop the whole server with a fatal error; this way
	 * only the plugin is skipped. Classes of other plugins it depends on are not available there and are
	 * ignored.
	 *
	 * @param list<string> $classes
	 */
	private static function check(string $src, string $prefix, array $classes) : ?string{
		if(count($classes) === 0 || !defined("pocketmine\\COMPOSER_AUTOLOADER_PATH") || !function_exists("proc_open")){
			return null;
		}
		$code = <<<'PHP'
[$autoload, $src, $prefix, $list] = array_slice($argv, 1);
require $autoload;
spl_autoload_register(static function(string $class) use ($src, $prefix) : void{
	if($prefix !== "" && !str_starts_with($class, $prefix . "\\")){
		return;
	}
	$relative = $prefix === "" ? $class : substr($class, strlen($prefix) + 1);
	$file = $src . "/" . str_replace("\\", "/", $relative) . ".php";
	if(is_file($file)){
		require_once $file;
	}
});
\pocketmine\plugin\compat\LegacyApi::registerAutoloader();
foreach(explode(",", $list) as $class){
	if(!class_exists($class) && !interface_exists($class) && !trait_exists($class)){
		echo "MISSING $class\n";
	}
}
echo "OK\n";
PHP;
		$process = proc_open([PHP_BINARY, "-d", "display_errors=stdout", "-d", "error_reporting=" . (E_ALL & ~E_DEPRECATED), "-r", $code, "--", \pocketmine\COMPOSER_AUTOLOADER_PATH, $src, $prefix, implode(",", $classes)], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		if(!is_resource($process)){
			return null;
		}
		$out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		if(str_contains($out, "OK\n") && !str_contains($out, "Fatal error")){
			return null;
		}
		if(preg_match('/Fatal error: (.+?) in (\S+) on line (\d+)/', $out, $m) === 1){
			// a parent class or interface from another plugin (dependency) cannot be checked here
			if(preg_match('/^(?:Uncaught Error: )?(?:Class|Interface|Trait) "([^"]+)" not found/', $m[1], $c) === 1
				&& !str_starts_with(strtolower($c[1]), "pocketmine\\")
				&& ($prefix === "" || !str_starts_with($c[1], $prefix . "\\"))){
				return null;
			}
			return $m[1] . " (" . basename($m[2]) . ":" . $m[3] . ")";
		}
		return null; // could not tell: let the server try
	}
}
