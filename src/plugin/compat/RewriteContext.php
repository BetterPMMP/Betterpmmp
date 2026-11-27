<?php

/*
 * BetterPMMP - declarations and token helpers for LegacySourceRewriter.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\plugin\compat;

use function count;
use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_DOUBLE_COLON;
use const T_ELLIPSIS;
use const T_EXTENDS;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INTERFACE;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_NEW;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * Token helpers and the declarations of one file: namespace, imports, classes and their methods.
 *
 * @internal
 */
final class RewriteContext{

	public string $namespace = "";
	/** @var array<string, string> lowercase alias => full name */
	public array $uses = [];
	/**
	 * @var list<array{name: string, short: string, parent: ?string, interfaces: list<string>, methods: list<array{name: string, params: list<array{start: int, end: int, type: ?array{int, int}, var: int, default: bool, variadic: bool}>, close: int, returnType: bool, body: ?int, visibility: ?int}>}>
	 */
	public array $classes = [];

	/** @param list<\PhpToken> $tokens */
	public function __construct(public array $tokens){
		$this->parse();
	}

	public function isSignificant(int $i) : bool{
		$id = $this->tokens[$i]->id;
		return $id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT;
	}

	public function next(int $i) : ?int{
		for($j = $i + 1, $n = count($this->tokens); $j < $n; ++$j){
			if($this->isSignificant($j)){
				return $j;
			}
		}
		return null;
	}

	public function prev(int $i) : ?int{
		for($j = $i - 1; $j >= 0; --$j){
			if($this->isSignificant($j)){
				return $j;
			}
		}
		return null;
	}

	/** Index of the bracket that closes the one at $i ( "(" "[" "{" ). */
	public function matching(int $i) : ?int{
		$open = $this->tokens[$i]->text;
		$close = match($open){ "(" => ")", "[" => "]", default => "}" };
		$depth = 0;
		for($j = $i, $n = count($this->tokens); $j < $n; ++$j){
			$t = $this->tokens[$j]->text;
			if($t === $open || ($open === "{" && ($t === '${' || $this->tokens[$j]->id === \T_CURLY_OPEN || $this->tokens[$j]->id === \T_DOLLAR_OPEN_CURLY_BRACES))){
				++$depth;
			}elseif($t === $close){
				if(--$depth === 0){
					return $j;
				}
			}
		}
		return null;
	}

	public function text(int $from, int $to) : string{
		$s = "";
		for($i = $from; $i <= $to; ++$i){
			$s .= $this->tokens[$i]->text;
		}
		return $s;
	}

	/** Full class name for a name written in this file. */
	public function resolve(string $name) : string{
		if(str_starts_with($name, "\\")){
			return substr($name, 1);
		}
		$lower = strtolower($name);
		if(in_array($lower, ["self", "static", "parent", "int", "float", "string", "bool", "array", "callable", "iterable", "object", "mixed", "void", "null", "never", "false", "true"], true)){
			return $name;
		}
		$first = $lower;
		$rest = "";
		if(($pos = strpos($name, "\\")) !== false){
			$first = strtolower(substr($name, 0, $pos));
			$rest = substr($name, $pos);
		}
		if(isset($this->uses[$first])){
			return $this->uses[$first] . $rest;
		}
		return ($this->namespace !== "" ? $this->namespace . "\\" : "") . $name;
	}

	/** Whether a function body returns a value somewhere (not counting nested closures). */
	public function returnsValue(int $bodyOpen) : bool{
		$end = $this->matching($bodyOpen);
		if($end === null){
			return false;
		}
		for($i = $bodyOpen + 1; $i < $end; ++$i){
			$id = $this->tokens[$i]->id;
			if($id === T_FUNCTION || $id === \T_FN){
				// skip nested closures
				$open = $i;
				while($open < $end && $this->tokens[$open]->text !== "{" && $this->tokens[$open]->text !== ";"){
					if($this->tokens[$open]->id === \T_DOUBLE_ARROW && $id === \T_FN){
						break;
					}
					++$open;
				}
				if($this->tokens[$open]->text === "{"){
					$i = $this->matching($open) ?? $end;
				}
				continue;
			}
			if($id === \T_RETURN){
				$n = $this->next($i);
				if($n !== null && $this->tokens[$n]->text !== ";"){
					return true;
				}
			}
		}
		return false;
	}

