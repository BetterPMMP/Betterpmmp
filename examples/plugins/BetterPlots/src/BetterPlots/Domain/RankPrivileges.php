<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

use pocketmine\command\CommandSender;
use pocketmine\item\StringToItemParser;
use BetterPlots\Domain\PlotStyles;

final class RankPrivileges{
    public static function mergeLimit(CommandSender $sender,bool $admin):int{return $admin?256:1;}
    public static function checkMerge(CommandSender $sender,bool $admin,int $cells):void{
        if($cells>self::mergeLimit($sender,$admin)){
            throw new \RuntimeException('Mergen ist vorübergehend nur für OPs möglich.');
        }
    }
    public static function allowed(CommandSender $sender,bool $admin,string $kind,string $block):bool{
        $entry=PlotStyles::STYLES[$kind][$block]??null;
        return $entry!==null&&($admin||$entry[1]===0||$sender->hasPermission(PlotStyles::permission($kind,$block)));
    }
    public static function material(CommandSender $sender,bool $admin,string $kind,string $block):string{
        if($kind==='outline'){$kind='border';}
        if(!in_array($kind,['border','wall'],true)){

            if(!$admin&&in_array($kind,['all','main','middle'],true)){throw new \RuntimeException('Fuer grossflaechige Wand-Aenderungen bitte /p wand verwenden; diese Gesamtbearbeitung ist nur fuer OP.');}
            return $block;
        }
        $parser=StringToItemParser::getInstance();$actual=$parser->parse($block)?->getBlock();
        if($actual===null){throw new \InvalidArgumentException('Unbekanntes Material.');}
        foreach(PlotStyles::STYLES[$kind] as $id=>$entry){
            $candidate=$parser->parse($id)?->getBlock();
            if($candidate===null||$candidate->getStateId()!==$actual->getStateId()){continue;}
            if(!self::allowed($sender,$admin,$kind,$id)){
                throw new \RuntimeException($entry[0].': ab '.PlotStyles::rankName($entry[1]).' freigeschaltet.');
            }
            return $id;
        }
        if($admin&&($kind==='wall'||$actual instanceof \pocketmine\block\Slab&&$actual->getSlabType()!==\pocketmine\block\utils\SlabType::DOUBLE)){return $block;}
        throw new \RuntimeException($kind==='border'?'Der Rand erlaubt nur freigeschaltete halbe Stufen oder Luft. /p rand':'Dieses Wandmaterial ist nicht freigeschaltet. /p wand');
    }
}
