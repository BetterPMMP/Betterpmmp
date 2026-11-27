<?php
declare(strict_types=1);
namespace BetterPlots\World;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\nbt\{LittleEndianNbtSerializer,TreeRoot};
use pocketmine\block\RuntimeBlockStateRegistry;
use BetterPlots\Protection\Guard;

final class SnapshotCodec{
    public static function state(int $state):string{return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot(GlobalBlockStateHandlers::getSerializer()->serialize($state)->toNbt())));}
    public static function runtime(string $value,bool $allowEngineBlocks=false):int{
        $raw=base64_decode($value,true);if($raw===false||strlen($raw)>65536){throw new \UnexpectedValueException('Ungültiger Paletteneintrag.');}
        $tag=(new LittleEndianNbtSerializer())->read($raw)->mustGetCompoundTag();
        $state=GlobalBlockStateHandlers::getDeserializer()->deserialize(BlockStateData::fromNbt($tag));
        if(!$allowEngineBlocks&&!Guard::safeBlock(RuntimeBlockStateRegistry::getInstance()->fromStateId($state))){throw new \UnexpectedValueException('Schematic enthält gesperrte Geräte.');}return $state;
    }
    public static function expand(string $encoded,int $volume):string{
        $rle=base64_decode($encoded,true);if($rle===false||strlen($rle)%8!==0||strlen($rle)>$volume*8){throw new \UnexpectedValueException('Ungültiges RLE.');}
        $out='';$count=0;
        for($i=0;$i<strlen($rle);$i+=8){$pair=unpack('Vindex/Vcount',$rle,$i);if($pair['count']<1||$count+$pair['count']>$volume){throw new \UnexpectedValueException('RLE-Längenfehler.');}$out.=str_repeat(pack('V',$pair['index']),$pair['count']);$count+=$pair['count'];}
        if($count!==$volume){throw new \UnexpectedValueException('Unvollständiges RLE.');}return $out;
    }
}
