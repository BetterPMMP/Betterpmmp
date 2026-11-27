<?php
declare(strict_types=1);

namespace BetterPlots\Generator;

final readonly class WeightedBucket{

    private array $ranges;
    private int $total;

    public function __construct(array $weights){
        if($weights === []){ throw new \InvalidArgumentException('Leerer Block-Bucket.'); }
        $ranges = [];
        $total = 0;
        foreach($weights as $state => $weight){
            if($state < 0 || $weight < 1 || $weight > 1000000){
                throw new \InvalidArgumentException('Ungültiger Zustand oder Gewicht.');
            }
            $total += $weight;
            if($total > 1000000000){ throw new \InvalidArgumentException('Bucketgewicht zu groß.'); }
            $ranges[] = [$total, $state];
        }
        $this->ranges = $ranges;
        $this->total = $total;
    }

    public function state(int $seed, int $x, int $y, int $z): int{
        $sample = crc32($seed . ':' . $x . ':' . $y . ':' . $z) % $this->total;
        foreach($this->ranges as [$end, $state]){
            if($sample < $end){ return $state; }
        }
        throw new \LogicException('Bucket-Invariante verletzt.');
    }
}
