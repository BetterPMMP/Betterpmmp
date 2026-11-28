<?php

declare(strict_types=1);

namespace leondev\bettermultiworld;

use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

final class VoidGenerator extends Generator{

	public const NAME = "bettermultiworld_void";

	public static function register() : void{
		$manager = GeneratorManager::getInstance();
		if($manager->getGenerator(self::NAME) === null){
			$manager->addGenerator(self::class, self::NAME, static fn(string $preset) : ?InvalidGeneratorOptionsException => null, fast: true);
		}
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$world->setChunk($chunkX, $chunkZ, new Chunk([], false));
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
	}
}
