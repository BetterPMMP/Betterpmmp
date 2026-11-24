<?php

/*
 * BetterPMMP - Bedrock structure files (.mcstructure), as written by /structure and structure blocks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\structure;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\PillarRotation;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;

/**
 * The format of the official game: little-endian NBT with size, a block index list (z fastest, then y,
 * then x) into a palette of block states, block entity data per index and the entities.
 * Only the first block layer is used; the second (water in waterlogged blocks) is written empty.
 */
final class McStructure{

	/**
	 * @param list<?CompoundTag> $palette block states (vanilla NBT)
	 * @param list<int> $indices palette index per block, -1 = structure void (keep what is there)
	 * @param array<int, CompoundTag> $tiles block index => block entity NBT
	 * @param list<CompoundTag> $entities entity NBT, positions relative to the structure origin
	 */
	private function __construct(
		public readonly int $sizeX,
		public readonly int $sizeY,
		public readonly int $sizeZ,
		private array $palette,
		private array $indices,
		private array $tiles,
		private array $entities
	){}

	public static function capture(World $world, Vector3 $min, Vector3 $max, bool $entities, bool $blocks) : self{
		$sizeX = (int) ($max->x - $min->x) + 1;
		$sizeY = (int) ($max->y - $min->y) + 1;
		$sizeZ = (int) ($max->z - $min->z) + 1;
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$paletteIndex = [];
		$palette = [];
		$indices = [];
		$tiles = [];
		$i = 0;
		for($x = 0; $x < $sizeX; ++$x){
			for($y = 0; $y < $sizeY; ++$y){
				for($z = 0; $z < $sizeZ; ++$z, ++$i){
					if(!$blocks){
						$indices[] = -1;
						continue;
					}
					$wx = (int) $min->x + $x;
					$wy = (int) $min->y + $y;
					$wz = (int) $min->z + $z;
					$block = $world->getBlockAt($wx, $wy, $wz);
					$stateId = $block->getStateId();
					if(!isset($paletteIndex[$stateId])){
						$paletteIndex[$stateId] = count($palette);
						try{
							$palette[] = $serializer->serialize($stateId)->toVanillaNbt();
						}catch(\Throwable){
							$palette[] = $serializer->serialize(VanillaBlocks::AIR()->getStateId())->toVanillaNbt();
						}
					}
					$indices[] = $paletteIndex[$stateId];
					$tile = $world->getTileAt($wx, $wy, $wz);
					if($tile !== null){
						$nbt = $tile->saveNBT();
						$tiles[$i] = $nbt;
					}
				}
			}
		}
		$entityList = [];
		if($entities){
			$box = new \pocketmine\math\AxisAlignedBB($min->x, $min->y, $min->z, $max->x + 1, $max->y + 1, $max->z + 1);
			foreach($world->getNearbyEntities($box) as $entity){
				if($entity instanceof \pocketmine\player\Player || !$entity->canSaveWithChunk()){
					continue;
				}
				$entityList[] = $entity->saveNBT();
			}
		}
		return new self($sizeX, $sizeY, $sizeZ, $palette, $indices, $tiles, $entityList);
	}

	public function write(Vector3 $origin) : string{
		$tileData = CompoundTag::create();
		foreach($this->tiles as $index => $nbt){
			$tileData->setTag((string) $index, CompoundTag::create()->setTag("block_entity_data", $nbt));
		}
		$layer = new ListTag(array_map(fn(int $i) => new IntTag($i), $this->indices));
		$water = new ListTag(array_map(fn() => new IntTag(-1), $this->indices));
		$root = CompoundTag::create()
			->setInt("format_version", 1)
			->setTag("size", new ListTag([new IntTag($this->sizeX), new IntTag($this->sizeY), new IntTag($this->sizeZ)]))
			->setTag("structure_world_origin", new ListTag([new IntTag((int) $origin->x), new IntTag((int) $origin->y), new IntTag((int) $origin->z)]))
			->setTag("structure", CompoundTag::create()
				->setTag("block_indices", new ListTag([$layer, $water]))
				->setTag("entities", new ListTag($this->entities, \pocketmine\nbt\NBT::TAG_Compound))
				->setTag("palette", CompoundTag::create()->setTag("default", CompoundTag::create()
					->setTag("block_palette", new ListTag(array_values($this->palette), \pocketmine\nbt\NBT::TAG_Compound))
					->setTag("block_position_data", $tileData)
				))
			);
		return (new LittleEndianNbtSerializer())->write(new TreeRoot($root));
	}

