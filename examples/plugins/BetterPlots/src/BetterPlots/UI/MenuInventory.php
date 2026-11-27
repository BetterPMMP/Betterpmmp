<?php
declare(strict_types=1);
namespace BetterPlots\UI;
use pocketmine\inventory\SimpleInventory;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\world\Position;
final class MenuInventory extends SimpleInventory implements BlockInventory{
    public function __construct(private Position $holder){parent::__construct(27);}
    public function getHolder():Position{return $this->holder;}
}
