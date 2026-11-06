<?php

/*
 * BetterPMMP - vanilla anvil calculation.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Armor;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\Rarity;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaArmorMaterials;
use pocketmine\item\VanillaItems;
use function ceil;
use function in_array;
use function intdiv;
use function max;
use function min;
use function strlen;

/**
 * Vanilla anvil rules: repairing with material (25 % per unit), combining two
 * items (sum of durability + 12 %), applying enchanted books, renaming,
 * enchantment merging (same level +1, capped), incompatible enchantments,
 * prior work penalty (RepairCost) and the "too expensive" limit of 40 levels.
 */
final class AnvilHelper{

	public const TOO_EXPENSIVE = 40;
	private const TAG_REPAIR_COST = "RepairCost";

	/**
	 * @return array{Item, int, int}|null [result, level cost, material units consumed] or null if nothing to do
	 */
	public static function calculate(Item $input, Item $material, ?string $rename, bool $creative) : ?array{
		if($input->isNull()){
			return null;
		}
		$result = clone $input;
		$result->setCount(1);
		$cost = 0;
		$penalty = self::repairCost($input) + ($material->isNull() ? 0 : self::repairCost($material));
		$materialUsed = 0;
		$renameCost = 0;

		if(!$material->isNull()){
			$isBook = $material->getTypeId() === ItemTypeIds::ENCHANTED_BOOK;
			if($result instanceof Durable && self::isRepairMaterial($result, $material)){
				// unit repair: each unit restores a quarter of the durability
				$quarter = (int) ceil($result->getMaxDurability() / 4);
				$damage = $result->getDamage();
				if($damage <= 0){
					return null;
				}
				while($damage > 0 && $materialUsed < $material->getCount()){
					$damage = max(0, $damage - $quarter);
					++$materialUsed;
					++$cost;
				}
				$result->setDamage($damage);
			}else{
				if(!$isBook && !($material->getTypeId() === $input->getTypeId() && $result instanceof Durable)){
					return null;
				}
				// combine durability of two equal items
				if($result instanceof Durable && $material instanceof Durable && !$isBook){
					$left = $result->getMaxDurability() - $result->getDamage();
					$right = $material->getMaxDurability() - $material->getDamage();
					$combined = $left + $right + intdiv($result->getMaxDurability() * 12, 100);
					$newDamage = max(0, $result->getMaxDurability() - $combined);
					if($newDamage < $result->getDamage()){
						$result->setDamage($newDamage);
						$cost += 2;
					}
				}
				// merge enchantments
				$registry = AvailableEnchantmentRegistry::getInstance();
				$anyApplied = false;
				$anyIncompatible = false;
				foreach($material->getEnchantments() as $instance){
					$type = $instance->getType();
					$applicable = $input->getTypeId() === ItemTypeIds::ENCHANTED_BOOK || $input->getTypeId() === ItemTypeIds::BOOK || $creative;
					if(!$applicable){
						foreach($registry->getAllEnchantmentsForItem($input) as $possible){
							if($possible === $type){
								$applicable = true;
								break;
							}
						}
					}
					foreach($result->getEnchantments() as $existing){
						if($existing->getType() !== $type && !$existing->getType()->isCompatibleWith($type)){
							$applicable = false;
						}
					}
					if(!$applicable){
						$anyIncompatible = true;
						++$cost;
						continue;
					}
					$current = $result->getEnchantmentLevel($type);
					$level = $instance->getLevel();
					$newLevel = $current === $level ? $level + 1 : max($current, $level);
					$newLevel = min($newLevel, $type->getMaxLevel());
					$result->addEnchantment(new EnchantmentInstance($type, $newLevel));
					$anyApplied = true;
					$multiplier = match($type->getRarity()){
						Rarity::COMMON => 1,
						Rarity::UNCOMMON => 2,
						Rarity::RARE => 4,
						default => 8,
					};
					if($isBook){
						$multiplier = max(1, intdiv($multiplier, 2));
					}
					$cost += $multiplier * $newLevel;
				}
				if($anyIncompatible && !$anyApplied && $cost > 0 && !$result instanceof Durable){
					return null;
				}
				$materialUsed = 1;
			}
		}

		if($rename !== null){
			if($rename === ""){
				if($result->hasCustomName()){
					$result->clearCustomName();
					$renameCost = 1;
				}
			}elseif(strlen($rename) <= 50 && $rename !== $input->getCustomName()){
				$result->setCustomName($rename);
				$renameCost = 1;
			}
		}
		$cost += $renameCost;
		if($cost <= 0){
			return null;
		}
		$total = $cost + $penalty;
		// vanilla: renaming alone is never too expensive (capped at 39)
		if($renameCost > 0 && $cost === $renameCost && $total >= self::TOO_EXPENSIVE){
			$total = self::TOO_EXPENSIVE - 1;
		}
		if($total >= self::TOO_EXPENSIVE && !$creative){
			return null;
		}
		$newPenalty = max(self::repairCost($input), $material->isNull() ? 0 : self::repairCost($material)) * 2 + 1;
		if($renameCost === 0 || $cost > $renameCost){
			$result->getNamedTag()->setInt(self::TAG_REPAIR_COST, $newPenalty);
		}
		return [$result, $total, $materialUsed];
	}

