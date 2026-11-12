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
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use function array_key_first;
use function array_reverse;
use function explode;
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
	/** @var array<int, array{Vector3, float}> runtime id => [feet position, height] */
	private array $npcBases = [];
	/** @var array<int, list<string>> runtime id => name tag lines shown by carrier actors */
	private array $npcNames = [];
	/** @var ClientboundPacket[] packets to send after the current one */
	private array $extra = [];

	/** @return ClientboundPacket[] */
	public function takeExtraPackets() : array{
		$extra = $this->extra;
		$this->extra = [];
		return $extra;
	}

	private const NAME_LINE_HEIGHT = 0.27;
	/** Java renders the name of an (invisible) armor stand this far above its feet */
	private const ARMOR_STAND_NAME_HEIGHT = 2.2;

	private static function carrierId(int $npcId, int $line) : int{
		return 0x3F000000 + $npcId * 16 + $line;
	}

	/**
	 * Custom model entities show no name tag on Java, so the name is put on invisible armor stands above the
	 * NPC (one per line, like Java holograms).
	 */
	private function showNpcName(int $npcId, string $name) : void{
		$lines = $name === "" ? [] : array_reverse(explode("\n", $name));
		$old = $this->npcNames[$npcId] ?? [];
		if($lines === $old){
			return;
		}
		foreach($old as $i => $_){
			$this->extra[] = RemoveActorPacket::create(self::carrierId($npcId, $i));
		}
		$this->npcNames[$npcId] = $lines;
		if(!isset($this->npcBases[$npcId])){
			return;
		}
		[$feet, $height] = $this->npcBases[$npcId];
		foreach($lines as $i => $line){
			if($i >= 16){
				break;
			}
			$id = self::carrierId($npcId, $i);
			$this->extra[] = AddActorPacket::create($id, $id, "minecraft:armor_stand", self::carrierPosition($feet, $height, $i), null, 0, 0, 0, 0, [], [
				EntityMetadataProperties::FLAGS => new LongMetadataProperty((1 << EntityMetadataFlags::INVISIBLE) | (1 << EntityMetadataFlags::CAN_SHOW_NAMETAG) | (1 << EntityMetadataFlags::ALWAYS_SHOW_NAMETAG) | (1 << EntityMetadataFlags::NO_AI)),
				EntityMetadataProperties::NAMETAG => new StringMetadataProperty($line),
				EntityMetadataProperties::ALWAYS_SHOW_NAMETAG => new ByteMetadataProperty(1),
			], new PropertySyncData([], []), [], null);
		}
	}

	private function moveNpcName(int $npcId, Vector3 $feet) : void{
		if(!isset($this->npcBases[$npcId])){
			return;
		}
		[$old, $height] = $this->npcBases[$npcId];
		$this->npcBases[$npcId][0] = $feet;
		if($old->distanceSquared($feet) < 0.0001){
			return; // only turned
		}
		foreach($this->npcNames[$npcId] ?? [] as $i => $_){
			$this->extra[] = MoveActorAbsolutePacket::create(self::carrierId($npcId, $i), self::carrierPosition($feet, $height, $i), 0, 0, 0, 0);
		}
	}

	private static function carrierPosition(Vector3 $feet, float $height, int $line) : Vector3{
		return $feet->add(0, $height + 0.3 + $line * self::NAME_LINE_HEIGHT - self::ARMOR_STAND_NAME_HEIGHT, 0);
	}

	/** @param MetadataProperty[] $metadata */
	private static function metaFloat(array $metadata, int $key, float $default) : float{
		$value = $metadata[$key] ?? null;
		return $value instanceof FloatMetadataProperty ? $value->getValue() : $default;
	}

	/** @param MetadataProperty[] $metadata */
	private static function metaString(array $metadata, int $key) : string{
		$value = $metadata[$key] ?? null;
		return $value instanceof StringMetadataProperty ? $value->getValue() : "";
	}

	/** @return ClientboundPacket|null the packet to send, null to drop it */
	public function fix(ClientboundPacket $packet) : ?ClientboundPacket{
		if($packet instanceof AvailableActorIdentifiersPacket){
			return self::withNpcIdentifiers($packet);
		}
		if($packet instanceof AddPlayerPacket && ($identifier = JavaNpcModels::identifierFor($packet->actorRuntimeId)) !== null){
			// a 3D NPC: Java gets the custom entity from the BetterPMMP NPC model pack instead of a player
			$this->npcs[$packet->actorRuntimeId] = $packet->uuid->toString();
			$this->npcBases[$packet->actorRuntimeId] = [$packet->position, self::metaFloat($packet->metadata, EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 1.8)];
			$this->showNpcName($packet->actorRuntimeId, self::metaString($packet->metadata, EntityMetadataProperties::NAMETAG));
			return AddActorPacket::create(
				$packet->actorRuntimeId, $packet->actorRuntimeId, $identifier, $packet->position, $packet->motion,
				$packet->pitch, $packet->yaw, $packet->headYaw, $packet->yaw, [], $packet->metadata, $packet->syncedProperties, [], null
			);
		}
		if($packet instanceof MoveActorAbsolutePacket && isset($this->npcs[$packet->actorRuntimeId])){
			$fixed = clone $packet;
			$fixed->position = $packet->position->subtract(0, self::HUMAN_EYE_OFFSET, 0); // players are moved by eye height, custom entities by their feet
			$this->moveNpcName($packet->actorRuntimeId, $fixed->position);
			return $fixed;
		}
		if($packet instanceof SetActorDataPacket && isset($this->npcs[$packet->actorRuntimeId])){
			$name = $packet->metadata[EntityMetadataProperties::NAMETAG] ?? null;
			if($name instanceof StringMetadataProperty){
				$this->showNpcName($packet->actorRuntimeId, $name->getValue());
			}
			$height = $packet->metadata[EntityMetadataProperties::BOUNDING_BOX_HEIGHT] ?? null;
			if($height instanceof FloatMetadataProperty && isset($this->npcBases[$packet->actorRuntimeId])){
				$this->npcBases[$packet->actorRuntimeId][1] = $height->getValue();
			}
			return $packet;
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
			if(isset($this->npcs[$packet->actorUniqueId])){
				$this->showNpcName($packet->actorUniqueId, "");
			}
			unset($this->markers[$packet->actorUniqueId], $this->npcs[$packet->actorUniqueId], $this->npcBases[$packet->actorUniqueId], $this->npcNames[$packet->actorUniqueId]);
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
