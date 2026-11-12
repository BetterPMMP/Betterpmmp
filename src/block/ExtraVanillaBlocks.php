<?php

/*
 * BetterPMMP - registers every Bedrock block type the core has no class for.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\convert\BlockSerializerDeserializerRegistrar;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_key_exists;
use function count;
use function file_get_contents;
use function json_decode;
use function max;
use function min;
use function round;
use function str_contains;
use function str_replace;
use function ucwords;

/**
 * Builds a {@link GenericBedrockBlock} for every block type in the Bedrock
 * palette that the core cannot handle yet (see {@link ExtraVanillaBlockNames}),
 * registers it in the runtime registry and maps its states for loading,
 * saving and network translation. Types the core already supports partially
 * only get the states the core cannot read, so existing behaviour is kept.
 *
 * Type IDs are fixed (BASE_TYPE_ID + list index) so every thread - including
 * the world generator workers - assigns identical state IDs.
 */
final class ExtraVanillaBlocks{

	public const BASE_TYPE_ID = 30000;

	/** @var array<string, GenericBedrockBlock>|null */
	private static ?array $blocks = null;
	private static bool $building = false;
	private static bool $mapped = false;

	/**
	 * @return array<string, GenericBedrockBlock> Bedrock name => block
	 */
	/**
	 * Blocks with real behaviour instead of the generic one: name => [block class, tile class].
	 * @phpstan-var array<string, array{class-string<GenericBedrockBlock>, class-string<\pocketmine\block\tile\Tile>|null}>
	 */
	private const BEHAVIOURS = [
		"minecraft:piston" => [VanillaPiston::class, \pocketmine\block\tile\PistonArm::class],
		"minecraft:sticky_piston" => [VanillaPiston::class, \pocketmine\block\tile\PistonArm::class],
		"minecraft:piston_arm_collision" => [VanillaPistonArm::class, null],
		"minecraft:sticky_piston_arm_collision" => [VanillaPistonArm::class, null],
		"minecraft:observer" => [VanillaObserver::class, null],
		"minecraft:dispenser" => [VanillaDispenser::class, \pocketmine\block\tile\Dispenser::class],
		"minecraft:dropper" => [VanillaDispenser::class, \pocketmine\block\tile\Dropper::class],
		"minecraft:target" => [VanillaTarget::class, null],
		"minecraft:end_gateway" => [VanillaEndGateway::class, null],
		"minecraft:sculk_shrieker" => [VanillaSculkShrieker::class, null],
		"minecraft:grindstone" => [VanillaGrindstone::class, null],
		"minecraft:suspicious_sand" => [VanillaSuspiciousBlock::class, null],
		"minecraft:suspicious_gravel" => [VanillaSuspiciousBlock::class, null],
		"minecraft:trial_spawner" => [VanillaTrialSpawner::class, null],
		"minecraft:vault" => [VanillaVault::class, null],
		"minecraft:creaking_heart" => [VanillaCreakingHeart::class, null],
		"minecraft:dried_ghast" => [VanillaDriedGhast::class, null],
		"minecraft:sculk_sensor" => [VanillaSculkSensor::class, null],
		"minecraft:crafter" => [VanillaCrafter::class, \pocketmine\block\tile\Crafter::class],
		"minecraft:frog_spawn" => [VanillaHatchable::class, null],
		"minecraft:composter" => [VanillaComposter::class, null],
		"minecraft:sniffer_egg" => [VanillaHatchable::class, null],
		"minecraft:calibrated_sculk_sensor" => [VanillaSculkSensor::class, null],
		"minecraft:open_eyeblossom" => [VanillaEyeblossom::class, null],
		"minecraft:closed_eyeblossom" => [VanillaEyeblossom::class, null],
		"minecraft:beehive" => [VanillaBeehive::class, null],
		"minecraft:bee_nest" => [VanillaBeehive::class, null],
		"minecraft:powder_snow" => [VanillaPowderSnow::class, null],
		"minecraft:honey_block" => [VanillaHoneyBlock::class, null],
		"minecraft:pointed_dripstone" => [VanillaPointedDripstone::class, null],
		"minecraft:turtle_egg" => [VanillaTurtleEgg::class, null],
		"minecraft:scaffolding" => [VanillaScaffolding::class, null],
		"minecraft:cherry_sapling" => [VanillaSapling::class, null],
		"minecraft:pale_oak_sapling" => [VanillaSapling::class, null],
		"minecraft:poplar_sapling" => [VanillaSapling::class, null],
		"minecraft:kelp" => [VanillaKelp::class, null],
	];

	public static function getAll() : array{
		if(self::$blocks !== null){
			return self::$blocks;
		}
		if(self::$building){
			return [];
		}
		self::$building = true;
		try{
			self::$blocks = self::build();
		}finally{
			self::$building = false;
		}
		self::registerMappings(GlobalBlockStateHandlers::getRegistrar());
		return self::$blocks;
	}

	public static function get(string $bedrockName) : ?GenericBedrockBlock{
		$block = self::getAll()[$bedrockName] ?? null;
		return $block === null ? null : clone $block;
	}

	/** Called by GlobalBlockStateHandlers after the vanilla mappings were registered. */
	public static function ensureMapped() : void{
		if(self::$building || self::$mapped){
			return;
		}
		self::getAll();
	}

