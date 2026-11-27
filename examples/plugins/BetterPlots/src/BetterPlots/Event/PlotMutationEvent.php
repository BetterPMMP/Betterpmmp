<?php
declare(strict_types=1);
namespace BetterPlots\Event;

use pocketmine\event\{Event,Cancellable,CancellableTrait};

class PlotMutationEvent extends Event implements Cancellable{
    use CancellableTrait;

    public function __construct(public readonly string $actor,public readonly string $plotKey,public readonly array $before,public readonly array $after){}
}
