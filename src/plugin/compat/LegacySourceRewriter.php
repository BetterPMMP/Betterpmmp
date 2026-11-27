<?php

/*
 * BetterPMMP - rewrites the source code of API 3 and 4 plugins so that it loads on PocketMine-MP 5.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\plugin\compat;

use function array_key_last;
use function count;
use function defined;
use function implode;
use function in_array;
use function is_array;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use const T_ABSTRACT;
use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ELLIPSIS;
use const T_EXTENDS;
use const T_FINAL;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INTERFACE;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_STATIC;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * Old plugins fail on PocketMine-MP 5 for two kinds of reasons:
 *
 * 1. PHP refuses to load the class at all: a method overrides a PocketMine method but has no return type
 *    (onEnable() instead of onEnable() : void), or has an extra parameter (Task::onRun(int $currentTick)).
 *    This is fixed generically: every method of a class that extends or implements a PocketMine class gets the
 *    return type of the PocketMine method; extra parameters become optional and parameter types that PHP would
 *    reject are removed.
 * 2. A method or class was renamed (getLevel() -> getWorld(), Item::get() -> numeric IDs ...). The most common
 *    ones are rewritten to the new API; classes that only moved are aliased by {@link LegacyApi}.
 *
 * The rewriter works on PHP tokens, so comments, strings and formatting stay as they are.
 */
final class LegacySourceRewriter{

	/** method renames: old name => replacement text after "->" */
	private const METHODS = [
		"getlevel" => "getWorld",
		"getlevelnonnull" => "getWorld",
		"getdefaultlevel" => "getWorldManager()->getDefaultWorld",
		"getlevelbyname" => "getWorldManager()->getWorldByName",
		"getlevelbyid" => "getWorldManager()->getWorld",
		"getlevels" => "getWorldManager()->getWorlds",
		"loadlevel" => "getWorldManager()->loadWorld",
		"unloadlevel" => "getWorldManager()->unloadWorld",
		"islevelloaded" => "getWorldManager()->isWorldLoaded",
		"islevelgenerated" => "getWorldManager()->isWorldGenerated",
		"addtitle" => "sendTitle",
		"addsubtitle" => "sendSubTitle",
		"addactionbarmessage" => "sendActionBarMessage",
	];

	/** @var array<string, array{parent: ?string, interfaces: list<string>}> plugin classes: FQCN => hierarchy */
	private array $classes = [];

	/** @var list<string> names of all classes, interfaces and traits of the plugin */
	private array $classNames = [];

	/** @var array<string, true> lowercase names of methods the plugin declares itself */
	private array $declared = [];

	/** @var list<string> what was changed, for the log */
	private array $changes = [];

	/**
	 * First pass over all files of the plugin: which class extends what. Needed because a plugin class can
	 * extend another plugin class that extends a PocketMine class.
	 */
	public function scan(string $code) : void{
		$tokens = \PhpToken::tokenize($code);
		$ctx = new RewriteContext($tokens);
		foreach($ctx->classes as $class){
			$this->classes[strtolower($class["name"])] = ["parent" => $class["parent"], "interfaces" => $class["interfaces"]];
			$this->classNames[] = $class["name"];
			foreach($class["methods"] as $method){
				$this->declared[strtolower($method["name"])] = true;
			}
		}
	}

	/** @return list<string> */
	public function getClassNames() : array{
		return $this->classNames;
	}

	/** @return list<string> */
	public function getChanges() : array{
		return $this->changes;
	}

