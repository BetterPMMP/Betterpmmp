<?php

/*
 * BetterPMMP - shelves, command blocks, signs of new wood types, straw beds.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\CommandBlock as TileCommandBlock;
use pocketmine\block\tile\Shelf as TileShelf;
use pocketmine\block\tile\Sign as TileSign;
use pocketmine\block\utils\RedstonePower;
use pocketmine\block\utils\SignText;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\item\Item;
use pocketmine\lang\Translatable;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;
use function array_map;
use function floor;
use function max;
use function min;
use function str_replace;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Shelves: three item slots. Using a shelf swaps the item in your hand with
 * the slot you look at; breaking it drops the items.
 */
class VanillaShelf extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }
	public function isTransparent() : bool{ return true; }

	private function facing() : int{
		$tag = $this->getStateData()->getStates()["minecraft:cardinal_direction"] ?? null;
		return match($tag === null ? "south" : (string) $tag->getValue()){
			"north" => Facing::NORTH,
			"east" => Facing::EAST,
			"west" => Facing::WEST,
			default => Facing::SOUTH,
		};
	}

	protected function recalculateCollisionBoxes() : array{
		// the back half of the block
		return [AxisAlignedBB::one()->trim($this->facing(), 0.5)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = $player !== null ? Facing::opposite($player->getHorizontalFacing()) : Facing::SOUTH;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => self::cardinalName($facing)]));
		return true;
	}

	/** Slot 0-2 from left to right, seen from the front. */
	public function slotAt(Vector3 $clickVector) : int{
		$u = match($this->facing()){
			Facing::SOUTH => $clickVector->x,
			Facing::NORTH => 1 - $clickVector->x,
			Facing::EAST => 1 - $clickVector->z,
			default => $clickVector->z,
		};
		return max(0, min(2, (int) floor($u * 3)));
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->position->getWorld()->getTile($this->position);
		if(!$tile instanceof TileShelf || $player === null){
			return false;
		}
		$slot = $this->slotAt($clickVector);
		$stored = $tile->getItem($slot);
		if($stored->isNull() && $item->isNull()){
			return false;
		}
		$tile->setItem($slot, $item);
		$player->getInventory()->setItemInHand($stored);
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}
}

/** Collects command output for a command block. */
final class CommandBlockSender extends ConsoleCommandSender{
	public string $output = "";

	public function sendMessage(Translatable|string $message) : void{
		$text = $message instanceof Translatable ? $this->getLanguage()->translate($message) : $message;
		$this->output .= ($this->output === "" ? "" : "\n") . TextFormat::clean($text);
	}

	public function getName() : string{
		return "@";
	}
}

/**
 * Command blocks (impulse, repeating, chain). Operators in creative mode
 * edit them with the Bedrock command block screen. Impulse blocks run once on
 * a redstone pulse, repeating ones every tick delay while powered (or always
 * active), chain blocks after the command block pointing into them ran.
 * Conditional blocks only run if the block behind them succeeded.
 */
class VanillaCommandBlock extends GenericBedrockBlock{

	private const MAX_CHAIN = 64;

	public function getMode() : int{
		return match($this->getBedrockName()){
			"minecraft:repeating_command_block" => 1,
			"minecraft:chain_command_block" => 2,
			default => 0,
		};
	}

	public static function nameForMode(int $mode) : string{
		return match($mode){
			1 => "minecraft:repeating_command_block",
			2 => "minecraft:chain_command_block",
			default => "minecraft:command_block",
		};
	}

	private function state(string $name) : int{
		$tag = $this->getStateData()->getStates()[$name] ?? null;
		return $tag === null ? 0 : (int) $tag->getValue();
	}

	public function getFacing() : int{
		return $this->state("facing_direction");
	}

	public function isConditional() : bool{
		return $this->state("conditional_bit") === 1;
	}

