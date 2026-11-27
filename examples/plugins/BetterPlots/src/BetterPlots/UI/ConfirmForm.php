<?php
declare(strict_types=1);
namespace BetterPlots\UI;
use pocketmine\form\{Form,FormValidationException};
use pocketmine\player\Player;
final class ConfirmForm implements Form{
    public function __construct(private string $title,private string $text,private \Closure $answer,private ?Player $viewer=null){}
    public function jsonSerialize():array{return ['type'=>'modal','title'=>$this->title,'content'=>$this->text,'splash_raw_fields'=>['title','content','button1','button2'],'button1'=>\BetterPlots\Text::t($this->viewer,'§aBestätigen'),'button2'=>\BetterPlots\Text::t($this->viewer,'§cAbbrechen')];}
    public function handleResponse(Player $p,mixed $data):void{if($data!==null&&!is_bool($data)){throw new FormValidationException('Ungültige Bestätigung.');}($this->answer)($p,$data===true);}
}
