<?php
declare(strict_types=1);
namespace BetterPlots\Generator;
final class LegacyPreset{
    public static function convert(array $p,bool $myplot):array{
        $road=(int)($p['RoadWidth']??$p['roadSize']??7);
        $offset=$myplot?(isset($p['roadSize'])?$road-7:0):$road;
        $out=['size'=>(int)($p['PlotSize']??$p['plotSize']??32),'road'=>$road,'height'=>(int)($p['GroundHeight']??$p['groundSize']??64),'offsetX'=>$offset,'offsetZ'=>$offset,'blocks'=>[]];
        foreach(['WallBlock'=>'border','borderBlock'=>'border','RoadBlock'=>'road','PlotFloorBlock'=>'floor','PlotFillBlock'=>'fill','BottomBlock'=>'bottom','roadBlock'=>'road','plotFloorBlock'=>'floor','plotFillBlock'=>'fill','plotBottomBlock'=>'bottom'] as $old=>$new){if(isset($p[$old])&&(is_string($p[$old])||is_int($p[$old]))&&$p[$old]!==''){$out['blocks'][$new]=(string)$p[$old];}}
        return $out;
    }
}
