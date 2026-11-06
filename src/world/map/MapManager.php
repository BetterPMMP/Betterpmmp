<?php

/*
 * BetterPMMP - vanilla maps (empty map -> filled map, rendering, markers).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\world\map;

use pocketmine\block\Liquid;
use pocketmine\block\Water;
use pocketmine\color\Color;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\MapDecoration;
use pocketmine\network\mcpe\protocol\types\MapImage;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;
use function base64_decode;
use function base64_encode;
use function chr;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function intdiv;
use function is_array;
use function is_int;
use function is_dir;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function mkdir;
use function mt_rand;
use function ord;
use function str_contains;
use function str_repeat;
use function strlen;

/**
 * Vanilla maps: using an empty map creates a filled map centred on the
 * vanilla map grid (128 blocks per map at scale 0). The map is drawn from the
 * world with vanilla map colours and north-south height shading, keeps
 * drawing while a player holds it and shows the holder as a marker. Maps are
 * stored in the world folder (betterpmmp_maps) and survive restarts.
 */
final class MapManager{

	public const TAG_UUID = "map_uuid";
	public const TAG_SCALE = "map_scale";

	/** @var array<int, array{world: string, x: int, z: int, scale: int, pixels: string}> */
	private static array $maps = [];
	/** @var array<int, int> state id => rgb */
	private static array $colorCache = [];
	private static int $ticker = 0;

	public static function createFilledMap(Player $player, int $scale = 0) : ?Item{
		$item = ExtraVanillaItems::get("minecraft:filled_map");
		if($item === null){
			return null;
		}
		$size = 128 << $scale;
		$pos = $player->getPosition();
		// vanilla map grid alignment
		$cx = (int) (floor(($pos->x + 64) / $size) * $size + intdiv($size, 2) - 64);
		$cz = (int) (floor(($pos->z + 64) / $size) * $size + intdiv($size, 2) - 64);
		$id = mt_rand(1, 0x7fffffff) * 4096 + mt_rand(0, 4095);
		self::$maps[$id] = ["world" => $player->getWorld()->getFolderName(), "x" => $cx, "z" => $cz, "scale" => $scale, "pixels" => str_repeat("\0", 128 * 128 * 3)];
		self::render($id, $player->getWorld(), 0, 0, 127, 127);
		self::save($id, $player->getWorld());
		$tag = $item->getNamedTag();
		$tag->setLong(self::TAG_UUID, $id);
		$tag->setInt(self::TAG_SCALE, $scale);
		$tag->setByte("map_display_players", 1);
		$item->setNamedTag($tag);
		return $item;
	}

	private static function mapFile(World $world, int $id) : string{
		return $world->getProvider()->getPath() . "/betterpmmp_maps/" . $id . ".json";
	}

	private static function save(int $id, World $world) : void{
		$data = self::$maps[$id] ?? null;
		if($data === null){
			return;
		}
		$dir = $world->getProvider()->getPath() . "/betterpmmp_maps";
		if(!is_dir($dir)){
			@mkdir($dir, 0777, true);
		}
		@file_put_contents(self::mapFile($world, $id), json_encode(["world" => $data["world"], "x" => $data["x"], "z" => $data["z"], "scale" => $data["scale"], "pixels" => base64_encode($data["pixels"])]));
	}

