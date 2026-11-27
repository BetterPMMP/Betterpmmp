<?php

/*
 * BetterPMMP - pocketmine\command\PluginIdentifiableCommand of API 3 for old plugins.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\command;

/**
 * API 3 commands implemented this to name their plugin (getPlugin()). PocketMine-MP 5 uses PluginOwned with
 * getOwningPlugin(); this empty interface keeps old command classes loadable.
 */
interface PluginIdentifiableCommand{
}
