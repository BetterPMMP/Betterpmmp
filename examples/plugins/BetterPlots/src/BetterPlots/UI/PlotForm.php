<?php
declare(strict_types=1);
namespace BetterPlots\UI;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class PlotForm implements Form{

    public function __construct(private string $title,private string $content,private array $labels,private \Closure $select){}
    public function jsonSerialize():array{return ['type'=>'form','title'=>$this->title,'content'=>$this->content,'buttons'=>array_map(static fn(string $s):array=>['text'=>$s],$this->labels)];}
    public function handleResponse(Player $player,mixed $data):void{
        if($data===null){return;}
        if(!is_int($data)||!isset($this->labels[$data])){throw new \pocketmine\form\FormValidationException('Ungültige Auswahl.');}
        ($this->select)($player,$data);
    }
}
