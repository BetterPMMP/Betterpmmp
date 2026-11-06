<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\generator\normal;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\PillarRotation;
use pocketmine\block\utils\StairShape;
use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\biome\Biome;
use pocketmine\world\biome\BiomeRegistry;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\math\Facing;
use pocketmine\world\generator\biome\BiomeSelector;
use pocketmine\world\generator\Gaussian;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\noise\Simplex;
use pocketmine\world\generator\object\OreType;
use pocketmine\world\generator\populator\GroundCover;
use pocketmine\world\generator\populator\Ore;
use pocketmine\world\generator\populator\Populator;
use pocketmine\world\World;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\math\Axis;
use function ceil;
use function crc32;
use function floor;
use function fmod;
use function is_int;
use function max;
use function min;

class Normal extends Generator{

	private int $waterHeight = 62;
	/** @var Populator[] */
	private array $populators = [];
	/** @var Populator[] */
	private array $generationPopulators = [];
	private Simplex $noiseBase;
	private BiomeSelector $selector;
	private Gaussian $gaussian;
	/** @var array<string, array{size: array{int, int, int}, blocks: array<int, array{int, int, int, Block}>}> */
	private array $villageStructureCache = [];

	private const NOISE_SAMPLING_RATE_Y = 8;

	/**
	 * @throws InvalidGeneratorOptionsException
	 */
	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);

		$this->gaussian = new Gaussian(2);

		$this->noiseBase = new Simplex($this->random, 4, 1 / 4, 1 / 32);
		$this->random->setSeed($this->seed);

		$this->selector = new class($this->random) extends BiomeSelector{
			protected function lookup(float $temperature, float $rainfall) : int{
				if($rainfall < 0.25){
					if($temperature < 0.7){
						return BiomeIds::OCEAN;
					}elseif($temperature < 0.85){
						return BiomeIds::RIVER;
					}else{
						return BiomeIds::SWAMPLAND;
					}
				}elseif($rainfall < 0.60){
					if($temperature < 0.25){
						return BiomeIds::ICE_PLAINS;
					}elseif($temperature < 0.75){
						return BiomeIds::PLAINS;
					}else{
						return BiomeIds::DESERT;
					}
				}elseif($rainfall < 0.80){
					if($temperature < 0.25){
						return BiomeIds::TAIGA;
					}elseif($temperature < 0.75){
						return BiomeIds::FOREST;
					}else{
						return BiomeIds::BIRCH_FOREST;
					}
				}else{
					if($temperature < 0.20){
						return BiomeIds::EXTREME_HILLS;
					}elseif($temperature < 0.40){
						return BiomeIds::EXTREME_HILLS_EDGE;
					}else{
						return BiomeIds::RIVER;
					}
				}
			}
		};

		$this->selector->recalculate();

		$cover = new GroundCover();
		$this->generationPopulators[] = $cover;

		$ores = new Ore();
		$stone = VanillaBlocks::STONE();
		$ores->setOreTypes([
			new OreType(VanillaBlocks::COAL_ORE(), $stone, 20, 16, 0, 128),
			new OreType(VanillaBlocks::IRON_ORE(), $stone, 20, 8, 0, 64),
			new OreType(VanillaBlocks::REDSTONE_ORE(), $stone, 8, 7, 0, 16),
			new OreType(VanillaBlocks::LAPIS_LAZULI_ORE(), $stone, 1, 6, 0, 32),
			new OreType(VanillaBlocks::GOLD_ORE(), $stone, 2, 8, 0, 32),
			new OreType(VanillaBlocks::DIAMOND_ORE(), $stone, 1, 7, 0, 16),
			new OreType(VanillaBlocks::DIRT(), $stone, 20, 32, 0, 128),
			new OreType(VanillaBlocks::GRAVEL(), $stone, 10, 16, 0, 128)
		]);
		$this->populators[] = $ores;
	}

	private function pickBiome(int $x, int $z) : Biome{
		$hash = $x * 2345803 ^ $z * 9236449 ^ $this->seed;
		$hash *= $hash + 223;
		//the above operations may result in a float. This probably wasn't intended, but we need to stick with it to
		//avoid cliff edges in existing user worlds.
		//We need to mod this so it doesn't generate an error in PHP 8.5 when we cast it back to an int.
		$hash = (int) fmod($hash, 2.0 ** 63);
		$xNoise = $hash >> 20 & 3;
		$zNoise = $hash >> 22 & 3;
		if($xNoise === 3){
			$xNoise = 1;
		}
		if($zNoise === 3){
			$zNoise = 1;
		}

		return $this->selector->pickBiome($x + $xNoise - 1, $z + $zNoise - 1);
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$this->random->setSeed(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $this->seed);

		//TODO: why don't we just create and set the chunk here directly?
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");

		$bedrock = VanillaBlocks::BEDROCK()->getStateId();
		$stillWater = VanillaBlocks::WATER()->getStateId();
		$stone = VanillaBlocks::STONE()->getStateId();

		$baseX = $chunkX * Chunk::EDGE_LENGTH;
		$baseZ = $chunkZ * Chunk::EDGE_LENGTH;

		[$biomeArray, $minNoiseHeights, $maxNoiseHeights] = $this->generateBiomes($baseX, $baseZ);

		$lowestNoiseBlock = (int) floor(min($minNoiseHeights));
		$highestNoiseBlock = (int) ceil(max($maxNoiseHeights));

		//getFastNoise3D expects the inputs to be aligned with the sampling rate, otherwise the samples will be taken
		//from different coordinates than we originally used when we first implemented this generator
		$noiseMin = (int) floor($lowestNoiseBlock / self::NOISE_SAMPLING_RATE_Y) * self::NOISE_SAMPLING_RATE_Y;
		$noiseMax = (int) ceil($highestNoiseBlock / self::NOISE_SAMPLING_RATE_Y) * self::NOISE_SAMPLING_RATE_Y;

		//we only need to generate noise for the blocks which could be affected
		//outside these bounds we'll just flood-fill blocks to save time
		$noise = $this->noiseBase->getFastNoise3D(
			xSize: Chunk::EDGE_LENGTH,
			ySize: $noiseMax - $noiseMin,
			zSize: Chunk::EDGE_LENGTH,
			xSamplingRate: 4,
			ySamplingRate: self::NOISE_SAMPLING_RATE_Y,
			zSamplingRate: 4,
			x: $chunkX * Chunk::EDGE_LENGTH,
			y: $noiseMin,
			z: $chunkZ * Chunk::EDGE_LENGTH
		);

		$minNoiseSubChunk = (int) floor($noiseMin / SubChunk::EDGE_LENGTH);
		foreach($chunk->getSubChunks() as $y => $subChunk){
			if($y >= 0 && $y < $minNoiseSubChunk){
				//Everything above 0 and below noiseMin is always solid stone, which can be flood-filled instead of
				//setting the blocks one at a time - this is vastly faster
				$blocks = [new PalettedBlockArray($stone)];
			}else{
				$blocks = [];
			}
			$chunk->setSubChunk($y, new SubChunk(Block::EMPTY_STATE_ID, $blocks, clone $biomeArray));
		}

		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				$chunk->setBlockStateId($x, 0, $z, $bedrock);

				$columnIndex = World::chunkHash($x, $z);
				$minSum = $minNoiseHeights[$columnIndex];
				$maxSum = $maxNoiseHeights[$columnIndex];
				$maxBlockY = max($maxSum, $this->waterHeight);
				$smoothHeight = ($maxSum - $minSum) / 2;

				//Everything below minSum is always solid stone - we already flood-filled the subchunks below though, so
				//we only need to fill the gap in the column here
				for($y = $minNoiseSubChunk * SubChunk::EDGE_LENGTH; $y < $minSum; $y++){
					$chunk->setBlockStateId($x, $y, $z, $stone);
				}
				for($y = (int) floor($minSum); $y <= $maxBlockY; ++$y){
					//noiseValue would anyway be <= 0 above maxSum because the smoothing term is >= 1
					$noiseValue = $y > $noiseMax ?
						-1 :
						$noise[$x][$z][$y - $noiseMin] - 1 / $smoothHeight * ($y - $smoothHeight - $minSum);

					if($noiseValue > 0){
						$chunk->setBlockStateId($x, $y, $z, $stone);
					}elseif($y <= $this->waterHeight){
						$chunk->setBlockStateId($x, $y, $z, $stillWater);
					}
				}
			}
		}

		foreach($this->generationPopulators as $populator){
			$populator->populate($world, $chunkX, $chunkZ, $this->random);
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$this->random->setSeed(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $this->seed);
		foreach($this->populators as $populator){
			$populator->populate($world, $chunkX, $chunkZ, $this->random);
		}
		$this->populateVillage($world, $chunkX, $chunkZ);

		$chunk = $world->getChunk($chunkX, $chunkZ);
		$biome = BiomeRegistry::getInstance()->getBiome($chunk->getBiomeId(7, 7, 7));
		$biome->populateChunk($world, $chunkX, $chunkZ, $this->random);
	}

	/** Places a Mojang Bedrock village house template in a biome-compatible settlement. */
	private function populateVillage(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$regionSize = 16;
		$regionX = (int) floor($chunkX / $regionSize);
		$regionZ = (int) floor($chunkZ / $regionSize);
		$hash = crc32($this->seed . ":village:" . $regionX . ":" . $regionZ);
		if(($hash & 1) !== 0){
			return;
		}
		$candidateX = $regionX * $regionSize + 4 + (($hash >> 2) & 7);
		$candidateZ = $regionZ * $regionSize + 4 + (($hash >> 5) & 7);
		if($chunkX !== $candidateX || $chunkZ !== $candidateZ){
			return;
		}
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			return;
		}
		$biome = $chunk->getBiomeId(8, 0, 8);
		$biomeGroup = match($biome){
			BiomeIds::DESERT => "desert",
			BiomeIds::SAVANNA, BiomeIds::SAVANNA_PLATEAU => "savanna",
			BiomeIds::TAIGA, BiomeIds::TAIGA_HILLS => "taiga",
			BiomeIds::ICE_PLAINS => "snowy",
			BiomeIds::PLAINS, BiomeIds::FOREST, BiomeIds::BIRCH_FOREST => "plains",
			default => null
		};
		if($biomeGroup === null){
			return;
		}

		$heights = [];
		foreach([[3, 3], [12, 3], [3, 12], [12, 12], [8, 8]] as [$x, $z]){
			$y = $chunk->getHighestBlockAt($x, $z);
			if($y === null){
				return;
			}
			$ground = $world->getBlockAt(($chunkX << Chunk::COORD_BIT_SIZE) + $x, $y, ($chunkZ << Chunk::COORD_BIT_SIZE) + $z);
			if(!$ground->isSolid() || in_array($ground->getTypeId(), [BlockTypeIds::WATER, BlockTypeIds::LAVA], true)){
				return;
			}
			$heights[] = $y;
		}
		if(max($heights) - min($heights) > 3){
			return;
		}

		$baseX = $chunkX << Chunk::COORD_BIT_SIZE;
		$baseZ = $chunkZ << Chunk::COORD_BIT_SIZE;
		$baseY = (int) round(array_sum($heights) / count($heights));
		$surfaceBlock = $biomeGroup === "desert" ? VanillaBlocks::SAND() : VanillaBlocks::GRASS();
		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				$surfaceY = $chunk->getHighestBlockAt($x, $z);
				if($surfaceY === null){
					continue;
				}
				if($surfaceY > $baseY){
					for($y = $baseY + 1; $y <= $surfaceY; ++$y){
						$world->setBlockAt($baseX + $x, $y, $baseZ + $z, VanillaBlocks::AIR());
					}
				}elseif($surfaceY < $baseY - 1){
					for($y = $surfaceY + 1; $y < $baseY; ++$y){
						$world->setBlockAt($baseX + $x, $y, $baseZ + $z, VanillaBlocks::DIRT());
					}
				}
				$world->setBlockAt($baseX + $x, $baseY, $baseZ + $z, clone $surfaceBlock);
				for($y = $baseY + 1; $y <= $baseY + 10; ++$y){
					$world->setBlockAt($baseX + $x, $y, $baseZ + $z, VanillaBlocks::AIR());
				}
			}
		}
		$variants = self::getVillageHouseVariants($biomeGroup);
		$offsets = [[0, 0], [8, 0], [0, 8], [8, 8]];
		foreach($offsets as $index => [$offsetX, $offsetZ]){
			$template = $this->getVillageStructure($biomeGroup, $variants[($index + (($hash >> 8) & 3)) % count($variants)]);
			if($template === null){
				continue;
			}
			[$sizeX, $sizeY, $sizeZ] = $template["size"];
			$originX = $baseX + $offsetX + intdiv(8 - $sizeX, 2);
			$originZ = $baseZ + $offsetZ + intdiv(8 - $sizeZ, 2);
			foreach($template["blocks"] as [$x, $y, $z, $block]){
				$world->setBlockAt($originX + $x, $baseY + $y, $originZ + $z, clone $block);
			}
		}

		// Shared well, farm rows and village marker let the runtime spawn residents
		// only after a player loads the generated settlement chunk.
		$wellX = $baseX + 7;
		$wellZ = $baseZ + 7;
		for($x = 0; $x < 2; ++$x){
			for($z = 0; $z < 2; ++$z){
				$world->setBlockAt($wellX + $x, $baseY, $wellZ + $z, VanillaBlocks::COBBLESTONE());
				$world->setBlockAt($wellX + $x, $baseY + 1, $wellZ + $z, VanillaBlocks::AIR());
			}
		}
		$world->setBlockAt($wellX, $baseY + 1, $wellZ, VanillaBlocks::WATER());
		$world->setBlockAt($wellX + 1, $baseY + 1, $wellZ, VanillaBlocks::OAK_FENCE());
		$world->setBlockAt($baseX + 7, $baseY + 1, $baseZ + 6, VanillaBlocks::HAY_BALE());
		for($z = 2; $z <= 5; ++$z){
			$world->setBlockAt($baseX + 7, $baseY, $baseZ + $z, VanillaBlocks::GRAVEL());
			$world->setBlockAt($baseX + 8, $baseY, $baseZ + $z, VanillaBlocks::GRAVEL());
			$world->setBlockAt($baseX + 7, $baseY, $baseZ + 10 + $z, VanillaBlocks::FARMLAND());
			$world->setBlockAt($baseX + 7, $baseY + 1, $baseZ + 10 + $z, VanillaBlocks::WHEAT()->setAge(5));
		}
	}

	/** @return list<string> */
	private static function getVillageHouseVariants(string $biomeGroup) : array{
		return match($biomeGroup){
			"desert" => ["desert_small_house_1", "desert_small_house_2", "desert_small_house_3"],
			"savanna" => ["savanna_small_house_1", "savanna_small_house_2", "savanna_small_house_3"],
			"taiga" => ["taiga_small_house_2", "taiga_small_house_3", "taiga_small_house_4"],
			"snowy" => ["snowy_small_house_1", "snowy_small_house_2", "snowy_small_house_3"],
			default => ["plains_small_house_1", "plains_small_house_2", "plains_small_house_3"]
		};
	}

	/** @return array{size: array{int, int, int}, blocks: array<int, array{int, int, int, Block}>}|null */
	private function getVillageStructure(string $biomeGroup, string $name) : ?array{
		$key = $biomeGroup . ":" . $name;
		if(isset($this->villageStructureCache[$key])){
			return $this->villageStructureCache[$key];
		}
		$path = dirname(__DIR__, 4) . "/resources/structures/village/" . $biomeGroup . "/houses/" . $name . ".nbt";
		$compressed = @file_get_contents($path);
		$decoded = $compressed === false ? false : @gzdecode($compressed);
		if($decoded === false){
			return null;
		}
		try{
			$root = (new BigEndianNbtSerializer())->read($decoded)->mustGetCompoundTag();
			$sizeTag = $root->getListTag("size");
			$size = [];
			foreach($sizeTag ?? [] as $dimension){
				$size[] = $dimension->getValue();
			}
			if(count($size) !== 3){
				return null;
			}
			$blockRegistry = VanillaBlocks::getAll();
			$paletteBlocks = [];
			foreach($root->getListTag("palette") ?? [] as $paletteEntry){
				if(!$paletteEntry instanceof CompoundTag){
					$paletteBlocks[] = VanillaBlocks::AIR();
					continue;
				}
				$sourceName = strtoupper(str_replace("minecraft:", "", $paletteEntry->getString("Name", "air")));
				$name = match($sourceName){
					"GRASS_BLOCK" => "GRASS",
					"SNOW_BLOCK" => "SNOW",
					"STRIPPED_OAK_LOG" => "OAK_LOG",
					"STRIPPED_SPRUCE_LOG" => "SPRUCE_LOG",
				"WALL_TORCH" => "TORCH",
				"POTTED_CACTUS" => "CACTUS",
				"JIGSAW", "STRUCTURE_BLOCK" => "AIR",
				default => str_ends_with($sourceName, "_BED") ? "BED" : (str_ends_with($sourceName, "_TERRACOTTA") ? "SANDSTONE" : $sourceName)
			};
				$block = clone ($blockRegistry[$name] ?? VanillaBlocks::AIR());
				if($block instanceof \pocketmine\block\Bed && str_ends_with($sourceName, "_BED")){
					$color = strtolower(substr($sourceName, 0, -4));
					$block->setColor(match($color){
						"white" => DyeColor::WHITE, "orange" => DyeColor::ORANGE, "magenta" => DyeColor::MAGENTA,
						"light_blue" => DyeColor::LIGHT_BLUE, "yellow" => DyeColor::YELLOW, "lime" => DyeColor::LIME,
						"pink" => DyeColor::PINK, "gray" => DyeColor::GRAY, "light_gray" => DyeColor::LIGHT_GRAY,
						"cyan" => DyeColor::CYAN, "purple" => DyeColor::PURPLE, "blue" => DyeColor::BLUE,
						"brown" => DyeColor::BROWN, "green" => DyeColor::GREEN, "red" => DyeColor::RED,
						"black" => DyeColor::BLACK, default => DyeColor::RED
					});
				}
				$properties = $paletteEntry->getCompoundTag("Properties");
				if($properties !== null){
					$block = self::applyVillageBlockProperties($block, $properties);
				}
				$paletteBlocks[] = $block;
			}
			$blocks = [];
			foreach($root->getListTag("blocks") ?? [] as $blockEntry){
				if(!$blockEntry instanceof CompoundTag){
					continue;
				}
				$position = [];
				foreach($blockEntry->getListTag("pos") ?? [] as $coordinate){
					$position[] = $coordinate->getValue();
				}
				$state = $blockEntry->getInt("state", -1);
				if(count($position) === 3 && isset($paletteBlocks[$state])){
					$blocks[] = [$position[0], $position[1], $position[2], $paletteBlocks[$state]];
				}
			}
			return $this->villageStructureCache[$key] = ["size" => [$size[0], $size[1], $size[2]], "blocks" => $blocks];
		}catch(\Throwable){
			return null;
		}
	}

	private static function applyVillageBlockProperties(Block $block, CompoundTag $properties) : Block{
		$states = [];
		foreach($properties as $key => $value){
			if($value instanceof StringTag){
				$states[$key] = $value->getValue();
			}
		}
		if($block instanceof HorizontalFacing && isset($states["facing"])){
			$facing = match($states["facing"]){
				"north" => Facing::NORTH,
				"south" => Facing::SOUTH,
				"east" => Facing::EAST,
				"west" => Facing::WEST,
				default => null
			};
			if($facing !== null){
				$block->setFacing($facing);
			}
		}
		if($block instanceof PillarRotation && isset($states["axis"])){
			$block->setAxis(match($states["axis"]){"x" => Axis::X, "z" => Axis::Z, default => Axis::Y});
		}
		if($block instanceof \pocketmine\block\Stair){
			$block->setUpsideDown(($states["half"] ?? "bottom") === "top");
			$block->setShape(match($states["shape"] ?? "straight"){
				"inner_left" => StairShape::INNER_LEFT,
				"inner_right" => StairShape::INNER_RIGHT,
				"outer_left" => StairShape::OUTER_LEFT,
				"outer_right" => StairShape::OUTER_RIGHT,
				default => StairShape::STRAIGHT
			});
		}
		if($block instanceof \pocketmine\block\Door){
			$block->setTop(($states["half"] ?? "lower") === "upper");
			$block->setHingeRight(($states["hinge"] ?? "left") === "right");
			$block->setOpen(($states["open"] ?? "false") === "true");
		}
		if($block instanceof \pocketmine\block\Bed){
			$block->setHead(($states["part"] ?? "foot") === "head");
		}
		return $block;
	}

	/**
	 * @return int[][]|PalettedBlockArray[]
	 * @phpstan-return array{PalettedBlockArray, non-empty-array<int, float>, non-empty-array<int, float>}
	 */
	private function generateBiomes(int $baseX, int $baseZ) : array{
		$biomeCache = [];

		$biomeArray = new PalettedBlockArray(BiomeIds::OCEAN);

		$uniform = null;
		$padding = $this->gaussian->smoothSize;
		$start = -$padding;
		$end = Chunk::EDGE_LENGTH + $padding;
		for($x = $start; $x < $end; ++$x){
			$absoluteX = $baseX + $x;
			for($z = $start; $z < $end; ++$z){
				$absoluteZ = $baseZ + $z;

				$columnIndex = World::chunkHash($x, $z);
				$biome = $biomeCache[$columnIndex] = $this->pickBiome($absoluteX, $absoluteZ);
				$biomeId = $biome->getId();
				$uniform = match ($uniform) {
					null, $biomeId => $biomeId,
					default => false
				};

				if($x >= 0 && $x < Chunk::EDGE_LENGTH && $z >= 0 && $z < Chunk::EDGE_LENGTH){
					for($y = 0; $y < 16; $y++){
						$biomeArray->set($x, $y, $z, $biomeId);
					}
				}
			}
		}

		if($uniform === false){
			[$minHeights, $maxHeights] = $this->gaussianSmoothElevation($start, $end, $biomeCache);
		}else{
			//If all the biomes in the blurred area are the same, we can save some performance by skipping blurring
			//With the current generator as of 2025-10-23, blurring can be skipped in two-thirds of chunks
			if(!is_int($uniform)){
				throw new AssumptionFailedError();
			}
			$biome = BiomeRegistry::getInstance()->getBiome($uniform);
			/** @phpstan-var non-empty-array<int, float> $minHeights */
			$minHeights = [];
			/** @phpstan-var non-empty-array<int, float> $maxHeights */
			$maxHeights = [];

			$minElevation = $biome->getMinElevation() - 1;
			$maxElevation = $biome->getMaxElevation();
			for($x = 0; $x < Chunk::EDGE_LENGTH; $x++){
				for($z = 0; $z < Chunk::EDGE_LENGTH; $z++){
					$columnIndex = World::chunkHash($x, $z);
					$minHeights[$columnIndex] = $minElevation;
					$maxHeights[$columnIndex] = $maxElevation;
				}
			}
		}

		return [$biomeArray, $minHeights, $maxHeights];
	}

	/**
	 * @param Biome[] $biomeCache
	 * @phpstan-param array<int, Biome> $biomeCache
	 *
	 * @return float[][]
	 * @phpstan-return array{non-empty-array<int, float>, non-empty-array<int, float>}
	 */
	private function gaussianSmoothElevation(int $start, int $end, array $biomeCache) : array{
		$minHeightsX = [];
		$maxHeightsX = [];
		//blur along the X axis first
		for($x = 0; $x < Chunk::EDGE_LENGTH; $x++){
			//while we don't need to smooth the padding corners, we do need to make sure that the contributions of
			//those corners are included in Z padding, otherwise we can get artifacts at chunk corners
			for($z = $start; $z < $end; $z++){
				$columnIndex = World::chunkHash($x, $z);

				$minSum = 0;
				$maxSum = 0;

				for($sx = -$this->gaussian->smoothSize; $sx <= $this->gaussian->smoothSize; ++$sx){
					$weight = $this->gaussian->kernel1D[$sx + $this->gaussian->smoothSize];
					$adjacent = $biomeCache[World::chunkHash($x + $sx, $z)];

					$minSum += ($adjacent->getMinElevation() - 1) * $weight;
					$maxSum += $adjacent->getMaxElevation() * $weight;
				}

				$minHeightsX[$columnIndex] = $minSum / $this->gaussian->weightSum1D;
				$maxHeightsX[$columnIndex] = $maxSum / $this->gaussian->weightSum1D;
			}
		}

		/** @phpstan-var non-empty-array<int, float> $minHeights */
		$minHeights = [];
		/** @phpstan-var non-empty-array<int, float> $maxHeights */
		$maxHeights = [];

		//then the Z axis, using the blurred values from the previous loop
		for($x = 0; $x < Chunk::EDGE_LENGTH; $x++){
			for($z = 0; $z < Chunk::EDGE_LENGTH; $z++){
				$columnIndex = World::chunkHash($x, $z);

				$minSum = 0;
				$maxSum = 0;

				for($sx = -$this->gaussian->smoothSize; $sx <= $this->gaussian->smoothSize; ++$sx){
					$weight = $this->gaussian->kernel1D[$sx + $this->gaussian->smoothSize];
					$adjacentIndex = World::chunkHash($x, $z + $sx);
					$minElevation = $minHeightsX[$adjacentIndex];
					$maxElevation = $maxHeightsX[$adjacentIndex];

					$minSum += $minElevation * $weight;
					$maxSum += $maxElevation * $weight;
				}

				$minHeights[$columnIndex] = $minSum / $this->gaussian->weightSum1D;
				$maxHeights[$columnIndex] = $maxSum / $this->gaussian->weightSum1D;
			}
		}

		return [$minHeights, $maxHeights];
	}
}
