<?php

/*
 * BetterPMMP - /spreadplayers like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\block\Liquid;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\entity\Location;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function is_numeric;
use function mt_getrandmax;
use function mt_rand;

/** /spreadplayers <x> <z> <spreadDistance> <maxRange> <victims> - random safe spots, at least spreadDistance apart */
final class SpreadplayersCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("spreadplayers", "Teleports entities to random locations.", "/spreadplayers <x> <z> <spreadDistance> <maxRange> <victim>");
		$this->setPermission(DefaultPermissionNames::COMMAND_SPREADPLAYERS);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 5 || !is_numeric($args[2]) || !is_numeric($args[3])){
			throw new InvalidCommandSyntaxException();
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$cx = $this->getRelativeDouble($origin->x, $sender, $args[0]);
		$cz = $this->getRelativeDouble($origin->z, $sender, $args[1]);
		$spread = (float) $args[2];
		$range = (float) $args[3];
		if($range < $spread || $range <= 0){
			return $this->error($sender, "maxRange must be bigger than spreadDistance");
		}
		$targets = TargetSelector::select($sender, $args[4]);
		if($targets === []){
			return $this->error($sender, "No targets matched selector");
		}
		$placed = [];
		foreach($targets as $entity){
			$spot = null;
			for($attempt = 0; $attempt < 200 && $spot === null; ++$attempt){
				$x = (int) ($cx + (mt_rand() / mt_getrandmax() * 2 - 1) * $range);
				$z = (int) ($cz + (mt_rand() / mt_getrandmax() * 2 - 1) * $range);
				foreach($placed as [$px, $pz]){
					if(($px - $x) ** 2 + ($pz - $z) ** 2 < $spread * $spread){
						continue 2;
					}
				}
				if(!$this->loadArea($world, $x, $z, $x, $z)){
					continue;
				}
				$y = $world->getHighestBlockAt($x, $z);
				if($y === null || $world->getBlockAt($x, $y, $z) instanceof Liquid || $world->getBlockAt($x, $y, $z)->getTypeId() === \pocketmine\block\BlockTypeIds::LAVA){
					continue; //vanilla never puts anyone into water or lava
				}
				$spot = [$x, $y + 1, $z];
			}
			if($spot === null){
				return $this->error($sender, "Could not spread " . count($targets) . " entities around " . (int) $cx . ", " . (int) $cz . " (too many entities for space - try using spread of at most " . (int) ($range / 2) . ")");
			}
			$placed[] = [$spot[0], $spot[2]];
			$entity->teleport(new Location($spot[0] + 0.5, $spot[1], $spot[2] + 0.5, $world, $entity->getLocation()->yaw, $entity->getLocation()->pitch));
		}
		Command::broadcastCommandMessage($sender, "Successfully spread " . count($targets) . " entities around " . (int) $cx . ", " . (int) $cz);
		return true;
	}
}
