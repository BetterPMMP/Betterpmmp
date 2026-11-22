<?php

/*
 * BetterPMMP - /locate structure like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\world\generator\vanilla\structure\WorldStructures;
use function count;
use function implode;
use function sqrt;
use function str_starts_with;
use function strtolower;
use function substr;

/** /locate structure <name> (also /locate <name>) - nearest structure of the BetterPMMP generators */
final class LocateCommand extends WorldEditingCommand{

	private const ALIASES = [
		"ancient_city" => "ancient_city", "bastion_remnant" => "bastion", "bastion" => "bastion", "end_city" => "end_city",
		"fortress" => "fortress", "nether_fortress" => "fortress", "mineshaft" => "mineshaft", "stronghold" => "stronghold",
		"village" => "village", "monument" => "ocean_monument", "ocean_monument" => "ocean_monument", "mansion" => "woodland_mansion",
		"woodland_mansion" => "woodland_mansion", "pillager_outpost" => "pillager_outpost", "ruined_portal" => "ruined_portal",
		"shipwreck" => "shipwreck", "ocean_ruins" => "ocean_ruin", "ocean_ruin" => "ocean_ruin", "buried_treasure" => "buried_treasure",
		"desert_pyramid" => "desert_pyramid", "temple" => "desert_pyramid", "jungle_pyramid" => "jungle_temple", "jungle_temple" => "jungle_temple",
		"swamp_hut" => "swamp_hut", "igloo" => "igloo", "trail_ruins" => "trail_ruins", "trial_chambers" => "trial_chambers", "trial_chamber" => "trial_chambers",
	];

	public function __construct(){
		parent::__construct("locate", "Displays the coordinates for the closest structure of a given type.", "/locate structure <structure>");
		$this->setPermission(DefaultPermissionNames::COMMAND_LOCATE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$name = strtolower($args[0] === "structure" ? ($args[1] ?? throw new InvalidCommandSyntaxException()) : $args[0]);
		if(str_starts_with($name, "minecraft:")){
			$name = substr($name, 10);
		}
		$origin = $this->senderPosition($sender);
		$manager = WorldStructures::get($origin->getWorld());
		if($manager === null){
			$sender->sendMessage("§cThis world has no vanilla structures");
			return true;
		}
		$known = [];
		foreach($manager->getStructures() as $structure){
			$known[$structure->getName()] = true;
		}
		$wanted = self::ALIASES[$name] ?? $name;
		if(!isset($known[$wanted])){
			$sender->sendMessage("§cUnknown structure for this dimension: " . $name . ". Available: " . implode(", ", array_keys($known)));
			return true;
		}
		$found = $manager->locate($wanted, $origin->getFloorX(), $origin->getFloorZ());
		if($found === null){
			$sender->sendMessage("§cCould not find " . $name . " nearby");
			return true;
		}
		[$x, $z] = $found;
		$distance = (int) sqrt(($x - $origin->x) ** 2 + ($z - $origin->z) ** 2);
		$sender->sendMessage("The nearest " . $name . " is at block " . $x . ", (y?), " . $z . " (" . $distance . " blocks away)");
		return true;
	}
}
