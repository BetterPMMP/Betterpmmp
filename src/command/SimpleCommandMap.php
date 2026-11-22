<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\command;

use pocketmine\command\defaults\BanCommand;
use pocketmine\command\defaults\BanIpCommand;
use pocketmine\command\defaults\BanListCommand;
use pocketmine\command\defaults\ClearCommand;
use pocketmine\command\defaults\DefaultGamemodeCommand;
use pocketmine\command\defaults\DeopCommand;
use pocketmine\command\defaults\DifficultyCommand;
use pocketmine\command\defaults\DumpMemoryCommand;
use pocketmine\command\defaults\EffectCommand;
use pocketmine\command\defaults\EnchantCommand;
use pocketmine\command\defaults\GamemodeCommand;
use pocketmine\command\defaults\GarbageCollectorCommand;
use pocketmine\command\defaults\GiveCommand;
use pocketmine\command\defaults\HelpCommand;
use pocketmine\command\defaults\KickCommand;
use pocketmine\command\defaults\KillCommand;
use pocketmine\command\defaults\ListCommand;
use pocketmine\command\defaults\MeCommand;
use pocketmine\command\defaults\OpCommand;
use pocketmine\command\defaults\PardonCommand;
use pocketmine\command\defaults\PardonIpCommand;
use pocketmine\command\defaults\ParticleCommand;
use pocketmine\command\defaults\PluginsCommand;
use pocketmine\command\defaults\SaveCommand;
use pocketmine\command\defaults\SaveOffCommand;
use pocketmine\command\defaults\SaveOnCommand;
use pocketmine\command\defaults\SayCommand;
use pocketmine\command\defaults\SeedCommand;
use pocketmine\command\defaults\SetWorldSpawnCommand;
use pocketmine\command\defaults\SpawnpointCommand;
use pocketmine\command\defaults\StatusCommand;
use pocketmine\command\defaults\StopCommand;
use pocketmine\command\defaults\TeleportCommand;
use pocketmine\command\defaults\TellCommand;
use pocketmine\command\defaults\TimeCommand;
use pocketmine\command\defaults\SwapHandsCommand;
use pocketmine\command\defaults\WeatherCommand;
use pocketmine\command\defaults\TimingsCommand;
use pocketmine\command\defaults\TitleCommand;
use pocketmine\command\defaults\TransferServerCommand;
use pocketmine\command\defaults\VanillaCommand;
use pocketmine\command\defaults\VersionCommand;
use pocketmine\command\defaults\WhitelistCommand;
use pocketmine\command\defaults\XpCommand;
use pocketmine\command\utils\CommandStringHelper;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use function array_shift;
use function array_values;
use function count;
use function implode;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function trim;

class SimpleCommandMap implements CommandMap{

	/**
	 * @var Command[]
	 * @phpstan-var array<string, Command>
	 */
	protected array $knownCommands = [];

	public function __construct(private Server $server){
		$this->setDefaultCommands();
	}

	private function setDefaultCommands() : void{
		$this->registerAll("pocketmine", [
			new BanCommand(),
			new BanIpCommand(),
			new BanListCommand(),
			new ClearCommand(),
			new DefaultGamemodeCommand(),
			new DeopCommand(),
			new DifficultyCommand(),
			new DumpMemoryCommand(),
			new EffectCommand(),
			new EnchantCommand(),
			new GamemodeCommand(),
			new GarbageCollectorCommand(),
			new GiveCommand(),
			new HelpCommand(),
			new KickCommand(),
			new KillCommand(),
			new ListCommand(),
			new MeCommand(),
			new OpCommand(),
			new PardonCommand(),
			new PardonIpCommand(),
			new ParticleCommand(),
			new PluginsCommand(),
			new SaveCommand(),
			new SaveOffCommand(),
			new SaveOnCommand(),
			new SayCommand(),
			new SeedCommand(),
			new SetWorldSpawnCommand(),
			new SpawnpointCommand(),
			new StatusCommand(),
			new StopCommand(),
			new TeleportCommand(),
			new TellCommand(),
			new TimeCommand(),
			new WeatherCommand(),
			new SwapHandsCommand(),
			new TimingsCommand(),
			new TitleCommand(),
			new TransferServerCommand(),
			new VersionCommand(),
			new WhitelistCommand(),
			new XpCommand(),
			new \pocketmine\command\defaults\SetblockCommand(),
			new \pocketmine\command\defaults\GameruleCommand(),
			new \pocketmine\command\defaults\CloneCommand(),
			new \pocketmine\command\defaults\ScoreboardCommand(),
			new \pocketmine\command\defaults\ExecuteCommand(),
			new \pocketmine\command\defaults\FillCommand(),
			new \pocketmine\command\defaults\SummonCommand(),
			new \pocketmine\command\defaults\TellrawCommand(),
			new \pocketmine\command\defaults\PlaysoundCommand(),
		]);
	}

