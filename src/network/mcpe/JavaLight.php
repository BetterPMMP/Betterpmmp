<?php

/*
 * BetterPMMP - real light for Java Edition players.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function array_fill;
use function base64_encode;
use function count;
use function implode;
use function method_exists;
use function pack;
use function spl_object_id;
use function str_repeat;

/**
 * Bedrock servers send no light, the Bedrock client computes it. The Java bridge (ViaBedrock) therefore gives
 * Java clients full sky light and no block light everywhere - fine without shaders, but shaders build their
 * whole lighting from it (grey, flat, torches do not glow). The server computes real light anyway; this sends it
 * per chunk to the BetterPMMP-Bridge plugin, which turns it into Java light updates.
 *
 * Message "betterpmmp:light": "chunkX;chunkZ;lowest sub-chunk;base64(per sub-chunk: 2048 bytes sky + 2048 bytes
 * block light, PocketMine layout)". Chunks are sent shortly after the chunk itself and once more a bit later
 * (in case the bridge sent its own chunk data after ours); block changes re-send the chunk.
 */
final class JavaLight{

	private const FIRST_DELAY = 4;
	private const SECOND_DELAY = 40;
	private const PER_TICK = 8;

	/** @var array<int, array<string, array{World, int, int, int}>> session id => chunk => [world, x, z, due tick] */
	private static array $queue = [];
	/** @var array<int, NetworkSession> */
	private static array $sessions = [];

	public static function chunkSent(NetworkSession $session, World $world, int $chunkX, int $chunkZ) : void{
		if(!\pocketmine\BetterPMMPConfig::flag("java-bridge", "real-light", true)){
			return;
		}
		$tick = $world->getServer()->getTick();
		self::add($session, $world, $chunkX, $chunkZ, $tick + self::FIRST_DELAY);
		self::add($session, $world, $chunkX, $chunkZ, $tick + self::SECOND_DELAY, "late");
	}

	private static function add(NetworkSession $session, World $world, int $chunkX, int $chunkZ, int $due, string $tag = "") : void{
		$id = spl_object_id($session);
		self::$sessions[$id] = $session;
		$key = $world->getId() . ":" . $chunkX . ":" . $chunkZ . $tag;
		if(!isset(self::$queue[$id][$key]) || self::$queue[$id][$key][3] > $due){
			self::$queue[$id][$key] = [$world, $chunkX, $chunkZ, $due];
		}
	}

	/** A block changed: re-send the light of the chunk (and its neighbours near the border) to Java players. */
	public static function blockChanged(World $world, int $x, int $z) : void{
		if(self::$sessions === []){
			return;
		}
		$cx = $x >> Chunk::COORD_BIT_SIZE;
		$cz = $z >> Chunk::COORD_BIT_SIZE;
		$lx = $x & Chunk::COORD_MASK;
		$lz = $z & Chunk::COORD_MASK;
		$due = $world->getServer()->getTick() + 2;
		// light reaches up to 15 blocks: the chunk and the neighbours on the near sides
		$sx = $lx < 8 ? -1 : 1;
		$sz = $lz < 8 ? -1 : 1;
		foreach([[0, 0], [$sx, 0], [0, $sz], [$sx, $sz]] as [$dx, $dz]){
			foreach($world->getChunkPlayers($cx + $dx, $cz + $dz) as $player){
				$session = $player->getNetworkSession();
				if($session->isJavaBridgeSession()){
					self::add($session, $world, $cx + $dx, $cz + $dz, $due);
				}
			}
		}
	}

	public static function forget(NetworkSession $session) : void{
		unset(self::$queue[spl_object_id($session)], self::$sessions[spl_object_id($session)]);
	}

	/** Called every tick for a Java bridge session. */
	public static function tick(NetworkSession $session) : void{
		$id = spl_object_id($session);
		if(!isset(self::$queue[$id]) || self::$queue[$id] === []){
			return;
		}
		$player = $session->getPlayer();
		if($player === null || !$session->isConnected()){
			self::forget($session);
			return;
		}
		$sent = 0;
		foreach(self::$queue[$id] as $key => [$world, $chunkX, $chunkZ, $due]){
			if($world->getServer()->getTick() < $due){
				continue;
			}
			if($player->getWorld() !== $world || $player->getUsedChunkStatus($chunkX, $chunkZ) === null){
				unset(self::$queue[$id][$key]);
				continue;
			}
			$chunk = $world->getChunk($chunkX, $chunkZ);
			if($chunk === null){
				unset(self::$queue[$id][$key]);
				continue;
			}
			if($chunk->isLightPopulated() !== true){
				continue; // light is still being computed: try again next tick
			}
			unset(self::$queue[$id][$key]);
			$session->sendDataPacket(self::message($chunk, $chunkX, $chunkZ));
			if(++$sent >= self::PER_TICK){
				break;
			}
		}
	}

	public static function message(Chunk $chunk, int $chunkX, int $chunkZ) : ScriptMessagePacket{
		$data = [];
		$lowest = null;
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$lowest ??= $y;
			$data[] = self::raw($subChunk->getBlockSkyLightArray()) . self::raw($subChunk->getBlockLightArray());
		}
		return ScriptMessagePacket::create("betterpmmp:light", $chunkX . ";" . $chunkZ . ";" . ($lowest ?? 0) . ";" . count($data) . ";" . base64_encode(implode("", $data)));
	}

	/** 2048 bytes, nibble per block at (x << 8 | z << 4 | y), low nibble first. */
	private static function raw(\pocketmine\world\format\LightArray $array) : string{
		if(method_exists($array, "getData")){
			return $array->getData();
		}
		if($array->isUniform(0)){
			return str_repeat("\x00", 2048);
		}
		if($array->isUniform(15)){
			return str_repeat("\xff", 2048);
		}
		$bytes = array_fill(0, 2048, 0);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				for($y = 0; $y < 16; ++$y){
					$index = ($x << 8) | ($z << 4) | $y;
					$bytes[$index >> 1] |= ($array->get($x, $y, $z) & 0x0f) << (($index & 1) << 2);
				}
			}
		}
		return pack("C*", ...$bytes);
	}
}
