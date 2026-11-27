<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

final class PlotStyles{
    public const STYLES=['border'=>['air'=>['Kein Rand (Luft)',0],'stone_slab'=>['Stein-Stufe',0],'smooth_stone_slab'=>['Glattstein-Stufe',0],'oak_slab'=>['Eichen-Stufe',1],'spruce_slab'=>['Fichten-Stufe',1],'cobblestone_slab'=>['Bruchstein-Stufe',1],'sandstone_slab'=>['Sandstein-Stufe',1],'birch_slab'=>['Birken-Stufe',2],'brick_slab'=>['Ziegel-Stufe',2],'stone_brick_slab'=>['Steinziegel-Stufe',2],'red_sandstone_slab'=>['Rote Sandstein-Stufe',2],'quartz_slab'=>['Quarz-Stufe',3],'dark_oak_slab'=>['Schwarzeichen-Stufe',3],'mossy_stone_brick_slab'=>['Moossteinziegel-Stufe',3],'nether_brick_slab'=>['Netherziegel-Stufe',3],'prismarine_slab'=>['Prismarin-Stufe',4],'dark_prismarine_slab'=>['Dunkle Prismarin-Stufe',4],'purpur_slab'=>['Purpur-Stufe',4],'end_stone_brick_slab'=>['Endsteinziegel-Stufe',4],'cherry_slab'=>['Kirschholz-Stufe',5],'warped_slab'=>['Wirrholz-Stufe',5],'crimson_slab'=>['Karmesin-Stufe',5],'polished_blackstone_brick_slab'=>['Polierte Schwarzsteinziegel-Stufe',5],'deepslate_tile_slab'=>['Tiefenschieferfliesen-Stufe',5]],'wall'=>['dirt'=>['Erde',0],'stone'=>['Stein',1],'cobblestone'=>['Bruchstein',1],'oak_planks'=>['Eichenholz',1],'stone_bricks'=>['Steinziegel',2],'bricks'=>['Ziegel',2],'sandstone'=>['Sandstein',2],'quartz'=>['Quarz',3],'mossy_stone_bricks'=>['Moossteinziegel',3],'nether_bricks'=>['Netherziegel',3],'prismarine'=>['Prismarin',4],'dark_prismarine'=>['Dunkler Prismarin',4],'purpur'=>['Purpur',4],'obsidian'=>['Obsidian',5],'amethyst_block'=>['Amethyst',5],'deepslate_tiles'=>['Tiefenschieferfliesen',5]]];
    public const RANKS=[1=>'VIP',2=>'Premium',3=>'Legende',4=>'Hero',5=>'Supreme'];

    public static function permission(string $kind,string $block):string{return 'splash.plot.'.$kind.'.'.$block;}
    public static function rankName(int $tier):string{return $tier===0?'Alle':(self::RANKS[$tier]??'Admin');}
}