	public function registerAll(string $fallbackPrefix, array $commands) : void{
		foreach($commands as $command){
			$this->register($fallbackPrefix, $command);
		}
	}

	public function register(string $fallbackPrefix, Command $command, ?string $label = null) : bool{
		if(count($command->getPermissions()) === 0){
			throw new \InvalidArgumentException("Commands must have a permission set");
		}

		if($label === null){
			$label = $command->getLabel();
		}
		$label = trim($label);
		$fallbackPrefix = strtolower(trim($fallbackPrefix));

		$registered = $this->registerAlias($command, false, $fallbackPrefix, $label);

		$aliases = $command->getAliases();
		foreach($aliases as $index => $alias){
			if(!$this->registerAlias($command, true, $fallbackPrefix, $alias)){
				unset($aliases[$index]);
			}
		}
		$command->setAliases(array_values($aliases));

		if(!$registered){
			$command->setLabel($fallbackPrefix . ":" . $label);
		}

		$command->register($this);

		return $registered;
	}

	public function unregister(Command $command) : bool{
		foreach(Utils::promoteKeys($this->knownCommands) as $lbl => $cmd){
			if($cmd === $command){
				unset($this->knownCommands[$lbl]);
			}
		}

		$command->unregister($this);

		return true;
	}

	private function registerAlias(Command $command, bool $isAlias, string $fallbackPrefix, string $label) : bool{
		$this->knownCommands[$fallbackPrefix . ":" . $label] = $command;
		if(($command instanceof VanillaCommand || $isAlias) && isset($this->knownCommands[$label])){
			return false;
		}

		if(isset($this->knownCommands[$label]) && $this->knownCommands[$label]->getLabel() === $label){
			return false;
		}

		if(!$isAlias){
			$command->setLabel($label);
		}

		$this->knownCommands[$label] = $command;

		return true;
	}

	/**
	 * BetterPMMP: like vanilla, a selector that matches several players (@a, @e, @r[c=3] ...) runs the command
	 * once for every player - for all commands, plugin commands included. Commands that understand selectors
	 * themselves (they can also target mobs) get the selector as it is.
	 * @param string[] $args
	 */
	private function executeWithSelectors(CommandSender $sender, Command $command, string $label, array $args) : void{
		static $selectorAware = ["kill", "tellraw", "playsound", "say", "me", "summon", "gamerule", "setblock", "fill", "clone", "execute", "scoreboard"];
		$index = null;
		foreach($args as $i => $arg){
			if(\pocketmine\command\utils\TargetSelector::isSelector($arg) && strtolower($arg) !== "@s"){
				$index = $i;
				break;
			}
		}
		if($index === null || in_array($command->getLabel(), $selectorAware, true)){
			$command->execute($sender, $label, $args);
			return;
		}
		$players = \pocketmine\command\utils\TargetSelector::selectPlayers($sender, $args[$index]);
		if($players === []){
			$sender->sendMessage(TextFormat::RED . "No targets matched selector");
			return;
		}
		if(count($players) === 1 && $command instanceof \pocketmine\command\defaults\VanillaCommand){
			$args[$index] = $players[0]->getName(); //exact player, not a name prefix
			$command->execute($sender, $label, $args);
			return;
		}
		foreach($players as $player){
			$args[$index] = $player->getName();
			$command->execute($sender, $label, $args);
		}
	}

