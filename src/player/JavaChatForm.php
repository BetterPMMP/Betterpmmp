<?php

/*
 * BetterPMMP - forms as chat menus for Java Edition players.
 * LGPL-3.0-or-later, like the surrounding PocketMine-derived core.
 */

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\utils\TextFormat;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function mb_strtolower;
use function round;
use function trim;

/**
 * Java Edition players join through ViaProxy, which cannot show Bedrock form
 * windows reliably. For them a form is shown in the chat instead: buttons are
 * numbered and answered by typing the number; a custom form asks one question
 * per field. The answer is handed to the form exactly like a Bedrock client's
 * response, so every plugin keeps working unchanged. "0" or "x" closes it.
 */
final class JavaChatForm{

	/** @var mixed[] */
	private array $data;
	/** @var mixed[] answers of a custom form so far */
	private array $answers = [];
	private int $field = 0;

	/**
	 * @param mixed[] $data the form's JSON data (Form::jsonSerialize())
	 */
	public function __construct(
		public readonly int $formId,
		array $data
	){
		$this->data = $data;
	}

	private function type() : string{
		return is_string($this->data["type"] ?? null) ? $this->data["type"] : "";
	}

	private static function text(mixed $value) : string{
		return is_scalar($value) ? (string) $value : "";
	}

	/** @return mixed[] */
	private function fields() : array{
		return is_array($this->data["content"] ?? null) ? $this->data["content"] : [];
	}

	/** Shows the form (or the current question of a custom form) in the chat. */
	public function show(Player $player) : void{
		$lines = [TextFormat::GOLD . TextFormat::BOLD . self::text($this->data["title"] ?? "Menü") . TextFormat::RESET];
		switch($this->type()){
			case "form":
				$content = self::text($this->data["content"] ?? "");
				if($content !== ""){
					$lines[] = TextFormat::WHITE . $content;
				}
				$buttons = is_array($this->data["buttons"] ?? null) ? $this->data["buttons"] : [];
				foreach($buttons as $i => $button){
					$lines[] = TextFormat::YELLOW . ($i + 1) . TextFormat::GRAY . ") " . TextFormat::WHITE . self::text(is_array($button) ? ($button["text"] ?? "") : "");
				}
				$lines[] = TextFormat::GRAY . "Zahl in den Chat schreiben, " . TextFormat::YELLOW . "0" . TextFormat::GRAY . " = schließen";
				break;
			case "modal":
				$lines[] = TextFormat::WHITE . self::text($this->data["content"] ?? "");
				$lines[] = TextFormat::YELLOW . "1" . TextFormat::GRAY . ") " . TextFormat::WHITE . self::text($this->data["button1"] ?? "Ja");
				$lines[] = TextFormat::YELLOW . "2" . TextFormat::GRAY . ") " . TextFormat::WHITE . self::text($this->data["button2"] ?? "Nein");
				$lines[] = TextFormat::GRAY . "Zahl in den Chat schreiben";
				break;
			case "custom_form":
				$this->skipLabels($lines);
				$question = $this->question();
				if($question === null){
					return;
				}
				$lines[] = $question;
				$lines[] = TextFormat::GRAY . "Antwort in den Chat schreiben, " . TextFormat::YELLOW . "x" . TextFormat::GRAY . " = abbrechen";
				break;
			default:
				return;
		}
		$player->sendMessage(implode("\n", $lines));
	}

	/**
	 * Adds labels at the current position to the output and skips them (a label has no answer).
	 * @param string[] $lines
	 */
	private function skipLabels(array &$lines) : void{
		$fields = $this->fields();
		while(isset($fields[$this->field]) && is_array($fields[$this->field]) && ($fields[$this->field]["type"] ?? "") === "label"){
			$lines[] = TextFormat::WHITE . self::text($fields[$this->field]["text"] ?? "");
			$this->answers[] = null;
			++$this->field;
		}
	}

