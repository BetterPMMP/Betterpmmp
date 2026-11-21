<?php

/*
 * BetterPMMP - console sender that also collects the command output (for the in-game console menu).
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\console;

use pocketmine\lang\Translatable;
use function implode;

/**
 * Runs commands with full console rights and remembers everything they print, so the
 * console menu can show the output in a window. The output still goes to the real console.
 */
final class CapturingConsoleSender extends ConsoleCommandSender{

	/** @var string[] */
	private array $lines = [];

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$message = $this->getLanguage()->translate($message);
		}
		$this->lines[] = $message;
		parent::sendMessage($message);
	}

	/** Everything printed since the last call, then forgets it. */
	public function takeOutput() : string{
		$out = implode("\n", $this->lines);
		$this->lines = [];
		return $out;
	}
}
