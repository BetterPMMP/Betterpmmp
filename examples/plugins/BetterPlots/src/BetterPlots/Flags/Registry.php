<?php
declare(strict_types=1);
namespace BetterPlots\Flags;

final class Registry{

    private array $types;
    public function __construct(string $json){
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data)){throw new \InvalidArgumentException('Ungültige Flag-Registry.');}
        $this->types=$data+['border'=>'Boolean','animal-spawn'=>'Boolean','mob-spawn'=>'Boolean','mob-spawner'=>'Boolean','mob-combat'=>'Enum'];
    }

    public function all():array{return $this->types;}
    public function parse(string $name,string $raw,bool $internal=false):mixed{
        $type=$this->types[$name]??throw new \InvalidArgumentException(\BetterPlots\Text::defer('Unbekanntes Flag: {v1}',['v1'=>$name]));
        $raw=trim($raw);
        if($type==='Intern'){
            if(!$internal){throw new \InvalidArgumentException('Dieses Flag wird intern verwaltet.');}
            return $raw;
        }
        if($type==='Boolean'){
            return match(strtolower($raw)){'true','on','an'=>true,'false','off','aus'=>false,default=>throw new \InvalidArgumentException('Erlaubt: true/false oder an/aus.')};
        }
        if($type==='Zahl'){
            if($name==='price'){
                if(!preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D',$raw)){throw new \InvalidArgumentException('Preis: positive Zahl mit höchstens zwei Nachkommastellen.');}
                $n=(float)$raw;if(!is_finite($n)||$n>1000000000){throw new \InvalidArgumentException('Preis zu groß.');}return $n;
            }
            $n=filter_var($raw,FILTER_VALIDATE_INT);
            if($n===false||$n<0||$n>2147483647){throw new \InvalidArgumentException('Ganzzahl 0..2147483647 erforderlich.');}return $n;
        }
        if($type==='Enum'||in_array($name,['gamemode','guest-gamemode'],true)){
            $values=match($name){
                'mob-combat'=>['all','owner'],
                'deny-teleport'=>['members','nonmembers','trusted','nontrusted','nonowners'],
                'liquid-flow','titles'=>['true','false','default'],
                'weather'=>['clear','rain','thunder','default'],
                default=>['survival','creative','adventure','spectator','default']};
            $value=strtolower($raw);if(!in_array($value,$values,true)){throw new \InvalidArgumentException(\BetterPlots\Text::defer('Erlaubt: {values}',['values'=>implode(', ',$values)]));}return $value;
        }
        if($type==='Zeitgesteuert'){
            $parts=preg_split('/\s+/',$raw);
            if(count($parts)<1||count($parts)>2){throw new \InvalidArgumentException('Syntax: Intervall [Menge]');}
            $interval=filter_var($parts[0],FILTER_VALIDATE_INT);$amount=filter_var($parts[1]??'0',FILTER_VALIDATE_INT);
            if($interval===false||$amount===false||$interval<1||$interval>86400||$amount<0||$amount>20){throw new \InvalidArgumentException('Intervall 1..86400, Menge 0..20.');}
            return [$interval,$amount];
        }
        if($type==='Blockliste'||$name==='blocked-cmds'){
            $parts=array_values(array_unique(array_filter(array_map('trim',explode(',',$raw)),static fn(string $s):bool=>$s!=='')));
            if(count($parts)>256){throw new \InvalidArgumentException('Liste zu lang.');}
            foreach($parts as $part){if(!preg_match('/^[a-zA-Z0-9_:#*\/.-]{1,100}$/D',$part)){throw new \InvalidArgumentException('Ungültiger Listeneintrag.');}}
            return $parts;
        }
        if(strlen($raw)>2048||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$raw)){throw new \InvalidArgumentException('Text ist zu lang oder enthält Steuerzeichen.');}
        return $raw;
    }
}
