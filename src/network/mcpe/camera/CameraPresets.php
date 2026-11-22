<?php

/*
 * BetterPMMP - the camera presets of the game, sent when a player joins (needed for /camera set).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\camera;

use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraPreset;
use function array_search;

/**
 * The vanilla presets in the order the official server sends them; /camera set refers to a preset by
 * its position in this list. The client knows how each of them behaves, so no values are overridden.
 */
final class CameraPresets{
	public const NAMES = [
		"minecraft:free",
		"minecraft:first_person",
		"minecraft:third_person",
		"minecraft:third_person_front",
		"minecraft:fixed_boom",
		"minecraft:follow_orbit",
	];

	public static function index(string $name) : ?int{
		$index = array_search($name, self::NAMES, true);
		return $index === false ? null : $index;
	}

	public static function packet() : CameraPresetsPacket{
		$presets = [];
		foreach(self::NAMES as $name){
			$presets[] = new CameraPreset($name, "", null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, false, null);
		}
		return CameraPresetsPacket::create($presets);
	}
}
