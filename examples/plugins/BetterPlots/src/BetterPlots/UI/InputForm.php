<?php
declare(strict_types=1);
namespace BetterPlots\UI;
use pocketmine\form\{Form,FormValidationException};
use pocketmine\player\Player;

final class InputForm implements Form{
    public function __construct(private string $title,private array $fields,private \Closure $submit,?Player $viewer=null){$this->fields=$viewer===null?$fields:Locale::fields($viewer,$fields);}
    public function jsonSerialize():array{
        return ['type'=>'custom_form','title'=>$this->title,'splash_raw_fields'=>['title'],'content'=>array_map(static function(array $f):array{
            if(isset($f['options'])){return ['type'=>'dropdown','splash_raw'=>true,'text'=>$f['label'],'options'=>$f['options'],'default'=>max(0,min(count($f['options'])-1,(int)($f['default']??0)))];}
            return ['type'=>'input','splash_raw'=>true,'text'=>$f['label'],'placeholder'=>$f['placeholder']??'','default'=>(string)($f['default']??'')];
        },$this->fields)];
    }
    public function handleResponse(Player $p,mixed $data):void{
        if($data===null){return;}
        if(!is_array($data)||!array_is_list($data)||count($data)!==count($this->fields)){throw new FormValidationException('Ungültige Eingabe.');}
        $args=[];
        foreach($this->fields as $i=>$f){
            $v=$data[$i];if(isset($f['options'])){if(!is_int($v)||!isset($f['options'][$v])){throw new FormValidationException('Ungültige Auswahl.');}$v=$f['values'][$v]??$f['options'][$v];}
            if(!is_string($v)||strlen($v)>1000||str_contains($v,"\n")||str_contains($v,"\r")){throw new FormValidationException('Ungültiger Text.');}
            $v=trim($v);if($v===''&&!($f['optional']??false)){$p->sendMessage(Locale::text($p,'§cBitte alle Pflichtfelder ausfüllen.'));return;}
            if($v!==''){$args[]=$v;}
        }
        ($this->submit)($p,$args);
    }
}
