<?php

/*
 * BetterPMMP - per-session packet fixes for Java Edition players (Java bridge).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\JavaNpcModels;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use function array_key_first;
use function count;
use function in_array;

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
	private const HUMAN_EYE_OFFSET = 1.621;

	/** @var array<int, true> runtime ids of marker actors */
	private array $markers = [];
	/** @var array<string, string> "x:y:z" => custom name sent for a (fake) container block */
	private array $containerNames = [];

	/** @var array<int, string> runtime id => uuid of NPCs sent as custom model entities */
	private array $npcs = [];

	/** @return ClientboundPacket|null the packet to send, null to drop it */
	public function fix(ClientboundPacket $packet) : ?ClientboundPacket{
		if($packet instanceof AvailableActorIdentifiersPacket){
			return self::withNpcIdentifiers($packet);
		}
		if($packet instanceof AddPlayerPacket && ($identifier = JavaNpcModels::identifierFor($packet->actorRuntimeId)) !== null){
			// a 3D NPC: Java gets the custom entity from the BetterPMMP NPC model pack instead of a player
			$this->npcs[$packet->actorRuntimeId] = $packet->uuid->toString();
			return AddActorPacket::create(
				$packet->actorRuntimeId, $packet->actorRuntimeId, $identifier, $packet->position, $packet->motion,
				$packet->pitch, $packet->yaw, $packet->headYaw, $packet->yaw, [], $packet->metadata, $packet->syncedProperties, [], null
			);
		}
		if($packet instanceof MoveActorAbsolutePacket && isset($this->npcs[$packet->actorRuntimeId])){
			$fixed = clone $packet;
			$fixed->position = $packet->position->subtract(0, self::HUMAN_EYE_OFFSET, 0); // players are moved by eye height, custom entities by their feet
			return $fixed;
		}
		if($packet instanceof MovePlayerPacket && isset($this->npcs[$packet->actorRuntimeId])){
			return MoveActorAbsolutePacket::create($packet->actorRuntimeId, $packet->position->subtract(0, self::HUMAN_EYE_OFFSET, 0), $packet->pitch, $packet->yaw, $packet->headYaw, 0);
		}
		if($packet instanceof PlayerSkinPacket && in_array($packet->uuid->toString(), $this->npcs, true)){
			return null;
		}
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
		if($packet instanceof BlockActorDataPacket){
			$root = $packet->nbt->getRoot();
			$key = self::key($packet->blockPosition);
			if(($name = $root->getTag("CustomName")) instanceof StringTag){
				$this->containerNames[$key] = $name->getValue();
				if(count($this->containerNames) > 256){
					unset($this->containerNames[array_key_first($this->containerNames)]);
				}
			}else{
				unset($this->containerNames[$key]);
			}
			return $packet;
		}
		if($packet instanceof RemoveActorPacket){
			unset($this->markers[$packet->actorUniqueId], $this->npcs[$packet->actorUniqueId]);
		}
		return $packet;
	}

	private static function withNpcIdentifiers(AvailableActorIdentifiersPacket $packet) : AvailableActorIdentifiersPacket{
		$identifiers = JavaNpcModels::getIdentifiers();
		$root = $packet->identifiers->getRoot();
		if($identifiers === [] || !($list = $root->getTag("idlist")) instanceof ListTag){
			return $packet;
		}
		$root = clone $root;
		$list = clone $list;
		$rid = 10000;
		foreach($identifiers as $identifier){
			$list->push(CompoundTag::create()
				->setString("bid", "")
				->setByte("hasspawnegg", 0)
				->setString("id", $identifier)
				->setInt("rid", $rid++)
				->setByte("summonable", 0));
		}
		$root->setTag("idlist", $list);
		return AvailableActorIdentifiersPacket::create(new CacheableNbt($root));
	}

	/**
	 * Name of the container block last sent at this position (menu plugins put a fake chest with a custom name
	 * there). The bridge needs it when opening the window; ViaBedrock does not always find it itself.
	 */
	public function getContainerName(BlockPosition $position) : ?string{
		return $this->containerNames[self::key($position)] ?? null;
	}

	private static function key(BlockPosition $position) : string{
		return $position->getX() . ":" . $position->getY() . ":" . $position->getZ();
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
