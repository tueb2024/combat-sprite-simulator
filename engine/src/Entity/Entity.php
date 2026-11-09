<?php

namespace JumPHP\Entity;

use Evenement\EventEmitter;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Vector;

/**
 * Something that moves in the world: position (x, y = top-left corner), velocity, acceleration,
 * size, facing and a state name. update() integrates the motion and picks the animation
 * "<state><Facing>" (walkRight, jumpLeft...) when the class uses the Sprite trait.
 *
 * Compose behaviours with traits and aliased updates:
 *   class Player extends Entity {
 *       use Sprite, Gravity { Sprite::update as updateSprite; Gravity::update as updateGravity; }
 *       public function update(float $dt): void { $this->updateGravity($dt); parent::update($dt); $this->updateSprite($dt); }
 *   }
 */
class Entity extends EventEmitter
{
    use Vector;

    protected float $vx = 0.0;
    protected float $vy = 0.0;
    protected float $ax = 0.0;
    protected float $ay = 0.0;
    /** Multiplier applied to vx each update (1 = none). */
    protected float $friction = 1.0;

    protected string $facing = 'right';
    protected string $state = 'idle';

    protected int $width = 16;
    protected int $height = 16;

    public bool $active = true;
    public bool $visible = true;
    /** Set by kill(): the EntityManager removes it at the end of the frame. */
    public bool $dead = false;
    /** Updated even far from the camera (EntityManager activity window). */
    public bool $alwaysActive = false;

    public function setVelocity(float $vx, float $vy): void
    {
        $this->vx = $vx;
        $this->vy = $vy;
    }

    /** @return array{0: float, 1: float} */
    public function getVelocity(): array
    {
        return [$this->vx, $this->vy];
    }

    public function setAcceleration(float $ax, float $ay): void
    {
        $this->ax = $ax;
        $this->ay = $ay;
    }

    public function setFriction(float $friction): void
    {
        $this->friction = $friction;
    }

    public function setFacing(string $dir): void
    {
        if ($dir !== 'left' && $dir !== 'right') {
            throw new \InvalidArgumentException("Facing direction '$dir' is invalid.");
        }
        $this->facing = $dir;
    }

    public function getFacing(): string
    {
        return $this->facing;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setSize(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getSpeedX(): float
    {
        return $this->vx;
    }

    public function getSpeedY(): float
    {
        return $this->vy;
    }

    public function setSpeedX(float $vx): void
    {
        $this->vx = $vx;
    }

    public function setSpeedY(float $vy): void
    {
        $this->vy = $vy;
    }

    public function getSpeed(): float
    {
        return sqrt($this->vx ** 2 + $this->vy ** 2);
    }

    /** @return array{x: float, y: float, width: int, height: int} */
    public function getBoundingBox(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    public function centerX(): float
    {
        return $this->x + $this->width / 2;
    }

    public function centerY(): float
    {
        return $this->y + $this->height / 2;
    }

    /** @param array{x: float, y: float, width: float, height: float} $rect */
    public function intersects(array $rect, int $shrink = 0): bool
    {
        return $this->x + $shrink < $rect['x'] + $rect['width'] && $this->x + $this->width - $shrink > $rect['x']
            && $this->y + $shrink < $rect['y'] + $rect['height'] && $this->y + $this->height - $shrink > $rect['y'];
    }

    public function kill(): void
    {
        $this->dead = true;
        $this->emit('killed', [$this]);
    }

    /** Integrates velocity (no collision: CollisionSystem::move() does motion against tiles). */
    public function update(float $deltaTime): void
    {
        $this->vx += $this->ax * $deltaTime;
        $this->vy += $this->ay * $deltaTime;
        $this->vx *= $this->friction;
        $this->x += $this->vx * $deltaTime;
        $this->y += $this->vy * $deltaTime;
        $this->pickAnimation();
    }

    /** "<state><Facing>" if it exists, else "<state>" (Sprite trait). */
    protected function pickAnimation(): void
    {
        if (!method_exists($this, 'hasAnimation')) {
            return;
        }
        $name = $this->state . ucfirst($this->facing);
        if (!$this->hasAnimation($name)) {
            $name = $this->state;
        }
        if ($this->hasAnimation($name)) {
            $this->setAnimation($name);
        }
    }

    /** Draws the entity; the camera offset is subtracted. Default: a magenta box (placeholder). */
    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        if (!$this->visible) {
            return;
        }
        if (method_exists($this, 'drawAt')) {
            $this->drawAt($r, $this->x - $camX, $this->y - $camY);
            return;
        }
        $r->fillRect($this->x - $camX, $this->y - $camY, $this->width, $this->height, [255, 0, 255]);
    }
}