	/** Loads a map from any loaded world's folder. */
	private static function load(int $id) : bool{
		if(isset(self::$maps[$id])){
			return true;
		}
		foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
			$file = self::mapFile($world, $id);
			if(!file_exists($file)){
				continue;
			}
			$data = json_decode((string) file_get_contents($file), true);
			if(!is_array($data)){
				return false;
			}
			$pixels = base64_decode((string) ($data["pixels"] ?? ""), true);
			self::$maps[$id] = [
				"world" => (string) ($data["world"] ?? $world->getFolderName()),
				"x" => (int) ($data["x"] ?? 0),
				"z" => (int) ($data["z"] ?? 0),
				"scale" => (int) ($data["scale"] ?? 0),
				"pixels" => $pixels !== false && strlen($pixels) === 128 * 128 * 3 ? $pixels : str_repeat("\0", 128 * 128 * 3),
			];
			return true;
		}
		return false;
	}

	/** Base colour of a block on maps (vanilla MapColor palette). */
	private static function blockColor(int $stateId) : int{
		if(isset(self::$colorCache[$stateId])){
			return self::$colorCache[$stateId];
		}
		try{
			$name = GlobalBlockStateHandlers::getSerializer()->serialize($stateId)->getName();
		}catch(\Throwable){
			$name = "";
		}
		$has = fn(string ...$words) : bool => self::containsAny($name, $words);
		$color = match(true){
			$name === "minecraft:air" || $name === "" => -1,
			$name === "minecraft:grass_block" => 0x7fb238,
			$has("water") => 0x4040ff,
			$has("lava", "fire", "magma") => 0xff0000,
			$has("ice") => 0xa0a0ff,
			$has("snow") => 0xffffff,
			$has("leaves", "vine", "sapling", "fern", "short_grass", "tall_grass", "flower", "kelp", "seagrass", "cactus", "sugar_cane", "lily", "bush", "azalea", "moss", "dandelion", "poppy", "tulip", "orchid", "allium", "bluet", "daisy", "cornflower", "lilac", "peony", "rose") => 0x007c00,
			$has("red_sand", "terracotta", "acacia") => 0xd87f33,
			$has("sand", "end_stone", "birch", "glowstone", "bone") => 0xf7e9a3,
			$has("white") => 0xffffff,
			$has("orange") => 0xd87f33,
			$has("magenta") => 0xb24cd8,
			$has("light_blue") => 0x6699d8,
			$has("yellow") => 0xe5e533,
			$has("lime") => 0x7fcc19,
			$has("pink") => 0xf27fa5,
			$has("light_gray") => 0x999999,
			$has("gray") => 0x4c4c4c,
			$has("cyan") => 0x4c7f99,
			$has("purple") => 0x7f3fb2,
			$has("blue") => 0x334cb2,
			$has("brown") => 0x664c33,
			$has("green") => 0x667f33,
			$has("red") => 0x993333,
			$has("black") => 0x191919,
			$has("dirt", "farmland", "path", "podzol", "mud", "rooted", "mycelium", "granite", "jungle") => 0x976d4d,
			$has("netherrack", "nether", "crimson", "warped") => 0x700200,
			$has("deepslate", "tuff", "basalt", "blackstone") => 0x646464,
			$has("quartz", "diorite", "calcite") => 0xfffcf5,
			$has("iron", "anvil", "chain") => 0xa7a7a7,
			$has("clay") => 0xa4a8b8,
			$has("planks", "log", "wood", "oak", "spruce", "mangrove", "cherry", "chest", "crafting", "bookshelf", "fence", "door", "stairs") => 0x8f7748,
			$has("gold") => 0xfaee4d,
			$has("diamond") => 0x5cdbd5,
			$has("emerald") => 0x00d93a,
			$has("lapis") => 0x4a80ff,
			$has("obsidian") => 0x191919,
			default => 0x707070,
		};
		return self::$colorCache[$stateId] = $color;
	}

	/** @param string[] $words */
	private static function containsAny(string $name, array $words) : bool{
		foreach($words as $word){
			if(str_contains($name, $word)){
				return true;
			}
		}
		return false;
	}

	/** Draws pixels [x1..x2] x [y1..y2] of a map from loaded chunks. */
	private static function render(int $id, World $world, int $x1, int $y1, int $x2, int $y2) : bool{
		$map = self::$maps[$id];
		$step = 1 << $map["scale"];
		$originX = $map["x"] - 64 * $step;
		$originZ = $map["z"] - 64 * $step;
		$pixels = $map["pixels"];
		$changed = false;
		for($py = max(0, $y1); $py <= min(127, $y2); ++$py){
			$wz = $originZ + $py * $step;
			$northHeight = null;
			for($px = max(0, $x1); $px <= min(127, $x2); ++$px){
				$wx = $originX + $px * $step;
				if(!$world->isChunkLoaded($wx >> 4, $wz >> 4)){
					continue;
				}
				$top = $world->getHighestBlockAt($wx, $wz);
				if($top === null){
					continue;
				}
				$block = $world->getBlockAt($wx, $top, $wz);
				$rgb = self::blockColor($block->getStateId());
				if($rgb < 0){
					continue;
				}
				// vanilla shading: compare with the block one step to the north
				$north = $world->isChunkLoaded($wx >> 4, ($wz - $step) >> 4) ? ($world->getHighestBlockAt($wx, $wz - $step) ?? $top) : $top;
				if($block instanceof Water || ($block instanceof Liquid && $rgb === 0x4040ff)){
					$depth = 0;
					for($d = $top; $d > $world->getMinY() && $depth < 10 && $world->getBlockAt($wx, $d, $wz) instanceof Water; --$d){
						++$depth;
					}
					$shade = $depth > 7 ? 180 : ($depth > 3 ? 220 : 255);
				}else{
					$shade = $top > $north ? 255 : ($top === $north ? 220 : 180);
				}
				$r = (($rgb >> 16) & 0xff) * $shade >> 8;
				$g = (($rgb >> 8) & 0xff) * $shade >> 8;
				$b = ($rgb & 0xff) * $shade >> 8;
				$offset = ($py * 128 + $px) * 3;
				$new = chr($r) . chr($g) . chr($b);
				if($pixels[$offset] . $pixels[$offset + 1] . $pixels[$offset + 2] !== $new){
					$pixels[$offset] = $new[0];
					$pixels[$offset + 1] = $new[1];
					$pixels[$offset + 2] = $new[2];
					$changed = true;
				}
			}
		}
		self::$maps[$id]["pixels"] = $pixels;
		return $changed;
	}

	private static function image(int $id, int $x1, int $y1, int $x2, int $y2) : MapImage{
		$pixels = self::$maps[$id]["pixels"];
		$rows = [];
		for($py = $y1; $py <= $y2; ++$py){
			$row = [];
			for($px = $x1; $px <= $x2; ++$px){
				$offset = ($py * 128 + $px) * 3;
				$r = ord($pixels[$offset]);
				$g = ord($pixels[$offset + 1]);
				$b = ord($pixels[$offset + 2]);
				// 1.26.60 sends map pixels as big-endian B,G,R,A (see gophertunnel BEARGB); the protocol
				// library writes Color::toRGBA() big-endian (R,G,B,A), so red and blue are swapped here
				$row[] = ($r | $g | $b) === 0 ? new Color(0, 0, 0, 0) : new Color($b, $g, $r);
			}
			$rows[] = $row;
		}
		return new MapImage($rows);
	}

	/** @return MapDecoration[] */
	private static function decorations(int $id, World $world) : array{
		$map = self::$maps[$id];
		$step = 1 << $map["scale"];
		$result = [];
		foreach($world->getPlayers() as $player){
			$pos = $player->getPosition();
			$dx = ($pos->x - $map["x"]) / $step;
			$dz = ($pos->z - $map["z"]) / $step;
			if($dx < -64 || $dx >= 64 || $dz < -64 || $dz >= 64){
				continue;
			}
			$rotation = ((int) floor($player->getLocation()->getYaw() * 16 / 360 + 0.5)) & 0x0f;
			$result[] = new MapDecoration(0, $rotation, ((int) ($dx * 2)) & 0xff, ((int) ($dz * 2)) & 0xff, $player->getName(), new Color(255, 255, 255));
		}
		return $result;
	}

	private static function packet(int $id, World $world, ?array $region = null) : ClientboundMapItemDataPacket{
		$map = self::$maps[$id];
		[$x1, $y1, $x2, $y2] = $region ?? [0, 0, 127, 127];
		$packet = new ClientboundMapItemDataPacket();
		$packet->mapId = $id;
		$packet->dimensionId = $world->getDimensionId();
		$packet->origin = new BlockPosition($map["x"], 0, $map["z"]);
		$packet->scale = $map["scale"];
		$packet->decorations = self::decorations($id, $world);
		$packet->xOffset = $x1;
		$packet->yOffset = $y1;
		$packet->colors = self::image($id, $x1, $y1, $x2, $y2);
		return $packet;
	}

	/** The client asks for a map's contents (MapInfoRequest). */
	public static function handleRequest(Player $player, int $id) : void{
		if(!self::load($id)){
			return;
		}
		$world = Server::getInstance()->getWorldManager()->getWorldByName(self::$maps[$id]["world"]) ?? $player->getWorld();
		$player->getNetworkSession()->sendDataPacket(self::packet($id, $world));
	}

	/** Keeps drawing maps held by players and moves their markers (every 20 ticks). */
	public static function tick() : void{
		if(++self::$ticker < 2){
			return;
		}
		self::$ticker = 0;
		foreach(Server::getInstance()->getOnlinePlayers() as $player){
			if(!$player->isConnected() || !$player->isAlive()){
				continue;
			}
			foreach([$player->getInventory()->getItemInHand(), $player->getOffHandInventory()->getItem(0)] as $item){
				$id = $item->getNamedTag()->getTag(self::TAG_UUID)?->getValue();
				if(!is_int($id) || !self::load($id)){
					continue;
				}
				$map = self::$maps[$id];
				$world = $player->getWorld();
				if($world->getFolderName() !== $map["world"]){
					continue;
				}
				$step = 1 << $map["scale"];
				$px = (int) floor(($player->getPosition()->x - $map["x"]) / $step) + 64;
				$py = (int) floor(($player->getPosition()->z - $map["z"]) / $step) + 64;
				$region = [max(0, $px - 16), max(0, $py - 16), min(127, $px + 16), min(127, $py + 16)];
				if($px >= -16 && $px < 144 && $py >= -16 && $py < 144 && $region[0] <= $region[2] && $region[1] <= $region[3]){
					if(self::render($id, $world, ...$region)){
						self::save($id, $world);
					}
					$player->getNetworkSession()->sendDataPacket(self::packet($id, $world, $region));
				}else{
					// outside the map: only update the markers
					$player->getNetworkSession()->sendDataPacket(self::packet($id, $world, [0, 0, 0, 0]));
				}
			}
		}
	}
}
