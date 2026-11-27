<?php
declare(strict_types=1);

namespace BetterPlots\Generator;

use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

final class Registration{
    public static function register(): void{
        $manager = GeneratorManager::getInstance();
        foreach(['myplot'=>LegacyMyPlotGenerator::class,'cplot_plot'=>LegacyCPlotGenerator::class] as $alias=>$class){if($manager->getGenerator($alias)===null){$manager->addGenerator($class,$alias,static function(string $p)use($class):?InvalidGeneratorOptionsException{try{new $class(0,$p);return null;}catch(\Throwable $e){return new InvalidGeneratorOptionsException($e->getMessage(),0,$e);}},fast:false);}}
        $existing = $manager->getGenerator('betterplots');
        if($existing !== null){
            if($existing->getGeneratorClass() !== PlotGenerator::class){
                throw new \RuntimeException('Generatorname betterplots wird bereits verwendet.');
            }
            return;
        }
        $manager->addGenerator(
            PlotGenerator::class,
            'betterplots',
            static function(string $preset): ?InvalidGeneratorOptionsException{
                try{ new PlotGenerator(0, $preset); return null; }
                catch(InvalidGeneratorOptionsException $e){ return $e; }
            },
            fast: false
        );
    }
}
