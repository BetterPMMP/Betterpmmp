<?php

/*
 * BetterPMMP - pocketmine\utils\UUID of API 3 for old plugins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\utils;

use Ramsey\Uuid\Uuid as RamseyUuid;
use Ramsey\Uuid\UuidInterface;

/**
 * API 3 had its own UUID class. PocketMine-MP 5 uses ramsey/uuid; these factories return such objects, which
 * have the same toString() and equals() that old plugins call.
 */
final class UUID{

	private function __construct(){}

	public static function fromString(string $uuid, ?int $version = null) : UuidInterface{ return RamseyUuid::fromString($uuid); }

	public static function fromBinary(string $uuid, ?int $version = null) : UuidInterface{ return RamseyUuid::fromBytes($uuid); }

	public static function fromRandom() : UuidInterface{ return RamseyUuid::uuid4(); }

	public static function fromData(string ...$data) : UuidInterface{ return RamseyUuid::uuid3(RamseyUuid::NIL, implode("", $data)); }
}