	public function getBlastResistance() : float{
		return 3600000.0;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = $player !== null ? Facing::opposite(VanillaPiston::lookingDirection($player)) : $face;
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["facing_direction" => $facing]));
		return true;
	}

	private function tile() : ?TileCommandBlock{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileCommandBlock ? $tile : null;
	}

	public static function canEdit(Player $player) : bool{
		return $player->isCreative() && $player->getServer()->isOp($player->getName());
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !self::canEdit($player)){
			return true;
		}
		$player->getNetworkSession()->sendDataPacket(ContainerOpenPacket::blockInv(0x7f, WindowTypes::COMMAND_BLOCK, BlockPosition::fromVector3($this->position)));
		return true;
	}

	public function onPostPlace() : void{
		$this->onNearbyBlockChange();
	}

	public function onNearbyBlockChange() : void{
		$tile = $this->tile();
		if($tile === null){
			return;
		}
		$powered = RedstonePower::isPowered($this);
		if($powered === $tile->powered){
			return;
		}
		$tile->powered = $powered;
		$tile->changed();
		if($powered || $tile->auto){
			if($this->getMode() !== 2){
				$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, max(1, $tile->tickDelay));
			}
		}
	}

	/** Always-active settings changed or the block was just placed. */
	public function scheduleIfActive() : void{
		$tile = $this->tile();
		if($tile !== null && ($tile->auto || $tile->powered) && $this->getMode() !== 2){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, max(1, $tile->tickDelay));
		}
	}

	public function onScheduledUpdate() : void{
		$tile = $this->tile();
		if($tile === null || !($tile->auto || $tile->powered)){
			return;
		}
		$this->execute(0);
		if($this->getMode() === 1){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, max(1, $tile->tickDelay));
		}
	}

	private function conditionMet() : bool{
		if(!$this->isConditional()){
			return true;
		}
		[$dx, $dy, $dz] = Facing::OFFSET[Facing::opposite($this->getFacing())];
		$behind = $this->position->getWorld()->getBlockAt($this->position->getFloorX() + $dx, $this->position->getFloorY() + $dy, $this->position->getFloorZ() + $dz);
		$tile = $behind instanceof self ? $behind->tile() : null;
		return $tile !== null && $tile->successCount > 0;
	}

	/** Runs the command and then the chain block in front. */
	public function execute(int $depth) : void{
		$tile = $this->tile();
		if($tile === null || $depth > self::MAX_CHAIN){
			return;
		}
		$command = $tile->command;
		if(str_starts_with($command, "/")){
			$command = (string) substr($command, 1);
		}
		$tile->successCount = 0;
		if($command !== "" && $this->conditionMet()){
			$server = Server::getInstance();
			$sender = new CommandBlockSender($server, $server->getLanguage());
			try{
				$ok = $server->dispatchCommand($sender, $command);
			}catch(\Throwable $e){
				$ok = false;
				$sender->output = $e->getMessage();
			}
			$tile->successCount = $ok ? 1 : 0;
			$tile->lastOutput = strlen($sender->output) > 512 ? substr($sender->output, 0, 512) : $sender->output;
		}
		$tile->changed();
		[$dx, $dy, $dz] = Facing::OFFSET[$this->getFacing()];
		$next = $this->position->getWorld()->getBlockAt($this->position->getFloorX() + $dx, $this->position->getFloorY() + $dy, $this->position->getFloorZ() + $dz);
		if($next instanceof self && $next->getMode() === 2){
			$nextTile = $next->tile();
			if($nextTile !== null && ($nextTile->auto || $nextTile->powered)){
				$next->execute($depth + 1);
			}
		}
	}

	/** Applies the settings from the command block screen. */
	public function applySettings(int $mode, bool $conditional, bool $redstoneMode, string $command, bool $trackOutput, int $tickDelay, bool $executeOnFirstTick, string $name) : void{
		$world = $this->position->getWorld();
		$block = ExtraVanillaBlocks::get(self::nameForMode($mode));
		$tile = $this->tile();
		if(!$block instanceof self || $tile === null){
			return;
		}
		$settings = [$command, $trackOutput, max(0, $tickDelay), $executeOnFirstTick, $name, !$redstoneMode, $tile->powered];
		$new = $block->withProperties(["facing_direction" => $this->getFacing(), "conditional_bit" => $conditional ? 1 : 0]);
		if($new->getStateId() !== $this->getStateId()){
			$world->setBlock($this->position, $new);
		}
		$block = $world->getBlock($this->position);
		$tile = $block instanceof self ? $block->tile() : null;
		if($tile === null){
			return;
		}
		[$tile->command, $tile->trackOutput, $tile->tickDelay, $tile->executeOnFirstTick, $tile->customName, $tile->auto, $tile->powered] = $settings;
		$tile->changed();
		$world->setBlock($this->position, $block);
		if($tile->auto){
			$block->scheduleIfActive();
		}
	}
}

/**
 * Signs of wood types the core has no sign for (poplar and later ones). They
 * use the core sign block entity, so text is saved and shown like on any sign.
 */
class VanillaGenericSign extends GenericBedrockBlock{

	private static ?int $placer = null;

