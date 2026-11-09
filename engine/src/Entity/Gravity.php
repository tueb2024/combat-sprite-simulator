<?php

namespace JumPHP\Entity;

/**
 * Falling: adds gravity to vy, up to a terminal speed. Units: pixels / s and pixels / s².
 * Use with an aliased update: use Gravity { Gravity::update as updateGravity; }
 */
trait Gravity
{
    protected float $gravity = 900.0;
    protected float $maxFallSpeed = 600.0;
    protected bool $gravityEnabled = true;

    public function update(float $deltaTime): void
    {
        if (!$this->gravityEnabled) {
            return;
        }
        $this->vy = min($this->vy + $this->gravity * $deltaTime, $this->maxFallSpeed);
    }

    public function setGravity(float $gravity, ?float $maxFallSpeed = null): void
    {
        $this->gravity = $gravity;
        $this->maxFallSpeed = $maxFallSpeed ?? $this->maxFallSpeed;
    }

    public function enableGravity(bool $on = true): void
    {
        $this->gravityEnabled = $on;
    }
}
