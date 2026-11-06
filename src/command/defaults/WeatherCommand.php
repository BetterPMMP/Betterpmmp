<?php

/*
 * BetterPMMP - /weather like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function count;
use function intdiv;
use function is_numeric;

/** /weather <clear|rain|thunder> [duration in seconds], /weather query */
class WeatherCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("weather", "Sets the weather", "/weather <clear|rain|thunder> [duration] | /weather query", ["toggledownfall"]);
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}
		$world = $sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){
			return true;
		}
		$weather = $world->getWeather();
		if($commandLabel === "toggledownfall"){
			$weather->set(!$weather->isRaining(), false);
			Command::broadcastCommandMessage($sender, "Toggled downfall");
			return true;
		}
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		if($args[0] === "query"){
			$state = $weather->isThundering() ? "thunder" : ($weather->isRaining() ? "rain" : "clear");
			$sender->sendMessage("Weather state is: $state (changes in " . intdiv($weather->getRainTime(), 20) . " s)");
			return true;
		}
		$duration = null;
		if(isset($args[1])){
			if(!is_numeric($args[1]) || (int) $args[1] < 1 || (int) $args[1] > 1000000){
				throw new InvalidCommandSyntaxException();
			}
			$duration = (int) $args[1] * 20;
		}
		[$rain, $thunder, $message] = match($args[0]){
			"clear" => [false, false, "Changing to clear weather"],
			"rain" => [true, false, "Changing to rainy weather"],
			"thunder" => [true, true, "Changing to rain and thunder"],
			default => throw new InvalidCommandSyntaxException(),
		};
		$weather->set($rain, $thunder, $duration);
		Command::broadcastCommandMessage($sender, $message);
		return true;
	}
}
