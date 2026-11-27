<?php
declare(strict_types=1);
namespace BetterPlots\UI;
final class FlagMenu{
    private const NAMES=['break'=>'Blöcke abbauen','place'=>'Blöcke platzieren','use'=>'Blöcke benutzen','animal-attack'=>'Tiere angreifen','animal-interact'=>'Mit Tieren interagieren','beacon-effects'=>'Leuchtfeuer-Effekte','block-burn'=>'Blöcke verbrennen','block-ignition'=>'Blöcke anzünden','chat'=>'Plot-Chat','concrete-harden'=>'Beton aushärten','coral-dry'=>'Korallen austrocknen','crop-grow'=>'Pflanzenwachstum','copper-oxide'=>'Kupfer oxidieren','deny-exit'=>'Verlassen verhindern','deny-portal-travel'=>'Portalreisen verhindern','deny-portals'=>'Portale verhindern','device-interact'=>'Geräte bedienen','disable-physics'=>'Blockphysik deaktivieren','drop-protection'=>'Gedroppte Items schützen','edit-sign'=>'Schilder bearbeiten','entity-change-block'=>'Blockänderungen durch Entities','explosion'=>'Explosionen','fishing'=>'Angeln','fly'=>'Fliegen','forcefield'=>'Kraftfeld','grass-grow'=>'Graswachstum','hanging-break'=>'Hängende Objekte abbauen','hanging-place'=>'Hängende Objekte platzieren','hide-info'=>'Plotinformationen verbergen','hostile-attack'=>'Feindliche Mobs angreifen','hostile-interact'=>'Mit feindlichen Mobs interagieren','ice-form'=>'Eisbildung','ice-melt'=>'Eis schmelzen','instabreak'=>'Sofort abbauen','invincible'=>'Unverwundbarkeit','item-drop'=>'Items fallen lassen','keep-inventory'=>'Inventar behalten','kelp-grow'=>'Seetangwachstum','leaf-decay'=>'Laubzerfall','lectern-read-book'=>'Bücher am Lesepult lesen','misc-break'=>'Sonstige Objekte abbauen','misc-place'=>'Sonstige Objekte platzieren','misc-interact'=>'Mit sonstigen Objekten interagieren','mob-break'=>'Blockabbau durch Mobs','mob-place'=>'Blockplatzierung durch Mobs','mycel-grow'=>'Myzelwachstum','no-worldedit'=>'WorldEdit verhindern','notify-enter'=>'Betreten melden','notify-leave'=>'Verlassen melden','player-interact'=>'Mit Spielern interagieren','pve'=>'Kampf gegen Mobs','pvp'=>'Spieler gegen Spieler','prevent-creative-copy'=>'Kreativ-Kopieren verhindern','projectiles'=>'Projektile','projectile-change-block'=>'Blockänderungen durch Projektile','redstone'=>'Redstone','server-plot'=>'Server-Grundstück','snow-form'=>'Schneebildung','snow-melt'=>'Schnee schmelzen','soil-dry'=>'Ackerboden austrocknen','tamed-attack'=>'Gezähmte Tiere angreifen','tamed-interact'=>'Mit gezähmten Tieren interagieren','tile-drop'=>'Block-Items droppen','untrusted-visit'=>'Besuche ohne Vertrauen','vehicle-break'=>'Fahrzeuge zerstören','vehicle-place'=>'Fahrzeuge platzieren','vehicle-use'=>'Fahrzeuge benutzen','villager-interact'=>'Mit Dorfbewohnern interagieren','vine-grow'=>'Rankenwachstum','weaving-death-place'=>'Spinnweben beim Tod platzieren','price'=>'Verkaufspreis','animal-cap'=>'Tierlimit','entity-cap'=>'Entitylimit','hostile-cap'=>'Limit feindlicher Mobs','misc-cap'=>'Limit sonstiger Entities','mob-cap'=>'Moblimit','vehicle-cap'=>'Fahrzeuglimit','time'=>'Tageszeit','deny-teleport'=>'Teleportieren verhindern','liquid-flow'=>'Flüssigkeitsfluss','titles'=>'Titelanzeigen','weather'=>'Wetter','blocked-cmds'=>'Gesperrte Befehle','gamemode'=>'Spielmodus','guest-gamemode'=>'Spielmodus für Gäste','music'=>'Musik','description'=>'Beschreibung','farewell'=>'Abschied','greeting'=>'Begrüßung','plot-title'=>'Plottitel','feed'=>'Automatisches Füttern','heal'=>'Automatisches Heilen','analysis'=>'Analyse','done'=>'Fertiggestellt','border'=>'Plotgrenze','animal-spawn'=>'Tiere spawnen','mob-spawn'=>'Mobs spawnen','mob-spawner'=>'Mobspawner auf dem Plot','mob-combat'=>'Mobs angreifen'];
    public static function name(string $flag):string{return self::NAMES[$flag]??$flag;}
    public static function currentFields(array $fields,mixed $value):array{
        if(!isset($fields[0])||$value===null){return $fields;}
        $raw=is_bool($value)?($value?'true':'false'):(is_array($value)?implode(',',$value):(string)$value);
        if(isset($fields[0]['options'])){$index=array_search($raw,$fields[0]['values']??$fields[0]['options'],true);if($index!==false){$fields[0]['default']=$index;}}
        else{$fields[0]['default']=$raw;}return $fields;
    }
    public static function definition(array $registry=[]):array{
        $entries=[];
        foreach([
            ['pvp','PvP','iron_sword',['Aus','An'],['false','true']],
            ['redstone','Redstone','redstone',['Aus','An'],['false','true']],
            ['mob-spawner','Mobspawner auf dem Plot','spawner',['Aus','An'],['false','true']],
            ['mob-combat','Mobs angreifen','iron_sword',['Alle','Nur Plot-Besitzer'],['all','owner']],
            ['weather','Wetter (Supreme)','water_bucket',['Sonne','Regen','Gewitter'],['clear','rain','thunder']],
            ['time','Tageszeit (Supreme)','clock',['Tag','Nacht'],['6000','18000']],
            ['fly','Fliegen (Hero / Supreme)','feather',['Aus','An'],['false','true']]
        ] as [$flag,$label,$icon,$options,$values]){
            $entries[]=['label'=>$label,'icon'=>$icon,'command'=>['flag','set',$flag],'permission'=>'plots.set.flag.'.$flag,'plot-context'=>true,'fields'=>[['label'=>$label,'options'=>$options,'values'=>$values]]];
        }
        $present=['pvp','redstone','mob-spawner','mob-combat','weather','time','fly'];
        foreach($registry as $flag=>$type){if($type==='Intern'||in_array($flag,$present,true)){continue;}
            $field=['label'=>self::name($flag),'placeholder'=>'Wert eingeben'];
            if($type==='Boolean'){$field=['label'=>self::name($flag),'options'=>['Aus','An'],'values'=>['false','true']];}
            $entries[]=['label'=>self::name($flag),'icon'=>'paper','command'=>['flag','set',$flag],'permission'=>'plots.set.flag.'.$flag,'plot-context'=>true,'fields'=>[$field]];
        }
        return ['title'=>'BetterPlots | Flags','description'=>'Alle verfügbaren Plot-Flags. Weitere Einträge auf den nächsten Seiten.','entries'=>$entries];
    }
}
