<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

enum CopperGolemPose : string{
	case STANDING = "standing";
	case SITTING = "sitting";
	case RUNNING = "running";
	case STAR = "star";

	public function next() : self{
		return match($this){
			self::STANDING => self::SITTING,
			self::SITTING => self::RUNNING,
			self::RUNNING => self::STAR,
			self::STAR => self::STANDING
		};
	}
}
