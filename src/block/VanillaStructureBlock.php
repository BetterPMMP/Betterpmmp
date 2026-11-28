<?php

/*
 * BetterPMMP - structure block.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\utils\RedstonePower;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\player\Player;
use pocketmine\world\structure\McStructure;
use Symfony\Component\Filesystem\Path;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function max;
use function min;
use function mkdir;
use function preg_match;
use function str_contains;
use function str_replace;
use function strtolower;

/**
 * Vanilla structure block for operators in creative mode: the screen saves its settings; "Save" stores the
 * area (offset + size, at most 64 x 384 x 64) as structures/<name>.mcstructure, "Load" places it with
 * rotation, mirror, integrity and seed - the same files as /structure. A redstone signal does the same.
 */
class VanillaStructureBlock extends GenericBedrockBlock{

	private const MODE_SAVE = 1;
	private const MODE_LOAD = 2;

	private static function canEdit(Player $player) : bool{
		return $player->isCreative() && $player->getServer()->isOp($player->getName());
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !self::canEdit($player)){
			return false;
		}
		$player->getNetworkSession()->sendDataPacket(ContainerOpenPacket::blockInv(0x7f, WindowTypes::STRUCTURE_EDITOR, BlockPosition::fromVector3($this->position)));
		return true;
	}

	private function tile() : ?TileStructureBlock{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileStructureBlock ? $tile : null;
	}

	/** Settings from the structure block screen; $trigger = the Save/Load button was pressed. */
	public function applySettings(Player $player, StructureEditorData $data, bool $trigger) : void{
		$tile = $this->tile();
		if($tile === null){
			return;
		}
		$settings = $data->structureSettings;
		$nbt = $tile->getSettings()
			->setString("structureName", $data->structureName)
			->setString("dataField", $data->structureDataField)
			->setInt("data", $data->structureBlockType)
			->setInt("xStructureOffset", $settings->offset->getX())->setInt("yStructureOffset", $settings->offset->getY())->setInt("zStructureOffset", $settings->offset->getZ())
			->setInt("xStructureSize", $settings->dimensions->getX())->setInt("yStructureSize", $settings->dimensions->getY())->setInt("zStructureSize", $settings->dimensions->getZ())
			->setByte("rotation", $settings->rotation)->setByte("mirror", $settings->mirror)
			->setByte("ignoreEntities", $settings->ignoreEntities ? 1 : 0)->setByte("removeBlocks", $settings->ignoreBlocks ? 1 : 0)
			->setByte("includePlayers", $data->includePlayers ? 1 : 0)->setByte("showBoundingBox", $data->showBoundingBox ? 1 : 0)
			->setFloat("integrity", $settings->integrityValue)->setLong("seed", $settings->integritySeed);
		$tile->setSettings($nbt);
		$this->position->getWorld()->setBlock($this->position, $this); //send the settings to the clients
		if($trigger){
			$player->sendMessage($this->run($nbt));
		}
	}

	/** Redstone: a rising signal saves or loads, like vanilla. */
	public function onNearbyBlockChange() : void{
		$powered = RedstonePower::isPowered($this);
		$tile = $this->tile();
		if($tile === null){
			return;
		}
		$settings = $tile->getSettings();
		if($powered === ($settings->getByte("isPowered", 0) === 1)){
			return;
		}
		$tile->setSettings($settings->setByte("isPowered", $powered ? 1 : 0));
		if($powered){
			$this->run($settings);
		}
	}

	/** Saves or loads according to the settings; returns a message for the player. */
	private function run(CompoundTag $settings) : string{
		$name = strtolower($settings->getString("structureName", ""));
		if($name === "" || preg_match('/^[a-z0-9_\-.]+(:[a-z0-9_\-.\/]+)?$/', $name) !== 1 || str_contains($name, "..")){
			return "Invalid structure name";
		}
		$world = $this->position->getWorld();
		$file = Path::join($world->getServer()->getDataPath(), "structures", str_replace(":", "/", $name) . ".mcstructure");
		$origin = $this->position->add($settings->getInt("xStructureOffset", 0), $settings->getInt("yStructureOffset", 1), $settings->getInt("zStructureOffset", 0))->floor();
		switch($settings->getInt("data", self::MODE_SAVE)){
			case self::MODE_SAVE:
				$size = new Vector3(
					max(1, min(64, $settings->getInt("xStructureSize", 1))),
					max(1, min(384, $settings->getInt("yStructureSize", 1))),
					max(1, min(64, $settings->getInt("zStructureSize", 1)))
				);
				$max = $origin->addVector($size)->subtract(1, 1, 1);
				if(!$world->isInWorld((int) $origin->x, (int) $origin->y, (int) $origin->z) || !$world->isInWorld((int) $max->x, (int) $max->y, (int) $max->z)){
					return "Cannot save blocks outside of the world";
				}
				$structure = McStructure::capture($world, $origin, $max, $settings->getByte("ignoreEntities", 0) === 0, $settings->getByte("removeBlocks", 0) === 0);
				if(!is_dir(dirname($file)) && !@mkdir(dirname($file), 0777, true)){
					return "Could not create " . dirname($file);
				}
				if(@file_put_contents($file, $structure->write($origin)) === false){
					return "Could not write " . $file;
				}
				return "Saved structure " . $name;
			case self::MODE_LOAD:
				if(!is_file($file)){
					return "Structure " . $name . " not found";
				}
				try{
					$structure = McStructure::read((string) file_get_contents($file));
				}catch(\Throwable $e){
					return "Structure " . $name . " could not be read: " . $e->getMessage();
				}
				$mirror = match($settings->getByte("mirror", 0)){
					1 => "x",
					2 => "z",
					3 => "xz",
					default => "none",
				};
				$placed = $structure->place($world, $origin, $settings->getByte("rotation", 0) & 3, $mirror, $settings->getByte("ignoreEntities", 0) === 0, $settings->getByte("removeBlocks", 0) === 0, max(0.0, min(100.0, $settings->getFloat("integrity", 100.0))) / 100, $settings->getLong("seed", 0));
				return "Loaded structure " . $name . " (" . $placed . " blocks)";
			default:
				return "";
		}
	}
}
