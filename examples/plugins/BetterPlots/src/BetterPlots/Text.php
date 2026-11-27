<?php
declare(strict_types=1);
namespace BetterPlots;

final class Text{
    public static function defer(string $text,array $values=[]):string{
        $replace=[];
        foreach($values as $key=>$value){$replace['{'.$key.'}']=(string)$value;}
        return strtr($text,$replace);
    }
    public static function ref(string $text):string{return $text;}
    public static function t(mixed $recipient,string $text,array $values=[]):string{return self::defer($text,$values);}
    public static function renderDeferred(mixed $recipient,string $text):string{return $text;}
    public static function join(string $separator,array $lines):string{return implode($separator,array_map('strval',$lines));}
    public static function export(string $text):array{return ['text'=>$text];}
    public static function import(array $data):string{
        if(!is_string($data['text']??null)){throw new \InvalidArgumentException('Invalid text');}
        return $data['text'];
    }
}
