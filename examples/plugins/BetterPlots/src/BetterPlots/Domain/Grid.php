<?php
declare(strict_types=1);

namespace BetterPlots\Domain;

final readonly class Grid{
    public int $period;

    public function __construct(public int $size = 32, public int $road = 7, public int $offsetX = 0, public int $offsetZ = 0){
        if($size < 2 || $size > 4096 || $road < 1 || $road > 256){
            throw new \InvalidArgumentException('Plotgröße 2..4096, Straßenbreite 1..256 erforderlich.');
        }
        foreach([$offsetX, $offsetZ] as $offset){
            if($offset < -2147483648 || $offset > 2147483647){
                throw new \InvalidArgumentException('Offset außerhalb der Weltgrenzen.');
            }
        }
        $this->period = $size + $road;
    }

    public static function floorDiv(int $value, int $divisor): int{
        if($divisor <= 0){ throw new \InvalidArgumentException('Positiver Divisor erforderlich.'); }
        $q = intdiv($value, $divisor);
        return $value % $divisor < 0 ? $q - 1 : $q;
    }

    public function cell(int $x, int $z): array{
        if($x < -2147483648 || $x > 2147483647 || $z < -2147483648 || $z > 2147483647){
            throw new \InvalidArgumentException('Koordinate außerhalb der Weltgrenzen.');
        }
        $rx = $x - $this->offsetX;
        $rz = $z - $this->offsetZ;
        $cx = self::floorDiv($rx, $this->period);
        $cz = self::floorDiv($rz, $this->period);
        return [$cx, $cz, $rx - $cx * $this->period, $rz - $cz * $this->period];
    }

    public function locate(int $x, int $z): ?PlotId{
        [$cx, $cz, $lx, $lz] = $this->cell($x, $z);
        return $lx < $this->size && $lz < $this->size ? new PlotId($cx, $cz) : null;
    }

    public function bounds(PlotId $id): array{

        $minPlotX = -self::floorDiv(-(-2147483648 - $this->offsetX), $this->period);
        $minPlotZ = -self::floorDiv(-(-2147483648 - $this->offsetZ), $this->period);
        $maxPlotX = self::floorDiv(2147483647 - $this->offsetX - $this->size + 1, $this->period);
        $maxPlotZ = self::floorDiv(2147483647 - $this->offsetZ - $this->size + 1, $this->period);
        if($id->x < $minPlotX || $id->x > $maxPlotX || $id->z < $minPlotZ || $id->z > $maxPlotZ){
            throw new \InvalidArgumentException('Plot reicht über die Weltgrenze.');
        }
        $x = $this->offsetX + $id->x * $this->period;
        $z = $this->offsetZ + $id->z * $this->period;
        return ['minX' => $x, 'minZ' => $z, 'maxX' => $x + $this->size - 1, 'maxZ' => $z + $this->size - 1];
    }

    public function spiral(int $limit): \Generator{
        if($limit < 1 || $limit > 10000000){ throw new \InvalidArgumentException('Ungültiges Suchbudget.'); }
        yield new PlotId(0, 0);
        $count = 1;
        $x = $z = 0;
        $length = 1;
        while($count < $limit){
            foreach([[1, 0], [0, 1], [-1, 0], [0, -1]] as $direction => [$dx, $dz]){
                for($step = 0; $step < $length && $count < $limit; ++$step){
                    $x += $dx; $z += $dz;
                    ++$count;
                    yield new PlotId($x, $z);
                }
                if($direction % 2 === 1){ ++$length; }
            }
        }
    }
}