	public static function read(string $data) : self{
		$root = (new LittleEndianNbtSerializer())->read($data)->mustGetCompoundTag();
		$size = [];
		foreach($root->getListTag("size") ?? [] as $tag){
			$size[] = (int) $tag->getValue();
		}
		if(count($size) !== 3){
			throw new \InvalidArgumentException("No size");
		}
		$structure = $root->getCompoundTag("structure") ?? throw new \InvalidArgumentException("No structure");
		$layers = $structure->getListTag("block_indices");
		$indices = [];
		foreach($layers !== null && $layers->count() > 0 ? $layers->get(0) : [] as $tag){
			$indices[] = (int) $tag->getValue();
		}
		$default = $structure->getCompoundTag("palette")?->getCompoundTag("default");
		$palette = [];
		foreach($default?->getListTag("block_palette") ?? [] as $tag){
			$palette[] = $tag instanceof CompoundTag ? $tag : null;
		}
		$tiles = [];
		foreach($default?->getCompoundTag("block_position_data") ?? [] as $index => $tag){
			if($tag instanceof CompoundTag && ($entity = $tag->getCompoundTag("block_entity_data")) !== null){
				$tiles[(int) $index] = $entity;
			}
		}
		$entities = [];
		foreach($structure->getListTag("entities") ?? [] as $tag){
			if($tag instanceof CompoundTag){
				$entities[] = $tag;
			}
		}
		$origin = [];
		foreach($root->getListTag("structure_world_origin") ?? [] as $tag){
			$origin[] = (int) $tag->getValue();
		}
		$self = new self($size[0], $size[1], $size[2], $palette, $indices, $tiles, $entities);
		$self->origin = count($origin) === 3 ? new Vector3(...$origin) : new Vector3(0, 0, 0);
		return $self;
	}

	public Vector3 $origin;

	/** Size after rotating by quarter turns (x and z swap for 90 and 270 degrees). @return int[] */
	public function rotatedSize(int $turns) : array{
		return ($turns & 1) === 1 ? [$this->sizeZ, $this->sizeY, $this->sizeX] : [$this->sizeX, $this->sizeY, $this->sizeZ];
	}

