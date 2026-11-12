<?php

/*
 * BetterPMMP - per-session packet fixes for Java Edition players (Java bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;

/**
 * Adjusts packets for Java Edition players before they go to the bridge.
 *
 * Plugins often spawn invisible "marker" actors with scale 0 next to a player,
 * e.g. a hidden wither that carries a boss bar. Bedrock ignores them, but Java
 * has no scale 0: there the marker is a full-size invisible mob, and Java
 * clients are pushed by every mob they overlap - the player keeps sliding.
 * Such markers (scale below 0.01, no name tag) are moved 64 blocks down for
 * Java players; the boss bar and everything else stay as they are.
 */
final class JavaPacketFixer{

	public const MARKER_DROP = 64.0;

	/** @var array<int, true> runtime ids of marker actors */
	private array $markers = [];

	public function fix(ClientboundPacket $packet) : ClientboundPacket{
		if($packet instanceof AddActorPacket){
			if(!self::isMarker($packet->metadata)){
				return $packet;
			}
			$this->markers[$packet->actorRuntimeId] = true;
			$fixed = clone $packet;
			$fixed->position = $packet->position->subtract(0, self::MARKER_DROP, 0);
			return $fixed;
		}
		if($packet instanceof MoveActorAbsolutePacket && isset($this->markers[$packet->actorRuntimeId])){
			$fixed = clone $packet;
			$fixed->position = $packet->position->subtract(0, self::MARKER_DROP, 0);
			return $fixed;
		}
		if($packet instanceof RemoveActorPacket){
			unset($this->markers[$packet->actorUniqueId]);
		}
		return $packet;
	}

	public function isMarkerActor(int $runtimeId) : bool{
		return isset($this->markers[$runtimeId]);
	}

	/**
	 * @param \pocketmine\network\mcpe\protocol\types\entity\MetadataProperty[] $metadata
	 */
	private static function isMarker(array $metadata) : bool{
		$scale = $metadata[EntityMetadataProperties::SCALE] ?? null;
		if(!$scale instanceof FloatMetadataProperty || $scale->getValue() >= 0.01){
			return false;
		}
		$nameTag = $metadata[EntityMetadataProperties::NAMETAG] ?? null;
		return !$nameTag instanceof StringMetadataProperty || $nameTag->getValue() === "";
	}
}
