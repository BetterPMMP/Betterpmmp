<?php
declare(strict_types=1);
namespace BetterPlots\UI;

use pocketmine\player\Player;
use BetterPlots\Text as L;

final class Locale{
    public static function text(Player $p,string $text):string{return L::renderDeferred($p,$text);}
    public static function fields(Player $p,array $fields):array{
        foreach($fields as &$field){
            $field['label']=self::text($p,(string)($field['label']??''));
            if(isset($field['placeholder'])){$field['placeholder']=self::text($p,(string)$field['placeholder']);}
            if(isset($field['options'])){
                $field['values']??=$field['options'];
                foreach($field['options'] as &$option){
                    $label=($field['source']??'')==='flags'?FlagMenu::name((string)$option):(match((string)$option){
                        'mine'=>'Eigene','shared'=>'Geteilte','world'=>'Diese Welt','all'=>'Alle','forsale'=>'Zum Verkauf','top'=>'Beste Bewertungen','done'=>'Fertiggestellt',
                        'public'=>'Öffentlich','owner'=>'Besitzer','report'=>'Meldung',default=>(string)$option
                    });
                    $option=self::text($p,$label);
                }unset($option);
            }
        }unset($field);return $fields;
    }
    public static function definition(Player $p,array $definition):array{
        foreach(['title','description'] as $key){if(isset($definition[$key])){$definition[$key]=self::text($p,(string)$definition[$key]);}}
        foreach($definition['entries']??[] as $i=>$entry){
            foreach(['label','hint'] as $key){if(isset($entry[$key])){$entry[$key]=self::text($p,(string)$entry[$key]);}}
            if(isset($entry['fields'])){$entry['fields']=self::fields($p,$entry['fields']);}
            $definition['entries'][$i]=$entry;
        }
        return $definition;
    }
}