	private function parse() : void{
		$n = count($this->tokens);
		$depth = 0;
		$classDepth = null;
		$current = null;
		for($i = 0; $i < $n; ++$i){
			$tok = $this->tokens[$i];
			$text = $tok->text;
			if($text === "{" || $tok->id === \T_CURLY_OPEN || $tok->id === \T_DOLLAR_OPEN_CURLY_BRACES){
				++$depth;
				continue;
			}
			if($text === "}"){
				--$depth;
				if($current !== null && $classDepth !== null && $depth === $classDepth){
					$this->classes[] = $current;
					$current = null;
					$classDepth = null;
				}
				continue;
			}
			if($tok->id === T_NAMESPACE && $depth === 0){
				$j = $this->next($i);
				if($j !== null && in_array($this->tokens[$j]->id, [T_STRING, T_NAME_QUALIFIED], true)){
					$this->namespace = $this->tokens[$j]->text;
				}
				continue;
			}
			if($tok->id === T_USE && $depth === 0){
				$this->parseUse($i);
				continue;
			}
			if(($tok->id === T_CLASS || $tok->id === T_INTERFACE || $tok->id === T_TRAIT) && $current === null){
				$p = $this->prev($i);
				if($p !== null && ($this->tokens[$p]->id === T_DOUBLE_COLON || $this->tokens[$p]->id === T_NEW)){
					continue; // Foo::class or an anonymous class
				}
				$j = $this->next($i);
				if($j === null || $this->tokens[$j]->id !== T_STRING){
					continue;
				}
				$short = $this->tokens[$j]->text;
				$parent = null;
				$interfaces = [];
				$k = $this->next($j);
				$mode = null;
				while($k !== null && $this->tokens[$k]->text !== "{"){
					$t = $this->tokens[$k];
					if($t->id === T_EXTENDS){
						$mode = "extends";
					}elseif($t->id === T_IMPLEMENTS){
						$mode = "implements";
					}elseif(in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)){
						$resolved = $this->resolve($t->text);
						if($mode === "extends" && $tok->id === T_CLASS){
							$parent = $resolved;
						}else{
							$interfaces[] = $resolved; // interfaces extending interfaces count the same
						}
					}
					$k = $this->next($k);
				}
				$start = $i;
				for($m = $this->prev($i); $m !== null && in_array($this->tokens[$m]->id, [\T_ABSTRACT, \T_FINAL, \T_READONLY], true); $m = $this->prev($m)){
					$start = $m;
				}
				$current = [
					"start" => $start,
					"kind" => $tok->id === T_CLASS ? "class" : ($tok->id === T_INTERFACE ? "interface" : "trait"),
					"name" => ($this->namespace !== "" ? $this->namespace . "\\" : "") . $short,
					"short" => $short,
					"parent" => $parent,
					"interfaces" => $interfaces,
					"methods" => [],
					"properties" => [],
					"open" => $k,
				];
				$classDepth = $depth;
				continue;
			}
			if($tok->id === T_VARIABLE && $current !== null && $classDepth !== null && $depth === $classDepth + 1){
				$property = $this->parseProperty($i);
				if($property !== null){
					$current["properties"][] = $property;
				}
				continue;
			}
			if($tok->id === T_FUNCTION && $current !== null && $classDepth !== null && $depth === $classDepth + 1){
				$method = $this->parseMethod($i);
				if($method !== null){
					$current["methods"][] = $method;
					if($method["body"] !== null){
						$end = $this->matching($method["body"]);
						if($end !== null){
							$i = $end; // skip the body
						}
					}else{
						$i = $method["close"];
					}
				}
			}
		}
	}

	/**
	 * A property declaration at class level ("protected $random;", "public Foo $x = null;").
	 * @return array{name: string, var: int, type: ?array{int, int}, modifier: int, static: bool, default: ?array{int, int}, multi: bool}|null
	 */
	private function parseProperty(int $var) : ?array{
		$typeStart = null;
		$typeEnd = null;
		$modifier = null;
		$static = false;
		for($k = $this->prev($var); $k !== null; $k = $this->prev($k)){
			$t = $this->tokens[$k];
			if(in_array($t->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, \T_VAR, \T_STATIC, \T_READONLY], true)){
				$modifier ??= $k;
				$static = $static || $t->id === \T_STATIC;
				// more modifiers may follow (public static)
				$p = $this->prev($k);
				if($p !== null && in_array($this->tokens[$p]->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, \T_VAR, \T_STATIC, \T_READONLY], true)){
					continue;
				}
				break;
			}
			if($t->text === "," || $t->text === ";" || $t->text === "{" || $t->text === "}" || $t->text === "(" || $t->text === ")"){
				return $t->text === "," ? ["name" => substr($this->tokens[$var]->text, 1), "var" => $var, "type" => null, "modifier" => $var, "static" => false, "default" => null, "multi" => true] : null;
			}
			$typeStart = $k;
			$typeEnd ??= $k;
		}
		if($modifier === null){
			return null;
		}
		$default = null;
		$n = $this->next($var);
		if($n !== null && $this->tokens[$n]->text === "="){
			$depth = 0;
			$end = $n;
			for($k = $n + 1, $c = count($this->tokens); $k < $c; ++$k){
				$x = $this->tokens[$k]->text;
				if($x === "(" || $x === "[") ++$depth;
				elseif($x === ")" || $x === "]") --$depth;
				elseif($depth === 0 && ($x === "," || $x === ";")) break;
				$end = $k;
			}
			$default = [$n, $end];
		}
		$after = $default !== null ? $this->next($default[1]) : $n;
		$multi = $after !== null && $this->tokens[$after]->text === ",";
		return ["name" => substr($this->tokens[$var]->text, 1), "var" => $var, "type" => $typeStart !== null && $typeEnd !== null ? [$typeStart, $typeEnd] : null, "modifier" => $modifier, "static" => $static, "default" => $default, "multi" => $multi];
	}

	private function parseUse(int $i) : void{
		$j = $this->next($i);
		if($j === null){
			return;
		}
		$first = $this->tokens[$j];
		if($first->id === T_FUNCTION || $first->text === "const"){
			return;
		}
		// collect the statement up to ";" ("A\B\{C, D as E}" or "A\B as C, D")
		$parts = "";
		for($k = $j, $n = count($this->tokens); $k < $n && $this->tokens[$k]->text !== ";"; ++$k){
			if($this->isSignificant($k)){
				$t = $this->tokens[$k]->text;
				$parts .= strtolower($t) === "as" ? " as " : $t;
			}
		}
		$parts = trim($parts);
		if(preg_match('/^([\\\\\w]+)\\\\\{(.*)\}$/s', $parts, $m) === 1){
			$prefix = ltrim($m[1], "\\");
			foreach(explode(",", $m[2]) as $item){
				$this->addUse($prefix . "\\" . trim($item));
			}
			return;
		}
		foreach(explode(",", $parts) as $item){
			$this->addUse(trim($item));
		}
	}

	private function addUse(string $item) : void{
		$item = preg_replace('/\s+/', " ", trim($item)) ?? "";
		if($item === ""){
			return;
		}
		if(preg_match('/^(.+?) as (\w+)$/i', $item, $m) === 1){
			$this->uses[strtolower($m[2])] = ltrim(str_replace(" ", "", $m[1]), "\\");
			return;
		}
		$name = ltrim(str_replace(" ", "", $item), "\\");
		$pos = strrpos($name, "\\");
		$alias = $pos === false ? $name : substr($name, $pos + 1);
		$this->uses[strtolower($alias)] = $name;
	}

	/**
	 * @return array{name: string, params: list<array{start: int, end: int, type: ?array{int, int}, var: int, default: bool, variadic: bool}>, close: int, returnType: bool, body: ?int, visibility: ?int}|null
	 */
	private function parseMethod(int $i) : ?array{
		$j = $this->next($i);
		if($j !== null && $this->tokens[$j]->text === "&"){
			$j = $this->next($j);
		}
		if($j === null || $this->tokens[$j]->id !== T_STRING){
			return null;
		}
		$name = $this->tokens[$j]->text;
		$open = $this->next($j);
		if($open === null || $this->tokens[$open]->text !== "("){
			return null;
		}
		$close = $this->matching($open);
		if($close === null){
			return null;
		}
		// parameters split at top-level commas
		$params = [];
		$start = $open + 1;
		$depth = 0;
		for($k = $open + 1; $k <= $close; ++$k){
			$t = $this->tokens[$k]->text;
			if($t === "(" || $t === "[" || $t === "{"){
				++$depth;
			}elseif(($t === ")" || $t === "]" || $t === "}") && $k !== $close){
				--$depth;
			}
			if(($t === "," && $depth === 0) || $k === $close){
				$param = $this->parseParam($start, $k - 1);
				if($param !== null){
					$params[] = $param;
				}
				$start = $k + 1;
			}
		}
		$after = $this->next($close);
		$returnType = $after !== null && $this->tokens[$after]->text === ":";
		$body = null;
		for($k = $close + 1, $n = count($this->tokens); $k < $n; ++$k){
			$t = $this->tokens[$k]->text;
			if($t === "{"){
				$body = $k;
				break;
			}
			if($t === ";"){
				break;
			}
		}
		// visibility modifier before "function"
		$visibility = null;
		for($k = $this->prev($i); $k !== null && in_array($this->tokens[$k]->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, \T_STATIC, \T_FINAL, \T_ABSTRACT], true); $k = $this->prev($k)){
			if(in_array($this->tokens[$k]->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)){
				$visibility = $k;
			}
		}
		return ["name" => $name, "nameIndex" => $j, "open" => $open, "params" => $params, "close" => $close, "returnType" => $returnType, "body" => $body, "visibility" => $visibility];
	}

	/**
	 * @return array{start: int, end: int, type: ?array{int, int}, var: int, default: bool, variadic: bool}|null
	 */
	private function parseParam(int $from, int $to) : ?array{
		$var = null;
		$typeStart = null;
		$typeEnd = null;
		$default = false;
		$variadic = false;
		for($k = $from; $k <= $to; ++$k){
			if(!$this->isSignificant($k)){
				continue;
			}
			$t = $this->tokens[$k];
			if($t->id === T_VARIABLE && $var === null){
				$var = $k;
				continue;
			}
			if($var === null){
				if($t->id === T_ELLIPSIS){
					$variadic = true;
					continue;
				}
				if($t->text === "&" || in_array($t->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, \T_READONLY], true)){
					continue;
				}
				$typeStart ??= $k;
				$typeEnd = $k;
			}elseif($t->text === "="){
				$default = true;
			}
		}
		if($var === null){
			return null;
		}
		return ["start" => $from, "end" => $to, "type" => $typeStart !== null && $typeEnd !== null ? [$typeStart, $typeEnd] : null, "var" => $var, "default" => $default, "variadic" => $variadic];
	}
}