	public function rewrite(string $code, string $file) : string{
		$tokens = \PhpToken::tokenize($code);
		$ctx = new RewriteContext($tokens);
		/** @var array<int, string> $replace token index => new text */
		$replace = [];
		/** @var array<int, string> $after token index => text inserted after it */
		$after = [];

		// old "$text{0}" offsets: PHP 8 only knows "$text[0]"
		foreach($tokens as $i => $tok){
			if($tok->text !== "{" || $i === 0){
				continue;
			}
			$before = $tokens[$i - 1];
			$isOffset = $before->id === T_VARIABLE || $before->text === "]"
				|| ($before->id === T_STRING && ($p = $ctx->prev($i - 1)) !== null && in_array($tokens[$p]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true));
			if($isOffset && ($close = $ctx->matching($i)) !== null){
				$replace[$i] = "[";
				$replace[$close] = "]";
				$this->changes[] = "$file: {} offset -> []";
			}
		}

		// 1. signatures of methods that override PocketMine methods
		foreach($ctx->classes as $class){
			if(($class["kind"] ?? "") === "class"){
				$this->fixClassBody($ctx, $class, $replace, $after, $file);
			}
			foreach($class["methods"] as $method){
				// "function x() : bool" that can end without a return: PHP 8 throws a TypeError there
				if($method["returnType"] && $method["body"] !== null && strtolower($this->returnTypeText($ctx, $method["close"], $method["body"])) === "bool"){
					$end = $ctx->matching($method["body"]);
					if($end !== null && !$this->endsWithReturn($ctx, $method["body"], $end)){
						$replace[$end] = "\treturn true;\n\t}";
						$this->changes[] = "$file: " . $class["short"] . "::" . $method["name"] . "() returns true at the end";
					}
				}
			}
			if(($class["kind"] ?? "") === "class" && !$this->hasAttribute($ctx, $class["start"])){
				// old plugins create properties on the fly, which PHP 8.2 reports as deprecated
				$replace[$class["start"]] = "#[\\AllowDynamicProperties]\n" . ($replace[$class["start"]] ?? $ctx->tokens[$class["start"]]->text);
			}
			foreach($class["methods"] as $method){
				$ref = $this->findReference($class["name"], $method["name"]);
				if($ref === null){
					continue;
				}
				$this->fixSignature($ctx, $method, $ref, $replace, $after, $file . ": " . $class["short"] . "::" . $method["name"] . "()");
			}
		}

		// 2. renamed methods and static calls
		$n = count($tokens);
		$depth = 0;
		$inUse = false;
		for($i = 0; $i < $n; ++$i){
			$tok = $tokens[$i];
			// moved classes are written with their new name. An alias alone is not enough: "instanceof Player"
			// does not load anything and is simply false while pocketmine\Player was never loaded.
			if($tok->text === "{" || $tok->id === \T_CURLY_OPEN || $tok->id === \T_DOLLAR_OPEN_CURLY_BRACES){
				++$depth;
			}elseif($tok->text === "}"){
				--$depth;
			}elseif($tok->id === T_USE && $depth === 0){
				$inUse = true;
			}elseif($inUse && $tok->text === ";"){
				$inUse = false;
			}elseif($tok->id === T_NAME_QUALIFIED || $tok->id === T_NAME_FULLY_QUALIFIED){
				$full = $inUse ? ltrim($tok->text, "\\") : $ctx->resolve($tok->text);
				$modern = LegacyApi::modernClass($full);
				if(strtolower($modern) !== strtolower($full) && LegacyApi::classExists($modern)){
					if($inUse){
						$next = $ctx->next($i);
						$oldShort = substr($full, (int) strrpos($full, "\\") + 1);
						$newShort = substr($modern, (int) strrpos($modern, "\\") + 1);
						$hasAs = $next !== null && $tokens[$next]->id === \T_AS;
						$replace[$i] = $modern . (!$hasAs && strtolower($oldShort) !== strtolower($newShort) ? " as " . $oldShort : "");
					}else{
						$replace[$i] = "\\" . $modern;
					}
					$this->changes[] = "$file: $full -> $modern";
				}
				continue;
			}
			if(($tok->id === T_OBJECT_OPERATOR || $tok->id === T_NULLSAFE_OBJECT_OPERATOR) && ($j = $ctx->next($i)) !== null && $tokens[$j]->id === T_STRING){
				$name = strtolower($tokens[$j]->text);
				$open = $ctx->next($j);
				if($open === null || $tokens[$open]->text !== "("){
					continue;
				}
				if(isset(self::METHODS[$name]) && !isset($this->declared[$name])){
					$replace[$j] = self::METHODS[$name];
					$this->changes[] = "$file: ->" . $tokens[$j]->text . "() -> " . self::METHODS[$name] . "()";
				}elseif($name === "getname" && $this->previousCallIs($ctx, $i, ["getlevel", "getlevelnonnull", "getworld"])){
					$replace[$j] = "getFolderName"; // Level::getName() was the folder name
				}elseif($name === "getplayer" && $this->previousCallIs($ctx, $i, ["getserver", "getinstance"])){
					$replace[$j] = "getPlayerByPrefix";
					$this->changes[] = "$file: Server::getPlayer() -> getPlayerByPrefix()";
				}elseif($name === "setgamemode" && !isset($this->declared[$name])){
					// API 3 used numbers (0 survival, 1 creative ...); the converter accepts both
					$close = $ctx->matching($open);
					if($close !== null){
						$replace[$open] = "(\\pocketmine\\plugin\\compat\\LegacyApi::gameMode(";
						$replace[$close] = "))";
						$this->changes[] = "$file: setGamemode() accepts numbers";
					}
				}elseif($name === "setcancelled" && !isset($this->declared[$name])){
					$close = $ctx->matching($open);
					if($close !== null){
						$arg = strtolower(trim($ctx->text($open + 1, $close - 1)));
						if($arg === "" || $arg === "true"){
							$replace[$j] = "cancel";
							for($k = $open + 1; $k < $close; ++$k){
								$replace[$k] = "";
							}
						}elseif($arg === "false"){
							$replace[$j] = "uncancel";
							for($k = $open + 1; $k < $close; ++$k){
								$replace[$k] = "";
							}
						}else{
							$replace[$j] = "{(" . $ctx->text($open + 1, $close - 1) . ") ? 'cancel' : 'uncancel'}";
							for($k = $open + 1; $k < $close; ++$k){
								$replace[$k] = "";
							}
						}
						$this->changes[] = "$file: setCancelled() -> cancel()/uncancel()";
					}
				}
				continue;
			}
			if($tok->id === T_DOUBLE_COLON && ($p = $ctx->prev($i)) !== null && in_array($tokens[$p]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($j = $ctx->next($i)) !== null && $tokens[$j]->id === T_STRING){
				$class = LegacyApi::modernClass($ctx->resolve($tokens[$p]->text));
				$member = $tokens[$j]->text;
				$isCall = ($c = $ctx->next($j)) !== null && $tokens[$c]->text === "(";
				$lower = strtolower($class);
				if($lower === "pocketmine\\item\\item" && $isCall && in_array(strtolower($member), ["get", "fromstring"], true)){
					$replace[$p] = "\\pocketmine\\item\\ItemFactory";
					$this->changes[] = "$file: Item::$member() -> legacy ID lookup";
				}elseif($lower === "pocketmine\\block\\block" && $isCall && strtolower($member) === "get"){
					$replace[$p] = "\\pocketmine\\block\\BlockFactory";
					$this->changes[] = "$file: Block::get() -> legacy ID lookup";
				}elseif(!$isCall && preg_match('/^[A-Z0-9_]+$/', $member) === 1 && $member !== "CLASS"){
					// API 3: Item::DIAMOND_SWORD, Block::STONE (the classes implemented the ID interfaces)
					if($lower === "pocketmine\\player\\player" && in_array($member, ["SURVIVAL", "CREATIVE", "ADVENTURE", "SPECTATOR"], true)){
						$replace[$p] = "\\pocketmine\\player\\GameMode";
						$replace[$j] = $member . "()";
						$this->changes[] = "$file: Player::$member -> GameMode::$member()";
					}elseif($lower === "pocketmine\\item\\item" && !defined("\\pocketmine\\item\\Item::$member")){
						$replace[$p] = "\\pocketmine\\item\\ItemIds";
					}elseif($lower === "pocketmine\\block\\block" && !defined("\\pocketmine\\block\\Block::$member")){
						$replace[$p] = "\\pocketmine\\block\\BlockLegacyIds";
					}
				}
			}
		}

		if(count($replace) === 0 && count($after) === 0){
			return $code;
		}
		$out = "";
		foreach($tokens as $i => $tok){
			$out .= $replace[$i] ?? $tok->text;
			if(isset($after[$i])){
				$out .= $after[$i];
			}
		}
		return $out;
	}

	private function returnTypeText(RewriteContext $ctx, int $close, int $body) : string{
		$colon = $ctx->next($close);
		return $colon === null ? "" : trim($ctx->text($colon + 1, $body - 1));
	}

	/** Whether the last statement of a body is a return or throw (then nothing needs to be added). */
	private function endsWithReturn(RewriteContext $ctx, int $open, int $close) : bool{
		$last = $ctx->prev($close);
		if($last === null || $last <= $open){
			return false;
		}
		if($ctx->tokens[$last]->text !== ";"){
			return false;
		}
		// walk back to the start of the last statement
		for($i = $ctx->prev($last); $i !== null && $i > $open; $i = $ctx->prev($i)){
			$t = $ctx->tokens[$i]->text;
			if($t === ";" || $t === "{" || $t === "}"){
				$first = $ctx->next($i);
				return $first !== null && in_array($ctx->tokens[$first]->id, [\T_RETURN, \T_THROW], true);
			}
		}
		$first = $ctx->next($open);
		return $first !== null && in_array($ctx->tokens[$first]->id, [\T_RETURN, \T_THROW], true);
	}

	/**
	 * Class-level fixes: events that implement Cancellable get the trait PocketMine-MP 5 needs, and properties
	 * that a PocketMine parent class declares with a type get the same type.
	 *
	 * @param array<string, mixed> $class
	 * @param array<int, string> $replace
	 * @param array<int, string> $after
	 */
	private function fixClassBody(RewriteContext $ctx, array $class, array &$replace, array &$after, string $file) : void{
		$declared = [];
		foreach($class["methods"] as $method){
			$declared[strtolower($method["name"])] = true;
		}
		foreach($class["interfaces"] as $interface){
			if(strtolower(LegacyApi::modernClass($interface)) === "pocketmine\\event\\cancellable" && !isset($declared["iscancelled"])){
				$after[$class["open"]] = ($after[$class["open"]] ?? "") . " use \\pocketmine\\event\\CancellableTrait;";
				$this->changes[] = "$file: " . $class["short"] . " uses CancellableTrait";
				break;
			}
		}
		foreach($class["properties"] as $property){
			if($property["multi"]){
				continue;
			}
			$ref = $this->findPropertyReference($class["name"], $property["name"]);
			if($ref === null || !$ref->hasType() || $ref->isStatic() !== $property["static"]){
				continue;
			}
			$type = self::typeToString($ref->getType(), $ref->getDeclaringClass()->getName());
			if($type === null){
				continue;
			}
			if($property["type"] !== null){
				[$ts, $te] = $property["type"];
				$replace[$ts] = $type;
				for($k = $ts + 1; $k <= $te; ++$k){
					$replace[$k] = "";
				}
			}else{
				$after[$property["modifier"]] = ($after[$property["modifier"]] ?? "") . " " . $type;
			}
			$refType = $ref->getType();
			if($property["default"] !== null && $refType !== null && !$refType->allowsNull() && strtolower(trim($ctx->text($property["default"][0] + 1, $property["default"][1]))) === "null"){
				for($k = $property["default"][0]; $k <= $property["default"][1]; ++$k){
					$replace[$k] = "";
				}
			}
			$this->changes[] = "$file: " . $class["short"] . "::$" . $property["name"] . " typed $type";
		}
	}

	private function findPropertyReference(string $class, string $property) : ?\ReflectionProperty{
		$seen = [];
		$current = $class;
		while($current !== null){
			$key = strtolower(ltrim($current, "\\"));
			if(isset($seen[$key])){
				return null;
			}
			$seen[$key] = true;
			if(isset($this->classes[$key])){
				$current = $this->classes[$key]["parent"];
				continue;
			}
			$modern = LegacyApi::modernClass($current);
			if(!str_starts_with(strtolower($modern), "pocketmine\\")){
				return null;
			}
			try{
				if(!class_exists($modern)){
					return null;
				}
				$reflection = new \ReflectionClass($modern);
				if(!$reflection->hasProperty($property)){
					return null;
				}
				$ref = $reflection->getProperty($property);
				return $ref->isPrivate() ? null : $ref;
			}catch(\Throwable){
				return null;
			}
		}
		return null;
	}

	/** Whether the class declaration starting at $start already has an attribute right before it. */
	private function hasAttribute(RewriteContext $ctx, int $start) : bool{
		$p = $ctx->prev($start);
		return $p !== null && $ctx->tokens[$p]->text === "]";
	}

	/**
	 * Whether the call before "->" at $arrow is one of $names: getServer()->getPlayer(...).
	 *
	 * @param list<string> $names lowercase
	 */
	private function previousCallIs(RewriteContext $ctx, int $arrow, array $names) : bool{
		$close = $ctx->prev($arrow);
		if($close === null || $ctx->tokens[$close]->text !== ")"){
			return false;
		}
		$open = $ctx->prev($close);
		if($open === null || $ctx->tokens[$open]->text !== "("){
			return false; // only calls without arguments
		}
		$name = $ctx->prev($open);
		return $name !== null && in_array(strtolower($ctx->tokens[$name]->text), $names, true);
	}

	/**
	 * The PocketMine method that a plugin method overrides or implements, or null.
	 */
	private function findReference(string $class, string $method) : ?\ReflectionMethod{
		$seen = [];
		$queue = [$class];
		while(count($queue) > 0){
			$current = array_shift($queue);
			$key = strtolower(ltrim($current, "\\"));
			if(isset($seen[$key])){
				continue;
			}
			$seen[$key] = true;
			if(isset($this->classes[$key])){
				$info = $this->classes[$key];
				if($info["parent"] !== null){
					$queue[] = $info["parent"];
				}
				foreach($info["interfaces"] as $interface){
					$queue[] = $interface;
				}
				continue;
			}
			// not a class of the plugin: a PocketMine (or library) class
			$modern = LegacyApi::modernClass($current);
			if(!str_starts_with(strtolower($modern), "pocketmine\\")){
				continue;
			}
			try{
				if(!class_exists($modern) && !interface_exists($modern)){
					continue;
				}
				$reflection = new \ReflectionClass($modern);
				if($reflection->hasMethod($method)){
					$ref = $reflection->getMethod($method);
					if(!$ref->isPrivate() && $ref->getName() !== "__construct"){
						return $ref;
					}
				}
			}catch(\Throwable){
				continue;
			}
		}
		return null;
	}

	/**
	 * @param array{name: string, params: list<array{start: int, end: int, type: ?array{int, int}, var: int, default: bool, variadic: bool}>, close: int, returnType: bool, body: ?int, visibility: ?int} $method
	 * @param array<int, string> $replace
	 * @param array<int, string> $after
	 */
	private function fixSignature(RewriteContext $ctx, array $method, \ReflectionMethod $ref, array &$replace, array &$after, string $where) : void{
		// a method may not be less visible than the one it overrides
		if($method["visibility"] !== null){
			$current = strtolower($ctx->tokens[$method["visibility"]]->text);
			$needed = $ref->isPublic() ? "public" : "protected";
			if(($current === "private") || ($current === "protected" && $needed === "public")){
				$replace[$method["visibility"]] = $needed;
				$this->changes[] = "$where: made $needed";
			}
		}
		$refParams = $ref->getParameters();
		$inject = [];
		foreach($method["params"] as $index => $param){
			$refParam = $refParams[$index] ?? null;
			if($refParam === null){
				// extra parameter (API 3: Task::onRun(int $currentTick), AsyncTask::onCompletion(Server $server))
				if(!$param["default"] && !$param["variadic"]){
					if($param["type"] !== null){
						[$ts, $te] = $param["type"];
						for($k = $ts; $k <= $te; ++$k){
							$replace[$k] = "";
						}
					}
					$after[$param["var"]] = ($after[$param["var"]] ?? "") . " = null";
					$var = $ctx->tokens[$param["var"]]->text;
					$typeText = $param["type"] !== null ? strtolower(ltrim($ctx->resolve(trim($ctx->text($param["type"][0], $param["type"][1]))), "\\")) : "";
					if($var === '$currentTick'){
						$inject[] = "$var ??= \\pocketmine\\Server::getInstance()->getTick();";
					}elseif($typeText === "pocketmine\\server"){
						$inject[] = "$var ??= \\pocketmine\\Server::getInstance();";
					}
					$this->changes[] = "$where: parameter $var made optional";
				}
				continue;
			}
			// a parameter type that is not the same as PocketMine's would be rejected: leave it untyped
			if($param["type"] !== null && $refParam->hasType()){
				$childType = strtolower(ltrim(LegacyApi::modernClass(ltrim($ctx->resolve(ltrim(trim($ctx->text($param["type"][0], $param["type"][1])), "?")), "\\")), "\\"));
				$refType = $refParam->getType();
				$refName = $refType instanceof \ReflectionNamedType ? strtolower($refType->getName()) : "";
				if($childType !== $refName){
					[$ts, $te] = $param["type"];
					for($k = $ts; $k <= $te; ++$k){
						$replace[$k] = "";
					}
					$this->changes[] = "$where: parameter type removed";
				}
			}
		}

		if(!$method["returnType"] && $ref->hasReturnType()){
			$type = self::typeToString($ref->getReturnType(), $ref->getDeclaringClass()->getName());
			if($type !== null && !($type === "void" && $method["body"] !== null && $ctx->returnsValue($method["body"]))){
				$after[$method["close"]] = ($after[$method["close"]] ?? "") . " : " . $type;
				$this->changes[] = "$where: return type $type added";
			}
		}
		if(count($inject) > 0 && $method["body"] !== null){
			$after[$method["body"]] = ($after[$method["body"]] ?? "") . " " . implode(" ", $inject);
		}
	}

	private static function typeToString(?\ReflectionType $type, string $declaringClass) : ?string{
		if($type === null){
			return null;
		}
		if($type instanceof \ReflectionNamedType){
			$name = $type->getName();
			$lower = strtolower($name);
			if($lower === "self"){
				$name = "\\" . $declaringClass;
			}elseif($lower === "static"){
				$name = "static";
			}elseif(!$type->isBuiltin()){
				$name = "\\" . $name;
			}
			return ($type->allowsNull() && !in_array($lower, ["mixed", "null"], true) ? "?" : "") . $name;
		}
		if($type instanceof \ReflectionUnionType){
			$parts = [];
			foreach($type->getTypes() as $part){
				$s = self::typeToString($part, $declaringClass);
				if($s === null){
					return null;
				}
				$parts[] = ltrim($s, "?");
			}
			return implode("|", $parts);
		}
		return null;
	}
}

