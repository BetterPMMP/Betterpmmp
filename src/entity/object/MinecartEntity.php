<?php

/*
 * BetterPMMP - rideable minecart with rail physics.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\ActivatorRail;
use pocketmine\block\BaseRail;
use pocketmine\block\PoweredRail;
use pocketmine\block\Rail;
use pocketmine\block\StraightOnlyRail;
use pocketmine\block\utils\RailConnectionInfo;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\riding\Rideable;
use pocketmine\entity\riding\RideableTrait;
use pocketmine\entity\riding\RidingManager;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;
use function atan2;
use function count;
use function cos;
use function deg2rad;
use function floor;
use function max;
use function min;
use function rad2deg;
use function sin;
use function sqrt;

/**
 * Vanilla minecart: follows straight, curved and sloped rails, keeps its
 * momentum (friction 0.997 with a rider, 0.96 without), climbs and rolls down
 * slopes, is boosted by powered rails and stopped by unpowered ones, ejects its
 * rider on powered activator rails and drops as an item when broken.
 */
class MinecartEntity extends Entity implements Rideable{
	use RideableTrait;

	private const MAX_SPEED = 0.4;
	private const SLOPE_ACCEL = 0.0078125;

	private float $hitDamage = 0.0;

	public static function getNetworkTypeId() : string{ return EntityIds::MINECART; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.7, 0.98); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.04; }

	public function getMaxRiders() : int{ return 1; }
	public function getSeatPosition(int $seat) : Vector3{ return new Vector3(0, 1.02, 0); }
	public function isControlledByDriver() : bool{ return false; } // minecarts are moved by the server
	public function canBeCollidedWith() : bool{ return true; }

	public function getInteractButtonText(Player $player) : string{
		return count(RidingManager::getRiders($this)) < $this->getMaxRiders() && RidingManager::getVehicle($player) !== $this ? "action.interact.ride.minecart" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() || RidingManager::getVehicle($player) === $this){
			return false;
		}
		return RidingManager::mount($player, $this);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent){
			return;
		}
		$damager = $source->getDamager();
		if($damager instanceof Player && $damager->isCreative()){
			$this->flagForDespawn();
			return;
		}
		$this->hitDamage += $source->getFinalDamage() * 10;
		if($this->hitDamage > 40){
			$this->getWorld()->dropItem($this->location, VanillaItems::MINECART());
			$this->flagForDespawn();
		}
	}

	/** The rail the cart is on (at its feet or just below on slopes). */
	private function railAt() : ?BaseRail{
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		foreach([(int) floor($this->location->y + 0.01), (int) floor($this->location->y - 0.5)] as $y){
			$block = $world->getBlockAt($x, $y, $z);
			if($block instanceof BaseRail){
				return $block;
			}
		}
		return null;
	}

	/** @return int[] the two exits of a rail (Facing, possibly with the ascend flag) */
	private static function exits(BaseRail $rail) : array{
		$shape = $rail instanceof Rail || $rail instanceof StraightOnlyRail ? $rail->getShape() : 0;
		return RailConnectionInfo::CONNECTIONS[$shape] ?? RailConnectionInfo::CURVE_CONNECTIONS[$shape] ?? [Facing::NORTH, Facing::SOUTH];
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->hitDamage = max(0.0, $this->hitDamage - $tickDiff);
		$this->tickRails();
		return true;
	}

	/** Rail physics for one tick (public for tests). */
	public function tickRails() : void{
		$rail = $this->railAt();
		if($rail === null){
			$this->keepMovement = false;
			$this->gravityEnabled = true;
			$motion = $this->getMotion();
			if($this->isOnGround()){
				$this->setMotion(new Vector3($motion->x * 0.5, $motion->y, $motion->z * 0.5));
			}
			return;
		}
		// on rails the cart is held at rail height: no gravity (it would pull the cart into the rail)
		$this->keepMovement = true;
		$this->gravityEnabled = false;
		$this->followRail($rail);
	}

	private function followRail(BaseRail $rail) : void{
		$pos = $rail->getPosition();
		[$a, $b] = self::exits($rail);
		$aFace = $a & ~RailConnectionInfo::FLAG_ASCEND;
		$bFace = $b & ~RailConnectionInfo::FLAG_ASCEND;
		[$ax, , $az] = Facing::OFFSET[$aFace];
		[$bx, , $bz] = Facing::OFFSET[$bFace];
		$motion = $this->getMotion();
		$vx = $motion->x;
		$vz = $motion->z;
		$speed = sqrt($vx * $vx + $vz * $vz);

		// rider pushes a slow cart in the looking direction
		$driver = RidingManager::getDriver($this);
		if($driver instanceof Player && $speed < 0.1 && $this->driverForward > 0){
			$yaw = deg2rad($this->driverYaw);
			$vx += -sin($yaw) * 0.02;
			$vz += cos($yaw) * 0.02;
			$speed = sqrt($vx * $vx + $vz * $vz);
		}

		// choose the exit we are heading to
		$towardsA = $vx * $ax + $vz * $az;
		$towardsB = $vx * $bx + $vz * $bz;
		$exit = $towardsA >= $towardsB ? $a : $b;
		$exitFace = $exit & ~RailConnectionInfo::FLAG_ASCEND;
		[$ex, , $ez] = Facing::OFFSET[$exitFace];
		$other = $exit === $a ? $b : $a;

		// slopes: gravity along the rail
		$ascendingUp = ($exit & RailConnectionInfo::FLAG_ASCEND) !== 0;
		$ascendingDown = ($other & RailConnectionInfo::FLAG_ASCEND) !== 0;
		if($ascendingUp){
			$speed -= self::SLOPE_ACCEL * 4;
		}elseif($ascendingDown){
			$speed += self::SLOPE_ACCEL * 4;
		}

		// powered rails
		if($rail instanceof PoweredRail){
			if($rail->isPowered()){
				if($speed < 0.01){
					// start moving away from a solid block next to the rail
					foreach([$aFace, $bFace] as $face){
						if($this->getWorld()->getBlock($pos->getSide($face))->isSolid()){
							$exitFace = Facing::opposite($face);
							[$ex, , $ez] = Facing::OFFSET[$exitFace];
						}
					}
				}
				$speed += 0.06;
			}else{
				$speed *= 0.5;
				if($speed < 0.03){
					$speed = 0.0;
				}
			}
		}
		if($rail instanceof ActivatorRail && $rail->isPowered()){
			foreach(RidingManager::getRiders($this) as $rider){
				RidingManager::dismount($rider);
			}
		}

		$speed *= RidingManager::getDriver($this) !== null ? 0.997 : 0.96;
		$speed = max(0.0, min(self::MAX_SPEED, $speed));

		// direction: curves turn towards the exit, straights stay on axis
		$dirX = $ex;
		$dirZ = $ez;
		$isCurve = $aFace !== Facing::opposite($bFace);
		$centerX = $pos->x + 0.5;
		$centerZ = $pos->z + 0.5;
		if($isCurve){
			// past the centre of a curve: head out of the exit; before it: head to the centre
			$toCenterX = $centerX - $this->location->x;
			$toCenterZ = $centerZ - $this->location->z;
			$entering = $toCenterX * $ex + $toCenterZ * $ez > 0.05;
			if($entering){
				$len = max(0.001, sqrt($toCenterX * $toCenterX + $toCenterZ * $toCenterZ));
				$dirX = $toCenterX / $len;
				$dirZ = $toCenterZ / $len;
			}
		}
		$newVX = $dirX * $speed;
		$newVZ = $dirZ * $speed;

		// keep the cart on the rail's axis
		$targetY = $pos->y + 0.0625;
		if($ascendingUp || $ascendingDown){
			$progress = $ascendingUp ? self::progress($this->location, $pos, $exitFace) : 1 - self::progress($this->location, $pos, Facing::opposite($exitFace));
			$targetY = $pos->y + $progress + 0.0625;
		}
		$snapX = $ex === 0 && !$isCurve ? ($centerX - $this->location->x) * 0.5 : 0.0;
		$snapZ = $ez === 0 && !$isCurve ? ($centerZ - $this->location->z) * 0.5 : 0.0;
		$this->setMotion(new Vector3($newVX + $snapX, ($targetY - $this->location->y) * 0.5 + ($ascendingUp ? $speed : 0.0), $newVZ + $snapZ));
		if($speed > 0.01){
			$this->setRotation(rad2deg(atan2(-$dirX, $dirZ)) + 90, 0.0);
		}
	}

	/** How far (0..1) the cart has moved across the rail block towards a face. */
	private static function progress(Vector3 $at, Vector3 $block, int $face) : float{
		[$ox, , $oz] = Facing::OFFSET[$face];
		$fx = $at->x - $block->x;
		$fz = $at->z - $block->z;
		$p = $ox !== 0 ? ($ox > 0 ? $fx : 1 - $fx) : ($oz > 0 ? $fz : 1 - $fz);
		return max(0.0, min(1.0, $p));
	}

	public static function placeOnRail(Player $player, World $world, Vector3 $railPos) : bool{
		$block = $world->getBlock($railPos);
		if(!$block instanceof BaseRail){
			return false;
		}
		$cart = new MinecartEntity(\pocketmine\entity\Location::fromObject($railPos->add(0.5, 0.0625, 0.5), $world, $player->getLocation()->getYaw(), 0.0));
		$cart->spawnToAll();
		return true;
	}
}
