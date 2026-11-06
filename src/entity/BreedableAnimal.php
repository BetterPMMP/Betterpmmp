<?php

declare(strict_types=1);

namespace pocketmine\entity;

interface BreedableAnimal extends Ageable{
	public function getLoveTicks() : int;
	public function setLoveTicks(int $ticks) : void;
	public function getBreedCooldownTicks() : int;
	public function setBreedCooldownTicks(int $ticks) : void;
}