	/** BetterPMMP: the command line of the running command after its label, unchanged (JSON, "run ..." of /execute) */
	private static ?string $rawArguments = null;

	/**
	 * The raw text of the running command after its first $skip arguments (null outside of a command).
	 * Lets commands read JSON or a nested command exactly as typed instead of split into words.
	 */
	public static function rawArgumentsAfter(int $skip) : ?string{
		if(self::$rawArguments === null){
			return null;
		}
		if(preg_match_all('/"((?:\\\\.|[^\\\\"])*)"|(\S+)/u', self::$rawArguments, $matches, PREG_OFFSET_CAPTURE) === false){
			return null;
		}
		if($skip >= count($matches[0])){
			return "";
		}
		return substr(self::$rawArguments, $matches[0][$skip][1]);
	}

	public function dispatch(CommandSender $sender, string $commandLine) : bool{
		$args = CommandStringHelper::parseQuoteAware($commandLine);
		$previousRaw = self::$rawArguments;
		self::$rawArguments = preg_match('/^\s*\S+\s*(.*)$/su', $commandLine, $rawMatch) === 1 ? $rawMatch[1] : "";
		try{
			return $this->dispatchParsed($sender, $args);
		}finally{
			self::$rawArguments = $previousRaw;
		}
	}

	/** @param string[] $args */
	private function dispatchParsed(CommandSender $sender, array $args) : bool{

		$sentCommandLabel = array_shift($args);
		if($sentCommandLabel !== null && ($target = $this->getCommand($sentCommandLabel)) !== null){
			$timings = Timings::getCommandDispatchTimings($target->getLabel());
			$timings->startTiming();

			try{
				if($target->testPermission($sender)){
					$this->executeWithSelectors($sender, $target, $sentCommandLabel, $args);
				}
			}catch(InvalidCommandSyntaxException $e){
				$sender->sendMessage($sender->getLanguage()->translate(KnownTranslationFactory::commands_generic_usage($target->getUsage())));
			}finally{
				$timings->stopTiming();
			}
			return true;
		}

		$sender->sendMessage(KnownTranslationFactory::pocketmine_command_notFound($sentCommandLabel ?? "", "/help")->prefix(TextFormat::RED));
		return false;
	}

	public function clearCommands() : void{
		foreach($this->knownCommands as $command){
			$command->unregister($this);
		}
		$this->knownCommands = [];
		$this->setDefaultCommands();
	}

	public function getCommand(string $name) : ?Command{
		return $this->knownCommands[$name] ?? null;
	}

	/**
	 * @return Command[]
	 * @phpstan-return array<string, Command>
	 */
	public function getCommands() : array{
		return $this->knownCommands;
	}

	public function registerServerAliases() : void{
		$values = $this->server->getCommandAliases();

		foreach(Utils::stringifyKeys($values) as $alias => $commandStrings){
			if(str_contains($alias, ":")){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_command_alias_illegal($alias)));
				continue;
			}

			$targets = [];
			$bad = [];
			$recursive = [];

			foreach($commandStrings as $commandString){
				$args = CommandStringHelper::parseQuoteAware($commandString);
				$commandName = array_shift($args) ?? "";
				$command = $this->getCommand($commandName);

				if($command === null){
					$bad[] = $commandString;
				}elseif(strcasecmp($commandName, $alias) === 0){
					$recursive[] = $commandString;
				}else{
					$targets[] = $commandString;
				}
			}

			if(count($recursive) > 0){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_command_alias_recursive($alias, implode(", ", $recursive))));
				continue;
			}

			if(count($bad) > 0){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_command_alias_notFound($alias, implode(", ", $bad))));
				continue;
			}

			//These registered commands have absolute priority
			$lowerAlias = strtolower($alias);
			if(count($targets) > 0){
				$this->knownCommands[$lowerAlias] = new FormattedCommandAlias($lowerAlias, $targets);
			}else{
				unset($this->knownCommands[$lowerAlias]);
			}

		}
	}
}