	public static function repairCost(Item $item) : int{
		return $item->getNamedTag()->getInt(self::TAG_REPAIR_COST, 0);
	}

	public static function isRepairMaterial(Durable $item, Item $material) : bool{
		$id = $material->getTypeId();
		if($item instanceof TieredTool){
			return match($item->getTier()){
				ToolTier::WOOD => self::isPlanks($material),
				ToolTier::STONE => in_array($id, [VanillaBlocks::COBBLESTONE()->asItem()->getTypeId(), VanillaBlocks::COBBLED_DEEPSLATE()->asItem()->getTypeId(), VanillaBlocks::BLACKSTONE()->asItem()->getTypeId()], true),
				ToolTier::IRON => $id === ItemTypeIds::IRON_INGOT,
				ToolTier::GOLD => $id === ItemTypeIds::GOLD_INGOT,
				ToolTier::DIAMOND => $id === ItemTypeIds::DIAMOND,
				ToolTier::NETHERITE => $id === ItemTypeIds::NETHERITE_INGOT,
				ToolTier::COPPER => $id === ItemTypeIds::COPPER_INGOT,
			};
		}
		if($item instanceof \pocketmine\item\Elytra){
			return $id === ItemTypeIds::PHANTOM_MEMBRANE;
		}
		if($item instanceof Armor){
			$m = $item->getMaterial();
			return match(true){
				$m === VanillaArmorMaterials::LEATHER() => $id === ItemTypeIds::LEATHER,
				$m === VanillaArmorMaterials::CHAINMAIL(), $m === VanillaArmorMaterials::IRON() => $id === ItemTypeIds::IRON_INGOT,
				$m === VanillaArmorMaterials::GOLD() => $id === ItemTypeIds::GOLD_INGOT,
				$m === VanillaArmorMaterials::DIAMOND() => $id === ItemTypeIds::DIAMOND,
				$m === VanillaArmorMaterials::NETHERITE() => $id === ItemTypeIds::NETHERITE_INGOT,
				$m === VanillaArmorMaterials::TURTLE() => $id === ItemTypeIds::SCUTE,
				$m === VanillaArmorMaterials::COPPER() => $id === ItemTypeIds::COPPER_INGOT,
				default => false,
			};
		}
		if($item instanceof \pocketmine\item\SimpleDurableItem){
			return match($item->getName()){
				"Shield" => self::isPlanks($material),
				"Mace" => \pocketmine\item\ExtraVanillaItems::get("minecraft:breeze_rod")?->getTypeId() === $id,
				default => false,
			};
		}
		return false;
	}

	private static function isPlanks(Item $item) : bool{
		return $item->getBlock() instanceof \pocketmine\block\Planks;
	}
}
