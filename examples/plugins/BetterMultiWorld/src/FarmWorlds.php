<?php

declare(strict_types=1);

namespace leondev\bettermultiworld;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function preg_match;
use function strtolower;

final class FarmWorlds{

	public static function name(string $name) : string{
		$reserved = ["default", "con", "prn", "aux", "nul"];
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/D', $name) !== 1 || in_array(strtolower($name), $reserved, true) || preg_match('/^(com|lpt)[1-9]$/i', $name) === 1){
			throw new \DomainException("Weltname: 1 bis 48 Buchstaben, Ziffern, _ oder -, keine Pfade oder reservierten Namen.");
		}
		return $name;
	}

	public static function enable(array $data, string $name) : array{
		self::name($name);
		$section = $data["mob-spawning"] ?? [];
		if(!is_array($section) || !is_array($section["worlds"] ?? []) || !is_array($section["disabled-worlds"] ?? [])){
			throw new \DomainException("Ungültige mob-spawning-Listen in betterpmmp.yml. Bitte zuerst korrigieren.");
		}
		$worlds = $section["worlds"] ?? ["default"];
		$lower = array_map(static fn($world) => strtolower((string) $world), $worlds);
		if(!in_array(strtolower($name), $lower, true)){
			$worlds[] = $name;
		}
		$section["worlds"] = array_values($worlds);
		$section["disabled-worlds"] = array_values(array_filter($section["disabled-worlds"] ?? [], static fn($world) => strtolower((string) $world) !== strtolower($name)));
		$data["mob-spawning"] = $section;
		return $data;
	}
}