	/**
	 * Places the structure with its minimum corner at $at, turned clockwise by quarter turns and mirrored
	 * ("x", "z", "xz"). integrity < 1 leaves blocks out at random (seeded).
	 */
	public function place(World $world, Vector3 $at, int $turns, string $mirror, bool $entities, bool $blocks, float $integrity, int $seed) : int{
		$turns &= 3;
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$upgrader = GlobalBlockStateHandlers::getUpgrader();
		$registry = RuntimeBlockStateRegistry::getInstance();
		$blocksByIndex = [];
		$random = new \pocketmine\utils\Random($seed);
		$placed = 0;
		$i = 0;
		[$rx, , $rz] = $this->rotatedSize($turns);
		for($x = 0; $x < $this->sizeX; ++$x){
			for($y = 0; $y < $this->sizeY; ++$y){
				for($z = 0; $z < $this->sizeZ; ++$z, ++$i){
					$p = $this->indices[$i] ?? -1;
					if(!$blocks || $p < 0 || ($integrity < 1.0 && $random->nextFloat() > $integrity)){
						continue;
					}
					if(!array_key_exists($p, $blocksByIndex)){
						$state = $this->palette[$p] ?? null;
						$block = null;
						if($state !== null){
							try{
								$block = $registry->fromStateId($deserializer->deserialize($upgrader->upgradeBlockStateNbt($state)));
							}catch(\Throwable){
								$block = null; //unknown block: left out
							}
						}
						$blocksByIndex[$p] = $block === null ? null : self::transform(clone $block, $turns, $mirror);
					}
					$block = $blocksByIndex[$p];
					if($block === null){
						continue;
					}
					[$tx, $tz] = self::transformPoint($x, $z, $this->sizeX, $this->sizeZ, $turns, $mirror);
					$wx = (int) $at->x + $tx;
					$wy = (int) $at->y + $y;
					$wz = (int) $at->z + $tz;
					if(!$world->isInWorld($wx, $wy, $wz)){
						continue;
					}
					$old = $world->getTileAt($wx, $wy, $wz);
					if($old instanceof \pocketmine\block\tile\Container){
						$old->getRealInventory()->clearAll(); //replaced, not dropped
					}
					$world->setBlockAt($wx, $wy, $wz, $block, false);
					if(isset($this->tiles[$i])){
						$created = $world->getTileAt($wx, $wy, $wz);
						if($created !== null){
							$world->removeTile($created);
							$created->close();
						}
						$nbt = clone $this->tiles[$i];
						$nbt->setInt(Tile::TAG_X, $wx)->setInt(Tile::TAG_Y, $wy)->setInt(Tile::TAG_Z, $wz);
						try{
							$tile = TileFactory::getInstance()->createFromData($world, $nbt);
							if($tile !== null){
								$world->addTile($tile);
							}
						}catch(\Throwable){
						}
					}
					++$placed;
				}
			}
		}
		if($entities){
			foreach($this->entities as $nbt){
				$pos = $nbt->getListTag(Entity::TAG_POS);
				if($pos === null || $pos->count() !== 3){
					continue;
				}
				//relative position inside the structure, then the same turn as the blocks
				$ox = (float) $pos->get(0)->getValue() - $this->origin->x;
				$oy = (float) $pos->get(1)->getValue() - $this->origin->y;
				$oz = (float) $pos->get(2)->getValue() - $this->origin->z;
				[$tx, $tz] = self::transformPointF($ox, $oz, $this->sizeX, $this->sizeZ, $turns, $mirror);
				$copy = clone $nbt;
				$copy->setTag(Entity::TAG_POS, new ListTag([new DoubleTag($at->x + $tx), new DoubleTag($at->y + $oy), new DoubleTag($at->z + $tz)]));
				$copy->removeTag("UniqueID");
				try{
					EntityFactory::getInstance()->createFromData($world, $copy)?->spawnToAll();
				}catch(\Throwable){
				}
			}
		}
		return $placed;
	}

	/** @return int[] */
	private static function transformPoint(int $x, int $z, int $sizeX, int $sizeZ, int $turns, string $mirror) : array{
		[$fx, $fz] = self::transformPointF($x + 0.5, $z + 0.5, $sizeX, $sizeZ, $turns, $mirror);
		return [(int) floor($fx), (int) floor($fz)];
	}

	/** @return float[] */
	private static function transformPointF(float $x, float $z, int $sizeX, int $sizeZ, int $turns, string $mirror) : array{
		if(str_contains($mirror, "x")){
			$x = $sizeX - $x;
		}
		if(str_contains($mirror, "z")){
			$z = $sizeZ - $z;
		}
		for($i = 0; $i < $turns; ++$i){
			[$x, $z] = [$sizeZ - $z, $x]; //clockwise seen from above
			[$sizeX, $sizeZ] = [$sizeZ, $sizeX];
		}
		return [$x, $z];
	}

	private static function transform(Block $block, int $turns, string $mirror) : Block{
		if($block instanceof HorizontalFacing || $block instanceof AnyFacing){
			$facing = $block->getFacing();
			if(Facing::axis($facing) !== Axis::Y){
				if(str_contains($mirror, "x") && Facing::axis($facing) === Axis::X){
					$facing = Facing::opposite($facing);
				}
				if(str_contains($mirror, "z") && Facing::axis($facing) === Axis::Z){
					$facing = Facing::opposite($facing);
				}
				for($i = 0; $i < $turns; ++$i){
					$facing = Facing::rotateY($facing, true);
				}
				try{
					$block->setFacing($facing);
				}catch(\Throwable){
				}
			}
		}
		if($block instanceof PillarRotation && ($turns & 1) === 1 && $block->getAxis() !== Axis::Y){
			$block->setAxis($block->getAxis() === Axis::X ? Axis::Z : Axis::X);
		}
		return $block;
	}
}
