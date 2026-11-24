<?php

/*
 * BetterPMMP - /summon like vanilla.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\permission\DefaultPermissionNames;
use function count;
use function implode;
use function array_slice;
use function str_contains;
use function strtolower;

/** /summon <entity type> [x y z] [name] */
final class SummonCommand extends WorldEditingCommand{

	public function __construct(){
		parent::__construct("summon", "Summons an entity.", "/summon <entityType> [spawnPos: x y z] [nameTag]");
		$this->setPermission(DefaultPermissionNames::COMMAND_SUMMON);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}
		$type = strtolower($args[0]);
		if(!str_contains($type, ":")){
			$type = "minecraft:" . $type;
		}
		if($type === "minecraft:player"){
			return $this->error($sender, "Players cannot be summoned");
		}
		$origin = $this->senderPosition($sender);
		$world = $origin->getWorld();
		$pos = count($args) >= 4 ? $this->parsePosition($sender, $args, 1, $origin) : $origin->asVector3();
		if(!$world->isInWorld((int) $pos->x, (int) $pos->y, (int) $pos->z) || !$this->loadArea($world, (int) $pos->x, (int) $pos->z, (int) $pos->x, (int) $pos->z)){
			return $this->error($sender, "Cannot summon the entity outside of the world");
		}
		$yaw = $sender instanceof Entity ? $sender->getLocation()->getYaw() : 0.0;
		$nbt = CompoundTag::create()
			->setString(EntityFactory::TAG_IDENTIFIER, $type)
			->setTag(Entity::TAG_POS, new ListTag([new DoubleTag($pos->x), new DoubleTag($pos->y), new DoubleTag($pos->z)]))
			->setTag(Entity::TAG_MOTION, new ListTag([new DoubleTag(0), new DoubleTag(0), new DoubleTag(0)]))
			->setTag(Entity::TAG_ROTATION, new ListTag([new FloatTag($yaw), new FloatTag(0)]));
		try{
			$entity = EntityFactory::getInstance()->createFromData($world, $nbt);
		}catch(\Throwable){
			$entity = null;
		}
		if($entity === null){
			return $this->error($sender, "Unknown entity type: " . $args[0]);
		}
		if(count($args) >= 5){
			$entity->setNameTag(implode(" ", array_slice($args, 4)));
		}
		$entity->spawnToAll();
		Command::broadcastCommandMessage($sender, "Object successfully summoned");
		return true;
	}
}
