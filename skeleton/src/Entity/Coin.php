<?php

namespace App\Entity;

use JumPHP\Entity\GameObject;
use JumPHP\Renderer\Renderer;

/**
 * A coin placed in the map ('C' in the level rows). Spawned by EntityManager::spawnFromMap().
 */
final class Coin extends GameObject
{
    private float $time = 0.0;

    public function __construct(float $x, float $y, int $width = 16, int $height = 16, string $type = 'coin', string $name = '', array $properties = [])
    {
        // a small coin (8 x 8) centred in its map tile
        parent::__construct($x + ($width - 8) / 2, $y + ($height - 8) / 2, 8, 8, $type, $name, $properties);
        $this->time = $x * 0.01;
    }

    public function update(float $deltaTime): void
    {
        $this->time += $deltaTime;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $bob = sin($this->time * 4) * 1.5;
        $r->fillCircle($this->x + 4 - $camX, $this->y + 4 + $bob - $camY, 4, [255, 200, 40]);
        $r->fillRect($this->x + 3 - $camX, $this->y + 2 + $bob - $camY, 2, 4, [255, 240, 150]);
    }
}
