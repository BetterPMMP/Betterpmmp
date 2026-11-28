<?php

declare(strict_types=1);

namespace leondev\betterwe;

use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_unshift;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

final class ItemIds{

	public static function parse(string $text) : ?Item{
		$text = strtolower(trim($text));
		if(str_starts_with($text, "minecraft:")){
			$text = substr($text, 10);
		}
		if($text === ""){
			return null;
		}
		try{
			$item = StringToItemParser::getInstance()->parse($text);
			if($item !== null){
				return $item;
			}
			if(preg_match('/^\d+(?::\d+)?$/D', $text) === 1){
				return LegacyStringToItemParser::getInstance()->parse($text);
			}
		}catch(\Throwable){
			return null;
		}
		return null;
	}

	public static function name(Item $item) : ?string{
		$aliases = StringToItemParser::getInstance()->lookupAliases($item);
		try{
			array_unshift($aliases, GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName());
		}catch(\Throwable){
		}
		foreach($aliases as $alias){
			$short = str_starts_with($alias, "minecraft:") ? substr($alias, 10) : $alias;
			if(preg_match('/^[a-z0-9_]+$/D', $short) !== 1){
				continue;
			}
			$id = "minecraft:" . $short;
			$parsed = self::parse($id);
			if($parsed !== null && $parsed->getStateId() === $item->getStateId()){
				return $id;
			}
		}
		return null;
	}
}