	public function isSolid() : bool{ return false; }

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$name = $this->getBedrockName();
		self::$placer = $player?->getId();
		if(str_ends_with($name, "_standing_sign") || str_ends_with($name, "_wall_sign")){
			$standing = str_replace("_wall_sign", "_standing_sign", $name);
			if($face !== Facing::UP && $face !== Facing::DOWN){
				$wall = ExtraVanillaBlocks::get(str_replace("_standing_sign", "_wall_sign", $standing));
				if($wall !== null){
					$tx->addBlock($blockReplace->getPosition(), $wall->withProperties(["facing_direction" => $face]));
					return true;
				}
			}
			if($face !== Facing::UP){
				return false;
			}
			$block = ExtraVanillaBlocks::get($standing) ?? $this;
			$rotation = $player !== null ? ((int) floor((($player->getLocation()->getYaw() + 180) * 16 / 360) + 0.5)) & 0x0f : 0;
			$tx->addBlock($blockReplace->getPosition(), $block->withProperties(["ground_sign_direction" => $rotation]));
			return true;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onPostPlace() : void{
		$tile = $this->position->getWorld()->getTile($this->position);
		$player = self::$placer === null ? null : $this->position->getWorld()->getEntity(self::$placer);
		self::$placer = null;
		if($tile instanceof TileSign && $player instanceof Player && $player->isConnected()){
			$tile->setEditorEntityRuntimeId($player->getId());
			$player->openSignEditor($this->position);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->position->getWorld()->getTile($this->position);
		if($player === null || !$tile instanceof TileSign || $tile->isWaxed()){
			return false;
		}
		$tile->setEditorEntityRuntimeId($player->getId());
		$player->openSignEditor($this->position);
		return true;
	}

	/** Text sent by the sign editor. */
	public function updateText(Player $author, bool $front, SignText $text) : bool{
		$tile = $this->position->getWorld()->getTile($this->position);
		if(!$tile instanceof TileSign || $tile->isWaxed() || $tile->getEditorEntityRuntimeId() !== $author->getId()){
			return false;
		}
		$size = 0;
		foreach($text->getLines() as $line){
			$size += strlen($line);
		}
		if($size > 1000){
			return false;
		}
		$old = $front ? $tile->getText() : $tile->getBackText();
		$new = new SignText(array_map(fn(string $line) : string => TextFormat::clean($line, false), $text->getLines()), $old->getBaseColor(), $old->isGlowing());
		$front ? $tile->setText($new) : $tile->setBackText($new);
		$tile->setEditorEntityRuntimeId(null);
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}
}

/** Straw beds: a two-block bed you can sleep in at night. */
class VanillaStrawBed extends GenericBedrockBlock{
	use BedrockShapeStates;

	public function isSolid() : bool{ return false; }

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 16)];
	}

	private function isHead() : bool{
		$tag = $this->getStateData()->getStates()["head_piece_bit"] ?? null;
		return $tag !== null && (int) $tag->getValue() === 1;
	}

	private function facing() : int{
		$tag = $this->getStateData()->getStates()["minecraft:cardinal_direction"] ?? null;
		return match($tag === null ? "south" : (string) $tag->getValue()){
			"north" => Facing::NORTH,
			"east" => Facing::EAST,
			"west" => Facing::WEST,
			default => Facing::SOUTH,
		};
	}

	private function otherHalf() : Block{
		return $this->getSide($this->isHead() ? Facing::opposite($this->facing()) : $this->facing());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$facing = $player !== null ? $player->getHorizontalFacing() : Facing::SOUTH;
		$head = $blockReplace->getSide($facing);
		if(!$head->canBeReplaced() || !$blockReplace->getSide(Facing::DOWN)->isSolid() || !$head->getSide(Facing::DOWN)->isSolid()){
			return false;
		}
		$direction = self::cardinalName($facing);
		$tx->addBlock($blockReplace->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => $direction, "head_piece_bit" => 0]));
		$tx->addBlock($head->getPosition(), $this->withProperties(["minecraft:cardinal_direction" => $direction, "head_piece_bit" => 1]));
		return true;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$other = $this->otherHalf();
		if($other instanceof self){
			$this->position->getWorld()->setBlock($other->getPosition(), VanillaBlocks::AIR());
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->isHead() ? [] : parent::getDropsForCompatibleTool($item);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null){
			return false;
		}
		$world = $this->position->getWorld();
		$time = $world->getTimeOfDay();
		$night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
		$head = $this->isHead() ? $this : $this->otherHalf();
		if($player->getPosition()->distanceSquared($head->getPosition()->add(0.5, 0.5, 0.5)) > 16){
			$player->sendTip("Du bist zu weit weg");
			return true;
		}
		if(!$night && !$world->getWeather()->isThundering()){
			$player->sendTip("Du kannst nur nachts oder bei Gewitter schlafen");
			$player->setSpawn($head->getPosition());
			return true;
		}
		$player->sleepOn($head->getPosition());
		return true;
	}
}
