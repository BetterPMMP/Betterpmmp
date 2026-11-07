<?php

/*
 * BetterPMMP - illagers in patrols, outposts and raids.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\ExtraVanillaItems;
use pocketmine\item\OminousBottle;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use function mt_rand;

/**
 * Captains wear the ominous banner and drop an ominous bottle (Bad Omen I-V)
 * when a player kills them. Raiders of a raid walk to the village centre
 * when they have nobody to fight.
 */
trait RaiderTrait{

	private bool $captain = false;
	private int $raidId = -1;
	private ?Vector3 $raidCenter = null;

	public function isCaptain() : bool{ return $this->captain; }

	public function setCaptain(bool $captain = true) : void{
		$this->captain = $captain;
		$this->getArmorInventory()->setHelmet($captain ? VanillaItems::OMINOUS_BANNER() : VanillaItems::AIR());
		$this->setPersistent();
	}

	public function getRaidId() : int{ return $this->raidId; }

	public function joinRaid(int $raidId, Vector3 $center) : void{
		$this->raidId = $raidId;
		$this->raidCenter = $center;
		$this->setPersistent();
	}

	protected function initRaider(CompoundTag $nbt) : void{
		if($nbt->getByte("BetterPMMPCaptain", 0) === 1){
			$this->setCaptain();
		}
	}

	protected function saveRaider(CompoundTag $nbt) : CompoundTag{
		return $nbt->setByte("BetterPMMPCaptain", $this->captain ? 1 : 0);
	}

	/** @param \pocketmine\item\Item[] $drops */
	protected function raiderDrops(array $drops) : array{
		$cause = $this->getLastDamageCause();
		if($this->captain && $cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player){
			$bottle = ExtraVanillaItems::get("minecraft:ominous_bottle");
			if($bottle instanceof OminousBottle){
				$drops[] = $bottle->setOmenLevel(mt_rand(0, 4));
			}
		}
		return $drops;
	}

	/** Walks towards the raided village when there is no target. Returns true when it moved. */
	protected function tickRaidMarch(int $tickDiff) : bool{
		if($this->raidCenter === null || $this->getPosition()->distanceSquared($this->raidCenter) < 12 * 12){
			return false;
		}
		$navigator = $this->getNavigator();
		$navigator->moveTo($this->raidCenter, $this->getWalkSpeed(false), 6.0);
		$navigator->tick($tickDiff);
		return true;
	}
}