	private function question() : ?string{
		$field = $this->fields()[$this->field] ?? null;
		if(!is_array($field)){
			return null;
		}
		$text = TextFormat::AQUA . "(" . ($this->field + 1) . "/" . count($this->fields()) . ") " . TextFormat::WHITE . self::text($field["text"] ?? "");
		switch($field["type"] ?? ""){
			case "input":
				$default = self::text($field["default"] ?? "");
				return $text . "\n" . TextFormat::GRAY . "Text eingeben" . ($default !== "" ? " (" . TextFormat::YELLOW . "-" . TextFormat::GRAY . " = \"$default\")" : "");
			case "toggle":
				return $text . "\n" . TextFormat::YELLOW . "ja" . TextFormat::GRAY . " / " . TextFormat::YELLOW . "nein" . TextFormat::GRAY . " (jetzt: " . (($field["default"] ?? false) === true ? "ja" : "nein") . ")";
			case "dropdown":
			case "step_slider":
				$options = is_array($field["options"] ?? null) ? $field["options"] : (is_array($field["steps"] ?? null) ? $field["steps"] : []);
				foreach($options as $i => $option){
					$text .= "\n" . TextFormat::YELLOW . ($i + 1) . TextFormat::GRAY . ") " . TextFormat::WHITE . self::text($option);
				}
				return $text;
			case "slider":
				return $text . "\n" . TextFormat::GRAY . "Zahl von " . self::text($field["min"] ?? 0) . " bis " . self::text($field["max"] ?? 100);
			default:
				return $text;
		}
	}

	/**
	 * Takes a chat message as the answer.
	 *
	 * @return array{bool, mixed} [finished, response data]; response null means closed
	 */
	public function answer(Player $player, string $message) : array{
		$message = trim($message);
		$lower = mb_strtolower($message);
		if($lower === "x" || ($lower === "0" && $this->type() !== "custom_form")){
			return [true, null];
		}
		switch($this->type()){
			case "form":
				$buttons = is_array($this->data["buttons"] ?? null) ? $this->data["buttons"] : [];
				if(!is_numeric($message) || (int) $message < 1 || (int) $message > count($buttons)){
					$player->sendMessage(TextFormat::RED . "Bitte eine Zahl von 1 bis " . count($buttons) . " schreiben (0 = schließen).");
					return [false, null];
				}
				return [true, (int) $message - 1];
			case "modal":
				if($message !== "1" && $message !== "2"){
					$player->sendMessage(TextFormat::RED . "Bitte 1 oder 2 schreiben.");
					return [false, null];
				}
				return [true, $message === "1"];
			case "custom_form":
				$field = $this->fields()[$this->field] ?? null;
				if(!is_array($field)){
					return [true, $this->answers];
				}
				$value = $this->parseField($field, $message, $lower);
				if($value === null){
					$player->sendMessage(TextFormat::RED . "Ungültige Antwort, bitte nochmal (x = abbrechen).");
					return [false, null];
				}
				$this->answers[] = $value[0];
				++$this->field;
				$lines = [];
				$this->skipLabels($lines);
				if($this->field >= count($this->fields())){
					return [true, $this->answers];
				}
				if($lines !== []){
					$player->sendMessage(implode("\n", $lines));
				}
				$player->sendMessage((string) $this->question());
				return [false, null];
		}
		return [true, null];
	}

	/**
	 * @param mixed[] $field
	 * @return array{mixed}|null the parsed value wrapped in an array, or null if the answer is invalid
	 */
	private function parseField(array $field, string $message, string $lower) : ?array{
		switch($field["type"] ?? ""){
			case "input":
				return [$message === "-" ? self::text($field["default"] ?? "") : $message];
			case "toggle":
				return match($lower){
					"ja", "j", "yes", "y", "1", "an", "true" => [true],
					"nein", "n", "no", "0", "aus", "false" => [false],
					default => null,
				};
			case "dropdown":
			case "step_slider":
				$options = is_array($field["options"] ?? null) ? $field["options"] : (is_array($field["steps"] ?? null) ? $field["steps"] : []);
				if(!is_numeric($message) || (int) $message < 1 || (int) $message > count($options)){
					return null;
				}
				return [(int) $message - 1];
			case "slider":
				if(!is_numeric($message)){
					return null;
				}
				$min = (float) ($field["min"] ?? 0);
				$max = (float) ($field["max"] ?? 100);
				return [round(max($min, min($max, (float) $message)), 2)];
		}
		return [null];
	}
}
