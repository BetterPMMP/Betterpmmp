<?php

/*
 * BetterPMMP - small vanilla features placed per chunk.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\utils\DirtType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds as B;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use function abs;
use function in_array;
use function sqrt;

/**
 * Monster rooms (dungeons), amethyst geodes, water and lava lakes, fossils
 * and buried treasure. All of them stay within 8 blocks of the chunk they are
 * placed for, so they fit the population area.
 */
final class UndergroundFeatures{

	private int $air;
	private int $stone;
	private int $deepslate;
	private int $water;
	private int $lava;
	/** @var int[] */
	private array $natural;

	public function __construct(){
		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->stone = VanillaBlocks::STONE()->getStateId();
		$this->deepslate = VanillaBlocks::DEEPSLATE()->getStateId();
		$this->water = VanillaBlocks::WATER()->getStateId();
		$this->lava = VanillaBlocks::LAVA()->getStateId();
		$this->natural = [
			$this->stone, $this->deepslate,
			VanillaBlocks::GRANITE()->getStateId(), VanillaBlocks::DIORITE()->getStateId(), VanillaBlocks::ANDESITE()->getStateId(),
			VanillaBlocks::TUFF()->getStateId(), VanillaBlocks::DIRT()->getStateId(), VanillaBlocks::GRAVEL()->getStateId(),
			VanillaBlocks::GRASS()->getStateId(), VanillaBlocks::SAND()->getStateId(), VanillaBlocks::SANDSTONE()->getStateId(),
			VanillaBlocks::CLAY()->getStateId(), VanillaBlocks::RED_SAND()->getStateId(), VanillaBlocks::MUD()->getStateId(),
			VanillaBlocks::PODZOL()->getStateId(), VanillaBlocks::SNOW()->getStateId(), VanillaBlocks::CALCITE()->getStateId(),
			VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE)->getStateId(), VanillaBlocks::MYCELIUM()->getStateId(),
		];
	}

	private function isSolidNatural(BlockWriter $w, int $x, int $y, int $z) : bool{
		return in_array($w->get($x, $y, $z), $this->natural, true);
	}

	public function populate(BlockWriter $w, int $baseX, int $baseZ, Random $r) : void{
		//vanilla: 8 dungeon attempts in the upper range + 4 deep ones
		for($i = 0; $i < 12; ++$i){
			$y = $i < 8 ? $r->nextRange(0, 120) : $r->nextRange(-58, -1);
			$this->dungeon($w, $baseX + $r->nextBoundedInt(16), $y, $baseZ + $r->nextBoundedInt(16), $r);
		}
		if($r->nextBoundedInt(24) === 0){
			$this->geode($w, $baseX + 8, $r->nextRange(-56, 30), $baseZ + 8, $r);
		}
		if($r->nextBoundedInt(9) === 0){
			$this->lake($w, $baseX + 8, $r->nextRange(-56, 50), $baseZ + 8, $r, true); //underground lava lake
		}
		if($r->nextBoundedInt(64) === 0){
			$this->fossil($w, $baseX + 8, $r->nextRange(-50, 40), $baseZ + 8, $r);
		}
	}

	/** Surface lakes and buried treasure need the surface biome. */
	public function populateSurface(BlockWriter $w, int $baseX, int $baseZ, int $biome, Random $r) : void{
		$x = $baseX + 8;
		$z = $baseZ + 8;
		$top = $w->highest($x, $z);
		if($top === null){
			return;
		}
		$isDesert = $biome === B::DESERT || $biome === B::MESA || $biome === B::MESA_BRYCE;
		if(!$isDesert && $r->nextBoundedInt(80) === 0 && $top > OverworldClimate::SEA_LEVEL){
			$this->lake($w, $x, $top - 3, $z, $r, false);
		}elseif($r->nextBoundedInt(400) === 0 && $top > OverworldClimate::SEA_LEVEL){
			$this->lake($w, $x, $top - 3, $z, $r, true);
		}
		if(($biome === B::BEACH || $biome === B::COLD_BEACH || $biome === B::STONE_BEACH) && $r->nextBoundedInt(100) === 0){
			$this->buriedTreasure($w, $baseX + 9, $baseZ + 9, $r);
		}
		if(($biome === B::DESERT || $biome === B::SWAMPLAND) && $r->nextBoundedInt(64) === 0){
			$this->fossil($w, $x, $top - 15, $z, $r);
		}
	}

	private function dungeon(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$rx = 2 + $r->nextBoundedInt(2); //inner half-sizes 2..3 -> 5x5 to 7x7 inside
		$rz = 2 + $r->nextBoundedInt(2);
		//vanilla validity: solid floor and ceiling, 1..5 openings at floor level into air
		$openings = 0;
		for($dx = -$rx - 1; $dx <= $rx + 1; ++$dx){
			for($dz = -$rz - 1; $dz <= $rz + 1; ++$dz){
				if(!$this->isSolidNatural($w, $x + $dx, $y - 1, $z + $dz) || !$this->isSolidNatural($w, $x + $dx, $y + 4, $z + $dz)){
					return;
				}
				$wall = abs($dx) === $rx + 1 || abs($dz) === $rz + 1;
				if($wall && $w->get($x + $dx, $y, $z + $dz) === $this->air && $w->get($x + $dx, $y + 1, $z + $dz) === $this->air){
					++$openings;
				}
			}
		}
		if($openings < 1 || $openings > 5){
			return;
		}
		$cobble = VanillaBlocks::COBBLESTONE();
		$mossy = VanillaBlocks::MOSSY_COBBLESTONE();
		$airBlock = VanillaBlocks::AIR();
		for($dx = -$rx - 1; $dx <= $rx + 1; ++$dx){
			for($dz = -$rz - 1; $dz <= $rz + 1; ++$dz){
				for($dy = -1; $dy <= 4; ++$dy){
					$wall = abs($dx) === $rx + 1 || abs($dz) === $rz + 1 || $dy === -1 || $dy === 4;
					if(!$wall){
						$w->setBlock($x + $dx, $y + $dy, $z + $dz, $airBlock);
					}elseif($dy === -1){
						$w->setBlock($x + $dx, $y + $dy, $z + $dz, $r->nextBoundedInt(4) === 0 ? $cobble : $mossy);
					}elseif($w->get($x + $dx, $y + $dy, $z + $dz) !== $this->air){
						$w->setBlock($x + $dx, $y + $dy, $z + $dz, $cobble);
					}
				}
			}
		}
		//up to two chests against walls
		for($c = 0; $c < 2; ++$c){
			for($attempt = 0; $attempt < 3; ++$attempt){
				$cx = $x + $r->nextRange(-$rx, $rx);
				$cz = $z + $r->nextRange(-$rz, $rz);
				$walls = 0;
				foreach(Facing::HORIZONTAL as $face){
					[$ox, , $oz] = Facing::OFFSET[$face];
					if($w->get($cx + $ox, $y, $cz + $oz) !== $this->air){
						++$walls;
					}
				}
				if($walls === 1 && $w->get($cx, $y, $cz) === $this->air){
					$w->setBlock($cx, $y, $cz, VanillaBlocks::CHEST(), LootTables::chestNbt("simple_dungeon", $r));
					break;
				}
			}
		}
		$mob = ["minecraft:skeleton", "minecraft:zombie", "minecraft:zombie", "minecraft:spider"][$r->nextBoundedInt(4)];
		$w->setBlock($x, $y, $z, VanillaBlocks::MONSTER_SPAWNER(), LootTables::spawnerNbt($mob));
	}

	private function geode(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$radius = 4.0 + $r->nextFloat() * 2.5;
		$basalt = VanillaBlocks::SMOOTH_BASALT();
		$calcite = VanillaBlocks::CALCITE();
		$amethyst = VanillaBlocks::AMETHYST();
		$budding = VanillaBlocks::BUDDING_AMETHYST();
		$airBlock = VanillaBlocks::AIR();
		$crack = $r->nextBoundedInt(5) === 0 ? Facing::HORIZONTAL[$r->nextBoundedInt(4)] : null;
		$buddingSpots = [];
		$ri = (int) $radius + 2;
		for($dx = -$ri; $dx <= $ri; ++$dx){
			for($dy = -$ri; $dy <= $ri; ++$dy){
				for($dz = -$ri; $dz <= $ri; ++$dz){
					$d = sqrt($dx * $dx + $dy * $dy * 1.1 + $dz * $dz);
					if($d > $radius + 1.6){
						continue;
					}
					$px = $x + $dx;
					$py = $y + $dy;
					$pz = $z + $dz;
					if($crack !== null){
						[$cx, , $cz] = Facing::OFFSET[$crack];
						if(($dx * $cx + $dz * $cz) > $radius - 2 && abs($dy) <= 1 && abs($dx * $cz + $dz * $cx) <= 1){
							$w->setBlock($px, $py, $pz, $airBlock);
							continue;
						}
					}
					if($d <= $radius - 1.6){
						$w->setBlock($px, $py, $pz, $airBlock);
					}elseif($d <= $radius - 0.6){
						if($r->nextBoundedInt(12) === 0){
							$w->setBlock($px, $py, $pz, $budding);
							$buddingSpots[] = [$px, $py, $pz];
						}else{
							$w->setBlock($px, $py, $pz, $amethyst);
						}
					}elseif($d <= $radius + 0.4){
						$w->setBlock($px, $py, $pz, $calcite);
					}else{
						$w->setBlock($px, $py, $pz, $basalt);
					}
				}
			}
		}
		//clusters grow from budding amethyst into the hollow
		foreach($buddingSpots as [$bx, $by, $bz]){
			foreach(Facing::ALL as $face){
				[$ox, $oy, $oz] = Facing::OFFSET[$face];
				if($r->nextBoundedInt(3) === 0 && $w->get($bx + $ox, $by + $oy, $bz + $oz) === $this->air){
					$w->setBlock($bx + $ox, $by + $oy, $bz + $oz, VanillaBlocks::AMETHYST_CLUSTER()->setFacing($face));
					break;
				}
			}
		}
	}

	/** Vanilla-style lake: overlapping ellipsoids, liquid in the lower half, air above. */
	private function lake(BlockWriter $w, int $x, int $y, int $z, Random $r, bool $lava) : void{
		$shape = [];
		$blobs = 4 + $r->nextBoundedInt(4);
		for($i = 0; $i < $blobs; ++$i){
			$sx = $r->nextFloat() * 6 + 3;
			$sy = $r->nextFloat() * 4 + 2;
			$sz = $r->nextFloat() * 6 + 3;
			$cx = $r->nextFloat() * (16 - $sx - 2) + 1 + $sx / 2 - 8;
			$cy = $r->nextFloat() * (8 - $sy - 4) + 2 + $sy / 2 - 4;
			$cz = $r->nextFloat() * (16 - $sz - 2) + 1 + $sz / 2 - 8;
			for($dx = -7; $dx <= 7; ++$dx){
				for($dz = -7; $dz <= 7; ++$dz){
					for($dy = -3; $dy <= 3; ++$dy){
						$fx = ($dx - $cx) / ($sx / 2);
						$fy = ($dy - $cy) / ($sy / 2);
						$fz = ($dz - $cz) / ($sz / 2);
						if($fx * $fx + $fy * $fy + $fz * $fz < 1){
							$shape["$dx:$dy:$dz"] = true;
						}
					}
				}
			}
		}
		if(count($shape) === 0){
			return;
		}
		//reject if the lake would break into air on the sides/below, or liquid above
		foreach($shape as $key => $_){
			[$dx, $dy, $dz] = array_map('intval', explode(":", $key));
			foreach(Facing::ALL as $face){
				[$ox, $oy, $oz] = Facing::OFFSET[$face];
				if(isset($shape[($dx + $ox) . ":" . ($dy + $oy) . ":" . ($dz + $oz)])){
					continue;
				}
				$id = $w->get($x + $dx + $ox, $y + $dy + $oy, $z + $dz + $oz);
				if($dy + $oy <= 0 && $id === $this->air){
					return;
				}
				if($id === $this->water && !$lava || $id === $this->lava){
					return;
				}
			}
		}
		$liquid = $lava ? VanillaBlocks::LAVA() : VanillaBlocks::WATER();
		$airBlock = VanillaBlocks::AIR();
		$border = VanillaBlocks::STONE();
		foreach($shape as $key => $_){
			[$dx, $dy, $dz] = array_map('intval', explode(":", $key));
			$w->setBlock($x + $dx, $y + $dy, $z + $dz, $dy <= 0 ? $liquid : $airBlock);
		}
		if($lava){
			//lava lakes are lined with stone where they touch the ground
			foreach($shape as $key => $_){
				[$dx, $dy, $dz] = array_map('intval', explode(":", $key));
				if($dy > 0){
					continue;
				}
				foreach(Facing::ALL as $face){
					[$ox, $oy, $oz] = Facing::OFFSET[$face];
					if(!isset($shape[($dx + $ox) . ":" . ($dy + $oy) . ":" . ($dz + $oz)]) && $oy <= 0 && $this->isSolidNatural($w, $x + $dx + $ox, $y + $dy + $oy, $z + $dz + $oz) && $r->nextBoundedInt(2) === 0){
						$w->setBlock($x + $dx + $ox, $y + $dy + $oy, $z + $dz + $oz, $border);
					}
				}
			}
		}else{
			//grass around water lakes
			foreach($shape as $key => $_){
				[$dx, $dy, $dz] = array_map('intval', explode(":", $key));
				if($dy === 1 && $w->get($x + $dx, $y + $dy - 1, $z + $dz) === VanillaBlocks::DIRT()->getStateId()){
					$w->setBlock($x + $dx, $y + $dy - 1, $z + $dz, VanillaBlocks::GRASS());
				}
			}
		}
	}

	private function fossil(BlockWriter $w, int $x, int $y, int $z, Random $r) : void{
		$bone = VanillaBlocks::BONE_BLOCK();
		$deep = $y < 0;
		$coal = $deep ? VanillaBlocks::DIAMOND_ORE() : VanillaBlocks::COAL_ORE();
		$alongX = $r->nextBoolean();
		$length = 7 + $r->nextBoundedInt(6);
		//spine with ribs, partly replaced by ore like vanilla fossils
		for($i = 0; $i < $length; ++$i){
			$sx = $x + ($alongX ? $i - intdiv($length, 2) : 0);
			$sz = $z + ($alongX ? 0 : $i - intdiv($length, 2));
			$block = $r->nextBoundedInt(10) === 0 ? $coal : $bone;
			if($this->isSolidNatural($w, $sx, $y, $sz)){
				$w->setBlock($sx, $y, $sz, $block);
			}
			if($i % 2 === 0 && $i > 1 && $i < $length - 2){
				for($h = 1; $h <= 3; ++$h){
					foreach([-1, 1] as $side){
						$rx = $alongX ? $sx : $sx + $side * ($h === 3 ? 1 : 2);
						$rz = $alongX ? $sz + $side * ($h === 3 ? 1 : 2) : $sz;
						if($this->isSolidNatural($w, $rx, $y + $h - 1, $rz) && $r->nextBoundedInt(5) !== 0){
							$w->setBlock($rx, $y + $h - 1, $rz, $bone);
						}
					}
				}
			}
		}
	}

	private function buriedTreasure(BlockWriter $w, int $x, int $z, Random $r) : void{
		$y = $w->highest($x, $z);
		if($y === null){
			return;
		}
		for($depth = 0; $depth < 6; ++$depth){
			$id = $w->get($x, $y - $depth, $z);
			if($id === VanillaBlocks::SAND()->getStateId() || $id === VanillaBlocks::SANDSTONE()->getStateId() || $id === VanillaBlocks::GRAVEL()->getStateId() || $id === $this->stone){
				if($depth >= 2){
					$w->setBlock($x, $y - $depth, $z, VanillaBlocks::CHEST(), LootTables::chestNbt("buried_treasure", $r));
					return;
				}
			}
		}
	}
}
