<?php
declare(strict_types=1);
namespace BetterPlots\Generator;
class LegacyCPlotGenerator extends PlotGenerator{
    public function __construct(int $seed,string $preset){$this->legacyBorder=true;parent::__construct($seed,json_encode(LegacyPreset::convert($preset===''?[]:json_decode($preset,true,32,JSON_THROW_ON_ERROR),false),JSON_THROW_ON_ERROR));}
}
