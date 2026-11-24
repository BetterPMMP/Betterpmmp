<?php

/*
 * BetterPMMP - end cities and end ships.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Random;
use pocketmine\world\generator\vanilla\LootTables;
use function min;

/**
 * End city on the outer islands, built from the vanilla templates the way vanilla assembles them
 * ({@link EndCityLayout}): base house, towers, bridges, further houses, fat towers and at most one end
 * ship with the elytra. Chests hold the end city loot.
 * Placement: spacing 20, separation 11, triangular; island surface >= 60 and more than 1024 blocks
 * from the centre, like vanilla.
 * (Shulkers are entities, which the generator cannot place yet.)
 */
final class EndCity extends Structure{

	private const TEMPLATES = [
		"base_floor", "base_roof", "bridge_end", "bridge_gentle_stairs", "bridge_piece", "bridge_steep_stairs",
		"fat_tower_base", "fat_tower_middle", "fat_tower_top", "second_floor_1", "second_floor_2", "second_roof",
		"ship", "third_floor_1", "third_floor_2", "third_roof", "tower_base", "tower_floor", "tower_piece", "tower_top"
	];

	public function getName() : string{ return "end_city"; }
	protected function getSpacing() : int{ return 20; }
	protected function getSeparation() : int{ return 11; }
	protected function getSalt() : int{ return 10387313; }
	//bridges and the ship reach up to about 135 blocks from the first house
	public function getMaxRadiusChunks() : int{ return 9; }
	protected function isTriangular() : bool{ return true; }

	protected function createStart(StructureContext $ctx, int $chunkX, int $chunkZ, Random $random) : ?StructureStart{
		$x = ($chunkX << 4) + 7;
		$z = ($chunkZ << 4) + 7;
		if($x * $x + $z * $z < 1024 * 1024){
			return null;
		}
		$rotation = $random->nextBoundedInt(4);
		//vanilla: lowest of the four corners of a 5x5 square on the side the city grows to
		$dx = $rotation === 1 || $rotation === 2 ? -5 : 5;
		$dz = $rotation === 2 || $rotation === 3 ? -5 : 5;
		$y = min($ctx->heightAt($x, $z), $ctx->heightAt($x, $z + $dz), $ctx->heightAt($x + $dx, $z), $ctx->heightAt($x + $dx, $z + $dz));
		if($y < 60){
			return null;
		}
		$sizes = [];
		foreach(self::TEMPLATES as $name){
			$template = JavaTemplate::get("end_city/" . $name);
			if($template === null){
				return null; //templates missing: no city rather than a broken one
			}
			$sizes[$name] = [$template->sizeX, $template->sizeY, $template->sizeZ];
		}
		$start = new StructureStart($this->getName(), $chunkX, $chunkZ, true);
		$sentries = 0;
		foreach(EndCityLayout::generate($sizes, $random, $x, $y, $z, $rotation) as $part){
			[$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $part["box"];
			//"Sentry" markers of the templates are where vanilla puts the shulkers
			foreach(JavaTemplate::get("end_city/" . $part["name"])?->blocks ?? [] as [$bx, $by, $bz, , , $marker]){
				if($marker === "Sentry"){
					[$rx, $rz] = EndCityLayout::rotate($bx, $bz, $part["rot"]);
					$start->markers["mob:shulker:" . $sentries++] = [$part["x"] + $rx, $part["y"] + $by, $part["z"] + $rz];
				}
			}
			$start->add(new StructurePiece($minX, $minY, $minZ, $maxX, $maxY, $maxZ, function(StructureCanvas $c, Random $r) use ($part) : void{
				$this->place($c, $r, $part["name"], $part["x"], $part["y"], $part["z"], $part["rot"], $part["overwrite"]);
			}, $random->nextInt()));
		}
		return $start;
	}

	private function place(StructureCanvas $c, Random $r, string $name, int $x, int $y, int $z, int $rotation, bool $overwrite) : void{
		$template = JavaTemplate::get("end_city/" . $name);
		if($template === null){
			return;
		}
		foreach($template->blocks as [$bx, $by, $bz, $blockName, $properties, $marker]){
			[$rx, $rz] = EndCityLayout::rotate($bx, $bz, $rotation);
			$wx = $x + $rx;
			$wz = $z + $rz;
			if(!$c->inside($wx, $wz)){
				continue;
			}
			$wy = $y + $by;
			if($blockName === "structure_block"){
				if($marker === "Elytra"){
					//the elytra of the ship: an item frame on the wall (a block on Bedrock)
					$tile = null;
					$elytra = ExtraVanillaItems::get("minecraft:elytra");
					if($elytra !== null){
						$tile = CompoundTag::create()->setTag("Item", $elytra->nbtSerialize());
					}
					try{
						$c->set($wx, $wy, $wz, VanillaBlocks::ITEM_FRAME()->setFacing(JavaTemplate::facing("south", $rotation) ?? Facing::SOUTH)->setHasMap(false), $tile);
					}catch(\Throwable){
						//no frame rather than a failed chunk
					}
				}
				continue; //"Chest" marks the chest below it, "Sentry" a shulker
			}
			if($blockName === "air" && !$overwrite){
				continue;
			}
			$block = JavaTemplate::block($blockName, $properties, $rotation);
			if($block === null){
				continue;
			}
			if($blockName === "chest"){
				$c->set($wx, $wy, $wz, $block, LootTables::chestNbt("end_city_treasure", $r));
			}else{
				$c->set($wx, $wy, $wz, $block);
			}
		}
	}
}
