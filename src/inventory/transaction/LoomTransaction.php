<?php

/*
 * BetterPMMP - weaving a banner pattern at a loom.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\block\inventory\LoomInventory;
use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\BannerPatternType;
use pocketmine\item\Banner;
use pocketmine\item\Dye;
use pocketmine\item\GenericBedrockItem;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function constant;
use function count;

/**
 * Vanilla loom: one banner and one dye are used up, the banner gets the pattern in the dye's colour.
 * Patterns like flower, creeper, skull, mojang, globe, piglin, flow, guster, bricks and curly border
 * need their banner pattern item in the third slot (it is not used up). At most 6 patterns.
 */
final class LoomTransaction extends InventoryTransaction{

	public const MAX_LAYERS = 6;

	/** Patterns that need a banner pattern item (Bedrock item name without "minecraft:"). */
	private const PATTERN_ITEMS = [
		"FLOWER" => "flower_banner_pattern",
		"CREEPER" => "creeper_banner_pattern",
		"SKULL" => "skull_banner_pattern",
		"MOJANG" => "mojang_banner_pattern",
		"GLOBE" => "globe_banner_pattern",
		"PIGLIN" => "piglin_banner_pattern",
		"FLOW" => "flow_banner_pattern",
		"GUSTER" => "guster_banner_pattern",
		"BRICKS" => "field_masoned_banner_pattern",
		"CURLY_BORDER" => "bordure_indented_banner_pattern",
	];

	/**
	 * Java's list of patterns without a pattern item, in the order the Java loom shows them.
	 * @var list<string>
	 */
	public const JAVA_FREE_PATTERNS = [
		"SQUARE_BOTTOM_LEFT", "SQUARE_BOTTOM_RIGHT", "SQUARE_TOP_LEFT", "SQUARE_TOP_RIGHT", "STRIPE_BOTTOM", "STRIPE_TOP",
		"STRIPE_LEFT", "STRIPE_RIGHT", "STRIPE_CENTER", "STRIPE_MIDDLE", "STRIPE_DOWNRIGHT", "STRIPE_DOWNLEFT", "SMALL_STRIPES",
		"CROSS", "STRAIGHT_CROSS", "TRIANGLE_BOTTOM", "TRIANGLE_TOP", "TRIANGLES_BOTTOM", "TRIANGLES_TOP", "DIAGONAL_LEFT",
		"DIAGONAL_UP_RIGHT", "DIAGONAL_UP_LEFT", "DIAGONAL_RIGHT", "CIRCLE", "RHOMBUS", "HALF_VERTICAL", "HALF_HORIZONTAL",
		"HALF_VERTICAL_RIGHT", "HALF_HORIZONTAL_BOTTOM", "BORDER", "GRADIENT", "GRADIENT_UP",
	];

	public function __construct(Player $source, private Item $result){
		parent::__construct($source);
	}

	public static function patternItemFor(BannerPatternType $type) : ?string{
		return self::PATTERN_ITEMS[$type->name] ?? null;
	}

	/** The pattern a banner pattern item gives, if any. */
	public static function patternOfItem(Item $item) : ?BannerPatternType{
		if(!$item instanceof GenericBedrockItem){
			return null;
		}
		foreach(self::PATTERN_ITEMS as $case => $name){
			if($item->getBedrockName() === "minecraft:" . $name){
				return constant(BannerPatternType::class . "::" . $case);
			}
		}
		return null;
	}

	/** The woven banner, or null if this pattern cannot be woven with these items. */
	public static function calculate(LoomInventory $loom, BannerPatternType $type) : ?Item{
		$banner = $loom->getItem(LoomInventory::SLOT_BANNER);
		$dye = $loom->getItem(LoomInventory::SLOT_DYE);
		$patternItem = $loom->getItem(LoomInventory::SLOT_PATTERN);
		if(!$banner instanceof Banner || !$dye instanceof Dye || count($banner->getPatterns()) >= self::MAX_LAYERS){
			return null;
		}
		$needs = self::patternItemFor($type);
		if($needs !== null && self::patternOfItem($patternItem) !== $type){
			return null;
		}
		$result = (clone $banner)->setCount(1);
		$patterns = $banner->getPatterns();
		$patterns[] = new BannerPatternLayer($type, $dye->getColor());
		$result->setPatterns($patterns);
		return $result;
	}

	public function validate() : void{
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		$banners = 0;
		$dyes = 0;
		foreach($inputs as $item){
			if($item instanceof Banner){
				$banners += $item->getCount();
			}elseif($item instanceof Dye){
				$dyes += $item->getCount();
			}else{
				throw new TransactionValidationException("A loom only uses up a banner and a dye");
			}
		}
		$received = 0;
		foreach($outputs as $item){
			if(!$item->equals($this->result, true, true)){
				throw new TransactionValidationException("The loom result does not match");
			}
			$received += $item->getCount();
		}
		if($banners !== 1 || $dyes !== 1 || $received !== 1){
			throw new TransactionValidationException("A loom weaves one banner from one banner and one dye");
		}
	}
}
