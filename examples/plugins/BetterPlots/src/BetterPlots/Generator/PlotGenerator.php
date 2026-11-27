<?php
declare(strict_types=1);

namespace BetterPlots\Generator;

use BetterPlots\Domain\Grid;
use pocketmine\block\BlockTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

class PlotGenerator extends Generator{
    protected bool $legacyBorder=false;
    private Grid $grid;
    private int $height;

    private array $buckets = [];

    public function __construct(int $seed, string $preset){
        parent::__construct($seed, $preset);
        try{
            $options = $preset === '' ? [] : json_decode($preset, true, 32, JSON_THROW_ON_ERROR);
            if(!is_array($options)){ throw new \InvalidArgumentException('Generatoroptionen müssen ein Objekt sein.'); }
            foreach(array_keys($options) as $key){
                if(!in_array($key, ['size', 'road', 'offsetX', 'offsetZ', 'height', 'blocks'], true)){
                    throw new \InvalidArgumentException(\BetterPlots\Text::defer('Unbekannte Generatoroption: {v1}',['v1'=>$key]));
                }
            }
            foreach(['size', 'road', 'offsetX', 'offsetZ', 'height'] as $key){
                if(isset($options[$key]) && !is_int($options[$key])){ throw new \InvalidArgumentException(\BetterPlots\Text::defer('{v1} muss ganzzahlig sein.',['v1'=>$key])); }
            }
            $this->grid = new Grid($options['size'] ?? 32, $options['road'] ?? 7, $options['offsetX'] ?? 0, $options['offsetZ'] ?? 0);
            $this->height = $options['height'] ?? 64;
            if($this->height < -63 || $this->height > 317){ throw new \InvalidArgumentException('Bodenhöhe muss -63..317 sein.'); }
            $blocks = $options['blocks'] ?? [];
            if(!is_array($blocks)){ throw new \InvalidArgumentException('blocks muss ein Objekt sein.'); }
            $defaults = ['bottom' => 'bedrock', 'fill' => 'dirt', 'floor' => 'grass', 'wall' => 'stone_bricks', 'border' => 'stone_slab', 'road' => 'oak_planks'];
            foreach(array_keys($blocks) as $key){
                if(!array_key_exists($key, $defaults)){ throw new \InvalidArgumentException('Unbekannter Block-Bucket.'); }
            }
            foreach($defaults as $role => $default){
                $spec = $blocks[$role] ?? [$default => 1];
                if(is_string($spec)){ $spec = [$spec => 1]; }
                if(!is_array($spec)){ throw new \InvalidArgumentException('Ungültiger Block-Bucket.'); }
                $weights = [];
                foreach($spec as $name => $weight){
                    if((!is_string($name) && !is_int($name)) || !is_int($weight) || $weight < 1 || $weight > 1000000){ throw new \InvalidArgumentException('Blockname und positives Ganzzahlgewicht bis 1000000 erforderlich.'); }
                    $block = LegacyBlock::parse((string)$name);
                    if(($block->getTypeId() === BlockTypeIds::AIR && $role !== 'border') || $block->getIdInfo()->getTileClass() !== null){
                        throw new \InvalidArgumentException('Terrain-Buckets erfordern Nicht-Luft-Bloecke ohne Block-Entity; nur border darf Luft sein.');
                    }
                    $id = $block->getStateId();
                    $weights[$id] = ($weights[$id] ?? 0) + $weight;
                }
                $this->buckets[$role] = new WeightedBucket($weights);
            }
        }catch(\Throwable $e){
            throw new InvalidGeneratorOptionsException($e->getMessage(), 0, $e);
        }
    }

    public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ): void{
        if($this->height < $world->getMinY() + 1 || $this->height + 1 >= $world->getMaxY()){
            throw new InvalidGeneratorOptionsException('Bodenhöhe außerhalb der Zielwelt.');
        }
        $chunk = new Chunk([], false);
        for($lx = 0; $lx < 16; ++$lx){
            for($lz = 0; $lz < 16; ++$lz){
                $x = $chunkX * 16 + $lx; $z = $chunkZ * 16 + $lz;
                [, , $gx, $gz] = $this->grid->cell($x, $z);
                $plot = $gx < $this->grid->size && $gz < $this->grid->size;
                $edge = $plot && ($gx === 0 || $gz === 0 || $gx === $this->grid->size - 1 || $gz === $this->grid->size - 1);
                if($this->legacyBorder){$edge=!$plot&&(($gx===$this->grid->size||$gx===$this->grid->period-1)&&$gz<$this->grid->size||($gz===$this->grid->size||$gz===$this->grid->period-1)&&$gx<$this->grid->size);}
                for($y = $world->getMinY(); $y <= $this->height; ++$y){
                    $role = $y === $world->getMinY() ? 'bottom' : ($y < $this->height ? ($edge ? 'wall' : 'fill') : ($plot ? 'floor' : 'road'));
                    $chunk->setBlockStateId($lx, $y, $lz, $this->buckets[$role]->state($this->seed, $x, $y, $z));
                }
                if($edge){
                    $chunk->setBlockStateId($lx, $this->height + 1, $lz, $this->buckets['border']->state($this->seed, $x, $this->height + 1, $z));
                }
            }
        }
        $world->setChunk($chunkX, $chunkZ, $chunk);
    }

    public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ): void{
        $chunk = $world->getChunk($chunkX, $chunkZ);
        if($chunk === null){ throw new \LogicException('Chunk vor Population nicht generiert.'); }

        foreach($chunk->getSubChunks() as $sy => $subChunk){
            for($x = 0; $x < 16; ++$x){
                for($z = 0; $z < 16; ++$z){
                    for($y = 0; $y < 16; ++$y){ $subChunk->getBiomeArray()->set($x, $y, $z, 1); }
                }
            }
        }
        $chunk->setPopulated();
    }

    public function terrainState(int $x,int $y,int $z,int $minY,bool $plot,bool $edge):int{
        if($y>$this->height+1||($y===$this->height+1&&!$edge)){return \pocketmine\block\VanillaBlocks::AIR()->getStateId();}
        $role=$y===$minY?'bottom':($y===$this->height+1?'border':($y<$this->height?($edge?'wall':'fill'):($plot?'floor':'road')));
        return $this->buckets[$role]->state($this->seed,$x,$y,$z);
    }
}
