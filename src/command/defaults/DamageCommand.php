<?php

/*
 * BetterPMMP - /damage like vanilla Bedrock.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\TargetSelector;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function is_numeric;
use function strtolower;

/** /damage <target> <amount> [cause] [entity <damager>] */
final class DamageCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("damage", "Apply damage to the specified entities.", "/damage <target> <amount> [cause] [entity <damager>]");
		$this->setPermission(DefaultPermissionNames::COMMAND_DAMAGE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2 || !is_numeric($args[1]) || (float) $args[1] < 0){
			throw new InvalidCommandSyntaxException();
		}
		$cause = match(strtolower($args[2] ?? "none")){
			"fall" => EntityDamageEvent::CAUSE_FALL,
			"fire" => EntityDamageEvent::CAUSE_FIRE,
			"fire_tick" => EntityDamageEvent::CAUSE_FIRE_TICK,
			"lava" => EntityDamageEvent::CAUSE_LAVA,
			"drowning" => EntityDamageEvent::CAUSE_DROWNING,
			"suffocation" => EntityDamageEvent::CAUSE_SUFFOCATION,
			"block_explosion" => EntityDamageEvent::CAUSE_BLOCK_EXPLOSION,
			"entity_explosion" => EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
			"void" => EntityDamageEvent::CAUSE_VOID,
			"magic" => EntityDamageEvent::CAUSE_MAGIC,
			"starve" => EntityDamageEvent::CAUSE_STARVATION,
			"contact" => EntityDamageEvent::CAUSE_CONTACT,
			"projectile" => EntityDamageEvent::CAUSE_PROJECTILE,
			"entity_attack" => EntityDamageEvent::CAUSE_ENTITY_ATTACK,
			"falling_block" => EntityDamageEvent::CAUSE_FALLING_BLOCK,
			default => EntityDamageEvent::CAUSE_CUSTOM,
		};
		$damager = null;
		if(isset($args[3]) && strtolower($args[3]) === "entity" && isset($args[4])){
			$damager = TargetSelector::select($sender, $args[4])[0] ?? null;
		}
		$hit = 0;
		foreach(TargetSelector::select($sender, $args[0]) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive()){
				continue;
			}
			$event = $damager !== null && $damager !== $entity
				? new EntityDamageByEntityEvent($damager, $entity, $cause === EntityDamageEvent::CAUSE_CUSTOM ? EntityDamageEvent::CAUSE_ENTITY_ATTACK : $cause, (float) $args[1])
				: new EntityDamageEvent($entity, $cause, (float) $args[1]);
			$entity->attack($event);
			if(!$event->isCancelled()){
				++$hit;
			}
		}
		if($hit === 0){
			$sender->sendMessage("§cCould not damage the target(s)");
			return true;
		}
		$sender->sendMessage("Applied damage to " . $hit . " entities");
		return true;
	}
}
