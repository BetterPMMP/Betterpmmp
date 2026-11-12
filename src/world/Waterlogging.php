<?php

/*
 * BetterPMMP - waterlogged blocks.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\BaseSign;
use pocketmine\block\Block;
use pocketmine\block\Chest;
use pocketmine\block\EnderChest;
use pocketmine\block\Fence;
use pocketmine\block\FenceGate;
use pocketmine\block\GenericBedrockBlock;
use pocketmine\block\Hopper;
use pocketmine\block\Ladder;
use pocketmine\block\Lantern;
use pocketmine\block\Slab;
use pocketmine\block\Stair;
use pocketmine\block\Thin;
use pocketmine\block\Trapdoor;
use pocketmine\block\utils\SlabType;
use pocketmine\block\VanillaFenceBlock;
use pocketmine\block\VanillaFenceGateBlock;
use pocketmine\block\VanillaSlabBlock;
use pocketmine\block\VanillaStairsBlock;
use pocketmine\block\VanillaTrapdoorBlock;
use pocketmine\block\Wall;
use pocketmine\block\Water;
use function str_contains;
use function str_ends_with;

/**
 * Bedrock keeps the water of a waterlogged block (stairs, slabs, fences ...) in
 * a second block layer. BetterPMMP stores and sends that layer: a water bucket
 * waterlogs such a block, an empty bucket takes the water back, placing such a
 * block into still water keeps the water, and when the block is broken the
 * water stays. (Water does not flow out of waterlogged blocks yet.)
 */
final class Waterlogging{

	public static function canHold(Block $block) : bool{
		if($block instanceof Slab){
			return $block->getSlabType() !== SlabType::DOUBLE;
		}
		if($block instanceof Stair || $block instanceof Fence || $block instanceof Wall || $block instanceof Trapdoor || $block instanceof FenceGate
			|| $block instanceof Ladder || $block instanceof BaseSign || $block instanceof Chest || $block instanceof EnderChest
			|| $block instanceof Lantern || $block instanceof Thin || $block instanceof Hopper){
			return true;
		}
		if($block instanceof VanillaSlabBlock || $block instanceof VanillaStairsBlock || $block instanceof VanillaFenceBlock
			|| $block instanceof VanillaTrapdoorBlock || $block instanceof VanillaFenceGateBlock){
			return true;
		}
		if($block instanceof GenericBedrockBlock){
			$name = $block->getBedrockName();
			return str_ends_with($name, "_wall") || str_contains($name, "copper_grate") || str_ends_with($name, "_sign")
				|| str_contains($name, "copper_chest") || str_ends_with($name, "_shelf") || str_contains($name, "chain")
				|| $name === "minecraft:decorated_pot" || $name === "minecraft:conduit";
		}
		return false;
	}

	/** Whether a still water source occupies the block itself (what a waterloggable block can take over). */
	public static function isWaterSource(Block $block) : bool{
		return $block instanceof Water && $block->isSource() && !$block->isFalling();
	}
}
