<?php
declare(strict_types=1);
namespace BetterPlots\World;
use BetterPlots\Main;
use pocketmine\world\World;
use pocketmine\world\format\io\WritableWorldProvider;

final class ExistingWorld{
    public static function inventory(string $root,string $name):array{
        if($name===''||$name==='.'||$name==='..'||strpbrk($name,"/\\\0")!==false){throw new \InvalidArgumentException('Ungueltiger Weltordnername.');}
        $base=realpath($root);$path=realpath(rtrim($root,'/\\').DIRECTORY_SEPARATOR.$name);
        if($base===false||$path===false||!is_dir($path)||dirname($path)!==$base){throw new \RuntimeException(\BetterPlots\Text::defer('Vorhandener Weltordner nicht gefunden: {v1}',['v1'=>$name]));}
        $regions=[];foreach(['mca','mcr','mcapm'] as $ext){foreach(glob($path.'/region/*.'.$ext)?:[] as $f){if(filesize($f)>0){$regions[]=basename($f);}}}
        return ['path'=>$path,'regions'=>$regions,'leveldb'=>is_file($path.'/db/CURRENT')];
    }
    public static function assertUnambiguous(array $files):void{
        if($files['regions']!==[]&&$files['leveldb']){throw new \RuntimeException(\BetterPlots\Text::defer('In {v1} liegen Regionsdateien UND eine LevelDB-Datenbank. Kein automatisches Ueberschreiben. /p importcheck fuer den Dateibericht verwenden.',['v1'=>$files['path']]));}
    }
    public static function load(Main $p,string $name):World{
        $wm=$p->getServer()->getWorldManager();$files=self::inventory($p->getServer()->getDataPath().'worlds',$name);self::assertUnambiguous($files);
        $loaded=$wm->getWorldByName($name);
        if($loaded!==null){self::verifyPath($files['path'],$loaded->getProvider()->getPath());return $loaded;}
        $entries=$wm->getProviderManager()->getMatchingProviders($files['path'].DIRECTORY_SEPARATOR);
        if(count($entries)!==1){throw new \RuntimeException(\BetterPlots\Text::defer('Weltformat nicht eindeutig erkannt: {v1} ({v2}).',['v1'=>$name,'v2'=>implode(', ',array_keys($entries))]));}
        $entry=reset($entries);$provider=$entry->fromPath($files['path'].DIRECTORY_SEPARATOR,$p->getLogger());$count=null;
        try{
            if(!$provider instanceof WritableWorldProvider){

                $count=0;foreach($provider->getAllChunks(false) as $coordinates=>$chunk){++$count;}
                if($files['regions']!==[]&&$count===0){throw new \RuntimeException(\BetterPlots\Text::defer('Regionsdateien vorhanden, aber keine lesbaren Chunks: {v1}',['v1'=>$name]));}
                $p->getLogger()->notice('Uebernehme '.$count.' gespeicherte Chunks aus '.$files['path'].'. BetterPMMP sichert das Original unter backups/worlds vor dem Formatwechsel.');
            }
        }finally{$provider->close();}
        if(!$wm->loadWorld($name,true)){throw new \RuntimeException(\BetterPlots\Text::defer('Bestehende Welt konnte nicht uebernommen werden: {v1}',['v1'=>$name]));}
        $world=$wm->getWorldByName($name)??throw new \RuntimeException('Geladene Welt fehlt.');self::verifyPath($files['path'],$world->getProvider()->getPath());
        if($count!==null&&$world->getProvider()->calculateChunkCount()!==$count){throw new \RuntimeException('Chunk-Anzahl nach Formatwechsel stimmt nicht. Original in backups/worlds erhalten; Import angehalten.');}
        return $world;
    }
    public static function verifyPath(string $expected,string $actual):void{
        if(realpath($expected)!==realpath($actual)){throw new \RuntimeException('Geladener Weltprovider verwendet einen anderen Ordner als die Altwelt.');}
    }
    public static function report(Main $p,string $name):string{
        $f=self::inventory($p->getServer()->getDataPath().'worlds',$name);$wm=$p->getServer()->getWorldManager();$world=$wm->getWorldByName($name);
        $lines=[\BetterPlots\Text::defer('Weltordner: {path} | Regionsdateien: {count} | LevelDB: {leveldb}',['path'=>$f['path'],'count'=>count($f['regions']),'leveldb'=>\BetterPlots\Text::ref($f['leveldb']?'ja':'nein')])];
        $lines[]=\BetterPlots\Text::defer('Erkannte Formate: {formats}',['formats'=>implode(', ',array_keys($wm->getProviderManager()->getMatchingProviders($f['path'].DIRECTORY_SEPARATOR)))]);
        if($world!==null){$provider=$world->getProvider();$spawn=$world->getSpawnLocation();$lines[]=\BetterPlots\Text::defer('Geladen: {provider} | {path} | Gespeicherte Chunks: {chunks} | Spawn: {x},{y},{z}',['provider'=>get_class($provider),'path'=>$provider->getPath(),'chunks'=>$provider->calculateChunkCount(),'x'=>$spawn->getFloorX(),'y'=>$spawn->getFloorY(),'z'=>$spawn->getFloorZ()]);}
        $plots=array_filter($p->repo->all('bp/plot/'),static fn($row)=>$row['world']===$name);$lines[]=\BetterPlots\Text::defer('Uebernommene Plotgruppen: {count}',['count'=>count($plots)]);
        foreach(array_slice($plots,0,3) as $plot){$lines[]=\BetterPlots\Text::defer('{owner}: {plots}',['owner'=>$plot['ownerName'],'plots'=>implode(', ',array_slice($plot['cells'],0,4))]);}
        return \BetterPlots\Text::join("\n",$lines);
    }
}
