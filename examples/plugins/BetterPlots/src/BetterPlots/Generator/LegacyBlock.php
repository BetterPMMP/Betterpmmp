<?php
declare(strict_types=1);
namespace BetterPlots\Generator;
use pocketmine\block\{Block,RuntimeBlockStateRegistry};
use pocketmine\item\StringToItemParser;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

final class LegacyBlock{
    public static function parse(string $name):Block{
        $name=trim($name);
        if(preg_match('/^([0-9]+)(?::([0-9]+))?$/D',$name,$m)){
            $id=filter_var($m[1],FILTER_VALIDATE_INT);$meta=filter_var($m[2]??'0',FILTER_VALIDATE_INT);
            if($id===false||$meta===false||$id>65535||$meta>15){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Ungueltige alte Block-ID: {v1}',['v1'=>$name]));}
            try{
                $data=GlobalBlockStateHandlers::getUpgrader()->upgradeIntIdMeta($id,$meta);
                $state=GlobalBlockStateHandlers::getDeserializer()->deserialize($data);
                return RuntimeBlockStateRegistry::getInstance()->fromStateId($state);
            }catch(\Throwable $e){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Alte Block-ID nicht konvertierbar: {v1}',['v1'=>$name]),0,$e);}
        }
        $item=StringToItemParser::getInstance()->parse($name);
        if($item===null){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Unbekannter Block: {v1}',['v1'=>$name]));}
        return $item->getBlock();
    }
}
