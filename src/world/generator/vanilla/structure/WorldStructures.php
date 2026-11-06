<?php

/*
 * BetterPMMP - structure lookup for loaded worlds.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\vanilla\Overworld;
use pocketmine\world\generator\vanilla\VanillaEnd;
use pocketmine\world\generator\vanilla\VanillaNether;
use pocketmine\world\World;
use function array_key_exists;
use function strtolower;

/**
 * Gives game code (eye of ender, mob spawning, ...) the same deterministic
 * structure layout the world generator used, for worlds made with one of the
 * BetterPMMP vanilla generators. Cached per world.
 */
final class WorldStructures{

	/** @var array<int, StructureManager|null> */
	private static array $managers = [];

	/** Generator class of a world, resolving aliases such as "default". */
	public static function generatorClass(World $world) : ?string{
		try{
			$name = $world->getProvider()->getWorldData()->getGenerator();
		}catch(\Throwable){
			return null;
		}
		// our own names always mean our generators, even if a plugin took a name over
		return match(strtolower($name)){
			"vanilla", "default", "betterpmmp_overworld" => Overworld::class,
			"vanilla_nether", "betterpmmp_vanillanether" => VanillaNether::class,
			"vanilla_end", "betterpmmp_vanillaend" => VanillaEnd::class,
			default => GeneratorManager::getInstance()->getGenerator($name)?->getGeneratorClass(),
		};
	}

	public static function get(World $world) : ?StructureManager{
		$id = $world->getId();
		if(array_key_exists($id, self::$managers)){
			return self::$managers[$id];
		}
		$manager = null;
		try{
			$manager = match(self::generatorClass($world)){
				Overworld::class => (new Overworld($world->getSeed(), ""))->getStructureManager(),
				VanillaNether::class => (new VanillaNether($world->getSeed(), ""))->getStructureManager(),
				VanillaEnd::class => (new VanillaEnd($world->getSeed(), ""))->getStructureManager(),
				default => null,
			};
		}catch(\Throwable){
			$manager = null;
		}
		return self::$managers[$id] = $manager;
	}

	public static function forget(World $world) : void{
		unset(self::$managers[$world->getId()]);
	}
}
