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

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use function count;
use function implode;
use function stripos;
use function substr;
use function strtolower;
use const PHP_VERSION;

class VersionCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"version",
			KnownTranslationFactory::pocketmine_command_version_description(),
			KnownTranslationFactory::pocketmine_command_version_usage(),
			["ver", "about"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_VERSION);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			$accent = TextFormat::AQUA;
			$muted = TextFormat::GRAY;
			$bright = TextFormat::WHITE;
			$versionColor = VersionInfo::IS_DEVELOPMENT_BUILD ? TextFormat::YELLOW : TextFormat::GREEN;
			$buildLabel = VersionInfo::IS_DEVELOPMENT_BUILD ? "Development build" : "Stable build";
			$displayVersion = VersionInfo::VERSION()->getFullVersion();
			if(VersionInfo::IS_DEVELOPMENT_BUILD && VersionInfo::BUILD_NUMBER() > 0){
				$displayVersion .= "." . VersionInfo::BUILD_NUMBER();
			}
			$jitMode = Utils::getOpcacheJitMode();
			$jitLabel = $jitMode === null ? "not supported" : ($jitMode === 0 ? "disabled" : "enabled");

			$sender->sendMessage(TextFormat::BOLD . $accent . "◆ BETTERPMMP ◆" . TextFormat::RESET . $muted . "  Bedrock Server Core");
			$sender->sendMessage($muted . "Version  " . $versionColor . $displayVersion . TextFormat::RESET . $muted . "  •  " . $bright . $buildLabel);
			$sender->sendMessage($muted . "Minecraft  " . $accent . ProtocolInfo::MINECRAFT_VERSION_NETWORK . TextFormat::RESET . $muted . "  •  Protocol  " . $accent . ProtocolInfo::CURRENT_PROTOCOL . TextFormat::RESET);
			$sender->sendMessage($muted . "PHP  " . $bright . PHP_VERSION . $muted . "  •  OS  " . $bright . Utils::getOS() . $muted . "  •  JIT  " . $bright . $jitLabel . TextFormat::RESET);
			$sender->sendMessage($muted . "Build ID  " . $bright . substr(VersionInfo::GIT_HASH(), 0, 8) . TextFormat::RESET);
			$sender->sendMessage($muted . "Based on Axolotl-PM  •  Open source under LGPL-3.0");
			$sender->sendMessage($muted . "Website  " . $accent . VersionInfo::WEBSITE_URL . TextFormat::RESET);
		}else{
			$pluginName = implode(" ", $args);
			$exactPlugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

			if($exactPlugin instanceof Plugin){
				$this->describeToSender($exactPlugin, $sender);

				return true;
			}

			$found = false;
			$pluginName = strtolower($pluginName);
			foreach($sender->getServer()->getPluginManager()->getPlugins() as $plugin){
				if(stripos($plugin->getName(), $pluginName) !== false){
					$this->describeToSender($plugin, $sender);
					$found = true;
				}
			}

			if(!$found){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_noSuchPlugin());
			}
		}

		return true;
	}

	private function describeToSender(Plugin $plugin, CommandSender $sender) : void{
		$desc = $plugin->getDescription();
		$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_header(
			TextFormat::DARK_GREEN . $desc->getName() . TextFormat::RESET,
			TextFormat::DARK_GREEN . $desc->getVersion() . TextFormat::RESET
		));

		if($desc->getDescription() !== ""){
			$sender->sendMessage($desc->getDescription());
		}

		if($desc->getWebsite() !== ""){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_website($desc->getWebsite()));
		}

		if(count($authors = $desc->getAuthors()) > 0){
			if(count($authors) === 1){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_author(implode(", ", $authors)));
			}else{
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_authors(implode(", ", $authors)));
			}
		}
	}
}
