<?php

/*
 * BetterPMMP - riding (boats, minecarts, horses, pigs, striders, camels).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\riding;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function array_search;
use function array_values;
use function count;
use function max;
use function spl_object_id;

/**
 * Keeps track of who rides what and tells clients about it with entity links
 * and the rider's seat metadata, like vanilla:
 *
 * - The first rider is the driver (link type RIDER), others are passengers.
 * - New viewers of a vehicle get the links right after the vehicle spawns.
 * - Riders get off when they sneak or press "leave vehicle", when either
 *   entity dies or despawns, or when the rider is teleported.
 */
final class RidingManager{

	/** @var array<int, Entity> rider id => vehicle */
	private static array $vehicleOf = [];
	/** @var array<int, list<Entity>> vehicle id => riders in seat order */
	private static array $riders = [];

	public static function getVehicle(Entity $rider) : ?Entity{
		$vehicle = self::$vehicleOf[$rider->getId()] ?? null;
		if($vehicle !== null && ($vehicle->isClosed() || !$vehicle->isAlive())){
			self::dismount($rider);
			return null;
		}
		return $vehicle;
	}

	/** @return list<Entity> */
	public static function getRiders(Entity $vehicle) : array{
		return self::$riders[$vehicle->getId()] ?? [];
	}

	public static function getDriver(Entity $vehicle) : ?Entity{
		return self::getRiders($vehicle)[0] ?? null;
	}

	public static function isRiding(Entity $entity) : bool{
		return self::getVehicle($entity) !== null;
	}

	public static function mount(Entity $rider, Entity $vehicle) : bool{
		if(!$vehicle instanceof Rideable || $rider === $vehicle || $rider->isClosed() || $vehicle->isClosed()){
			return false;
		}
		if(isset(self::$vehicleOf[$rider->getId()])){
			self::dismount($rider);
		}
		$riders = self::getRiders($vehicle);
		if(count($riders) >= $vehicle->getMaxRiders()){
			return false;
		}
		$riders[] = $rider;
		self::$riders[$vehicle->getId()] = $riders;
		self::$vehicleOf[$rider->getId()] = $vehicle;
		$seat = count($riders) - 1;
		self::applySeat($rider, $vehicle, $seat);
		$link = new EntityLink($vehicle->getId(), $rider->getId(), $seat === 0 ? EntityLink::TYPE_RIDER : EntityLink::TYPE_PASSENGER, true, true, 0.0);
		self::broadcast($vehicle, $rider, SetActorLinkPacket::create($link));
		$vehicle->onRiderMount($rider, $seat);
		return true;
	}

	public static function dismount(Entity $rider) : void{
		$vehicle = self::$vehicleOf[$rider->getId()] ?? null;
		unset(self::$vehicleOf[$rider->getId()]);
		if($vehicle === null){
			return;
		}
		$riders = self::getRiders($vehicle);
		$index = array_search($rider, $riders, true);
		if($index !== false){
			unset($riders[$index]);
		}
		$riders = array_values($riders);
		if(count($riders) === 0){
			unset(self::$riders[$vehicle->getId()]);
		}else{
			self::$riders[$vehicle->getId()] = $riders;
		}
		$link = new EntityLink($vehicle->getId(), $rider->getId(), EntityLink::TYPE_REMOVE, true, true, 0.0);
		self::broadcast($vehicle, $rider, SetActorLinkPacket::create($link));
		if(!$rider->isClosed()){
			$properties = $rider->getNetworkProperties();
			$properties->setGenericFlag(EntityMetadataFlags::RIDING, false);
			$rider->sendData(null);
			// step off next to the vehicle
			if(!$vehicle->isClosed() && $rider->isAlive()){
				$pos = $vehicle->getPosition();
				$rider->teleport(new Location($pos->x, $pos->y + max(0.5, $vehicle->getSize()->getHeight()), $pos->z, $pos->getWorld(), $rider->getLocation()->getYaw(), $rider->getLocation()->getPitch()));
			}
		}
		if($vehicle instanceof Rideable && !$vehicle->isClosed()){
			$vehicle->onRiderDismount($rider);
		}
		// remaining riders move up a seat
		foreach($riders as $seat => $other){
			self::applySeat($other, $vehicle, $seat);
		}
	}

	/** Gets every rider off a vehicle (vehicle destroyed). */
	public static function ejectAll(Entity $vehicle) : void{
		foreach(self::getRiders($vehicle) as $rider){
			self::dismount($rider);
		}
	}

	private static function applySeat(Entity $rider, Entity $vehicle, int $seat) : void{
		if(!$vehicle instanceof Rideable){
			return;
		}
		$properties = $rider->getNetworkProperties();
		$properties->setGenericFlag(EntityMetadataFlags::RIDING, true);
		$properties->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, $vehicle->getSeatPosition($seat));
		$properties->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 0);
		$rider->sendData(null);
	}

	/** Sends a packet to everyone who sees the vehicle, plus the rider itself. */
	private static function broadcast(Entity $vehicle, Entity $rider, SetActorLinkPacket $packet) : void{
		$targets = $vehicle->getViewers();
		if($rider instanceof Player){
			$targets[] = $rider;
		}
		$sent = [];
		foreach($targets as $player){
			if(isset($sent[spl_object_id($player)]) || !$player->isConnected()){
				continue;
			}
			$sent[spl_object_id($player)] = true;
			$player->getNetworkSession()->sendDataPacket($packet);
		}
	}

	/** Called after a vehicle was spawned to a player: tell them who rides it. */
	public static function sendLinksTo(Entity $vehicle, Player $player) : void{
		foreach(self::getRiders($vehicle) as $seat => $rider){
			$player->getNetworkSession()->sendDataPacket(SetActorLinkPacket::create(
				new EntityLink($vehicle->getId(), $rider->getId(), $seat === 0 ? EntityLink::TYPE_RIDER : EntityLink::TYPE_PASSENGER, true, false, 0.0)
			));
		}
	}

	/** Keeps a rider's server-side position on its seat. */
	public static function seatPositionOf(Entity $rider) : ?Vector3{
		$vehicle = self::getVehicle($rider);
		if(!$vehicle instanceof Rideable){
			return null;
		}
		$index = array_search($rider, self::getRiders($vehicle), true);
		return $vehicle->getPosition()->addVector($vehicle->getSeatPosition($index === false ? 0 : $index));
	}
}