	/** @return array<string, GenericBedrockBlock> */
	private static function build() : array{
		$wanted = array_flip(ExtraVanillaBlockNames::NAMES);
		$dictionary = BlockStateDictionary::loadFromString(
			Filesystem::fileGetContents(BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT),
			Filesystem::fileGetContents(BedrockDataFiles::BLOCK_STATE_META_MAP_JSON)
		);
		/** @var array<string, array<string, mixed>> $properties */
		$properties = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BLOCK_PROPERTIES_TABLE_JSON), true, flags: JSON_THROW_ON_ERROR);
		/** @var array<string, string> $itemMap */
		$itemMap = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BLOCK_ID_TO_ITEM_ID_MAP_JSON), true, flags: JSON_THROW_ON_ERROR);

		$deserializer = GlobalBlockStateHandlers::getRegistrar()->deserializer;

		/** @var array<string, list<array{BlockStateData, int}>> $grouped */
		$grouped = [];
		foreach($dictionary->getStates() as $entry){
			$name = $entry->getStateName();
			if(!isset($wanted[$name])){
				continue;
			}
			$data = $entry->generateStateData();
			//keep states the core already understands with the core
			$existing = $deserializer->getDeserializerForId($name);
			if($existing !== null){
				try{
					$reader = new BlockStateReader($data);
					$existing($reader);
					$reader->checkUnreadProperties();
					continue;
				}catch(\Throwable){
					//not supported by the core: handled generically
				}
			}
			$grouped[$name][] = [$data, $entry->getMeta()];
		}

		$blocks = [];
		foreach(ExtraVanillaBlockNames::NAMES as $index => $name){
			if(!isset($grouped[$name])){
				continue;
			}
			$states = [];
			$default = 0;
			foreach($grouped[$name] as $i => [$data, $meta]){
				$states[] = $data;
				if($meta === 0){
					$default = $i;
				}
			}
			$props = $properties[$name] ?? [];
			$hardness = (float) ($props["hardness"] ?? 1.0);
			$opacity = (float) ($props["opacity"] ?? 1.0);
			$brightness = (float) ($props["brightness"] ?? 0.0);
			$solid = !($hardness <= 0.0 && $opacity <= 0.0) && !self::isNonSolid($name);
			$transparent = $opacity < 1.0;
			$light = (int) max(0, min(15, round($brightness * 15)));
			$hasItem = array_key_exists($name, $itemMap) && !self::isTechnical($name);
			$breakInfo = $hardness < 0 ? BlockBreakInfo::indestructible() : new BlockBreakInfo($hardness, self::toolType($name), 0, (float) ($props["blastResistance"] ?? $hardness * 5));
			[$class, $tileClass] = self::BEHAVIOURS[$name] ?? [GenericBedrockBlock::class, null];
			$blocks[$name] = new $class(
				new BlockIdentifier(self::BASE_TYPE_ID + $index, $tileClass),
				self::displayName($name),
				new BlockTypeInfo($breakInfo),
				$name,
				$states,
				$default,
				$solid,
				$transparent,
				$light,
				$hasItem
			);
		}
		return $blocks;
	}

	private static function registerMappings(BlockSerializerDeserializerRegistrar $registrar) : void{
		if(self::$mapped || self::$blocks === null){
			return;
		}
		self::$mapped = true;
		foreach(self::$blocks as $name => $block){
			$registrar->serializer->map($block, fn(GenericBedrockBlock $b) : BlockStateData => $b->getStateData());
			$existing = $registrar->deserializer->getDeserializerForId($name);
			$registrar->deserializer->map($name, function(BlockStateReader $in) use ($block, $existing, $name) : Block{
				$data = $in->readAllStates();
				$index = $block->findStateIndex($data);
				if($index !== null){
					return (clone $block)->setStateIndex($index);
				}
				if($existing !== null){
					return $existing(new BlockStateReader($data));
				}
				throw new \pocketmine\data\bedrock\block\BlockStateDeserializeException("Unknown state for $name");
			});
		}
	}

	/** Plants, carpets and similar blocks without a collision box. */
	private static function isNonSolid(string $name) : bool{
		foreach(["sapling", "propagule", "_carpet", "seagrass", "kelp", "bush", "wildflowers", "leaf_litter", "dry_grass", "eyeblossom", "dandelion", "shrub", "sculk_vein",
			"hanging_moss", "frog_spawn", "bubble_column", "end_gateway", "_button", "pressure_plate", "_sign", "icicle", "ice_crystal", "moving_block"] as $part){
			if(str_contains($name, $part)){
				return true;
			}
		}
		return false;
	}

	/** Technical blocks players should not obtain as items. */
	private static function isTechnical(string $name) : bool{
		return match($name){
			"minecraft:moving_block", "minecraft:piston_arm_collision", "minecraft:sticky_piston_arm_collision",
			"minecraft:bubble_column", "minecraft:end_gateway", "minecraft:border_block", "minecraft:allow", "minecraft:deny",
			"minecraft:jigsaw", "minecraft:structure_block", "minecraft:command_block", "minecraft:chain_command_block",
			"minecraft:repeating_command_block", "minecraft:camera", "minecraft:chalkboard" => true,
			default => false
		};
	}

	private static function toolType(string $name) : int{
		foreach(["shelf", "poplar", "bee", "composter", "chest", "chalkboard"] as $part){
			if(str_contains($name, $part)){
				return BlockToolType::AXE;
			}
		}
		foreach(["moss", "wool", "seagrass", "kelp", "bush", "grass", "sapling"] as $part){
			if(str_contains($name, $part)){
				return BlockToolType::NONE;
			}
		}
		if(str_contains($name, "suspicious") || str_contains($name, "powder_snow")){
			return BlockToolType::SHOVEL;
		}
		return BlockToolType::PICKAXE;
	}

	private static function displayName(string $name) : string{
		return ucwords(str_replace("_", " ", str_replace("minecraft:", "", $name)));
	}
}
