<?php
declare(strict_types=1);

namespace BetterPlots\Domain;

final readonly class PlotId{
    public function __construct(public int $x, public int $z){}

    public static function parse(string $value): self{
        if(preg_match('/^(-?(?:0|[1-9][0-9]*));(-?(?:0|[1-9][0-9]*))$/D', $value, $m) !== 1){
            throw new \InvalidArgumentException('Plot-ID muss X;Z sein.');
        }
        $x = filter_var($m[1], FILTER_VALIDATE_INT);
        $z = filter_var($m[2], FILTER_VALIDATE_INT);
        if($x === false || $z === false){
            throw new \InvalidArgumentException('Plot-ID außerhalb des Integerbereichs.');
        }
        return new self($x, $z);
    }

    public function key(): string{ return $this->x . ';' . $this->z; }
}
