<?php
declare(strict_types=1);
namespace BetterPlots\Storage;

final class LocalizedException extends \RuntimeException{
    public function __construct(public readonly string $template,public readonly array $parameters){
        $replace=[];foreach($parameters as $key=>$value){$replace['{'.$key.'}']=(string)$value;}
        parent::__construct(strtr($template,$replace));
    }
}
