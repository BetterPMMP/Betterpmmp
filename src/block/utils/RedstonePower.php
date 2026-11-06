<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Lever;
use pocketmine\block\Redstone;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\RedstoneTorch;
use pocketmine\block\RedstoneWire;
use pocketmine\block\SimplePressurePlate;
use pocketmine\block\WeightedPressurePlate;
use pocketmine\inventory\Inventory;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * BetterPMMP's server-side signal rules. Sides are always OUTPUT directions.
 * Diode getFacing() points towards the input, as encoded by Bedrock's state.
 * No recursive conduction: a solid block only relays a direct strong signal.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */
final class RedstonePower{

	public static function isConductor(Block $block) : bool{
		// This matches this core's wire geometry. Per-block Bedrock exceptions
		// (some transparent / special full cubes) still need a conformance pass.
		return $block->isFullCube() && !$block->isTransparent();
	}

	public static function at(World $world, Vector3 $pos) : ?Block{
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		return $world->isInWorld($x, $y, $z) && $world->isChunkLoaded($x >> 4, $z >> 4)
			? $world->getBlockAt($x, $y, $z) : null;
	}

	public static function side(Block $block, int $side) : ?Block{
		$pos = $block->getPosition();
		return self::at($pos->getWorld(), $pos->getSide($side));
	}

	public static function weak(Block $source, int $towards, bool $includeWire = true) : int{
		if($source instanceof Redstone){
			return 15;
		}
		if($source instanceof Lever){
			return $source->isActivated() ? 15 : 0;
		}
		if($source instanceof Button || $source instanceof SimplePressurePlate){
			return $source->isPressed() ? 15 : 0;
		}
		if($source instanceof RedstoneTorch){
			return $source->isLit() && $towards !== Facing::opposite($source->getFacing()) ? 15 : 0;
		}
		if($source instanceof RedstoneRepeater){
			return $source->isPowered() && $towards === Facing::opposite($source->getFacing()) ? 15 : 0;
		}
		if($source instanceof RedstoneComparator){
			return $towards === Facing::opposite($source->getFacing()) ? $source->getOutputSignalStrength() : 0;
		}
		if($source instanceof RedstoneWire){
			if(!$includeWire || $towards === Facing::UP){
				return 0;
			}
			return $towards === Facing::DOWN || $source->getConnection($towards) !== RedstoneWireConnectionType::NONE
				? $source->getOutputSignalStrength() : 0;
		}
		return $source instanceof AnalogRedstoneSignalEmitter ? $source->getOutputSignalStrength() : 0;
	}

	/** Input into a solid block; includeWire=false asks for strong power only. */
	public static function intoConductor(Block $source, int $towards, bool $includeWire = true) : int{
		if($source instanceof Lever){
			return $towards === Facing::opposite($source->getFacing()->getFacing()) && $source->isActivated() ? 15 : 0;
		}
		if($source instanceof Button){
			return $towards === Facing::opposite($source->getFacing()) && $source->isPressed() ? 15 : 0;
		}
		if($source instanceof SimplePressurePlate || $source instanceof WeightedPressurePlate){
			return $towards === Facing::DOWN ? self::weak($source, $towards, $includeWire) : 0;
		}
		if($source instanceof RedstoneTorch){
			return $towards === Facing::UP && $source->isLit() ? 15 : 0;
		}
		if($source instanceof RedstoneWire){
			return self::weak($source, $towards, $includeWire);
		}
		if($source instanceof RedstoneRepeater || $source instanceof RedstoneComparator){
			return self::weak($source, $towards, $includeWire);
		}
		return 0;
	}

	public static function conductorInput(Block $block, bool $includeWire = true) : int{
		$power = 0;
		if(self::isConductor($block)){
			foreach(Facing::ALL as $face){
				$other = self::side($block, $face);
				if($other !== null){
					$power = max($power, self::intoConductor($other, Facing::opposite($face), $includeWire));
				}
			}
		}
		return $power;
	}

	public static function fromSide(Block $receiver, int $side, bool $includeWire = true) : int{
		$source = self::side($receiver, $side);
		if($source === null){
			return 0;
		}
		$power = self::weak($source, Facing::opposite($side), $includeWire);
		return max($power, self::conductorInput($source, $includeWire));
	}

	public static function input(Block $receiver, bool $includeWire = true) : int{
		$power = 0;
		foreach(Facing::ALL as $face){
			$power = max($power, self::fromSide($receiver, $face, $includeWire));
		}
		return $power;
	}

	public static function wireInput(RedstoneWire $wire) : int{
		// Excluding all wire-derived strong power prevents a wire powering its
		// own support block and then reading that power back forever.
		$power = self::input($wire, false);
		$above = self::side($wire, Facing::UP);
		foreach(Facing::HORIZONTAL as $side){
			$neighbour = self::side($wire, $side);
			if($neighbour === null){
				continue;
			}
			$candidates = [$neighbour];
			if($above !== null && !self::isConductor($above)){
				$candidates[] = self::side($neighbour, Facing::UP);
			}
			if(!self::isConductor($neighbour)){
				$candidates[] = self::side($neighbour, Facing::DOWN);
			}
			foreach($candidates as $other){
				if($other instanceof RedstoneWire){
					$power = max($power, $other->getOutputSignalStrength() - 1);
				}
			}
		}
		return $power;
	}

	public static function diodeInput(RedstoneRepeater|RedstoneComparator $diode) : int{
		$rear = self::side($diode, $diode->getFacing());
		return $rear instanceof RedstoneWire ? $rear->getOutputSignalStrength() : self::fromSide($diode, $diode->getFacing());
	}

	public static function diodeSideInput(RedstoneRepeater|RedstoneComparator $diode, bool $lockingOnly = false) : int{
		$power = 0;
		foreach([false, true] as $clockwise){
			$side = Facing::rotateY($diode->getFacing(), $clockwise);
			$other = self::side($diode, $side);
			if($other instanceof RedstoneRepeater || $other instanceof RedstoneComparator){
				$power = max($power, self::weak($other, Facing::opposite($side)));
			}elseif(!$lockingOnly && $other instanceof RedstoneWire){
				$power = max($power, $other->getOutputSignalStrength());
			}elseif(!$lockingOnly && $other instanceof Redstone){
				$power = 15;
			}
		}
		return $power;
	}

	public static function comparatorOutput(int $rear, int $side, bool $subtract) : int{
		return $subtract ? max(0, $rear - $side) : ($rear >= $side ? $rear : 0);
	}

	public static function inventorySignal(Inventory $inventory) : int{
		if($inventory->getSize() === 0){
			return 0;
		}
		$fullness = 0.0;
		foreach($inventory->getContents() as $item){
			if(!$item->isNull()){
				$fullness += min(1.0, $item->getCount() / max(1, min($inventory->getMaxStackSize(), $item->getMaxStackSize())));
			}
		}
		return $fullness > 0 ? min(15, 1 + (int) floor(14 * $fullness / $inventory->getSize() + 1.0e-10)) : 0;
	}
}
