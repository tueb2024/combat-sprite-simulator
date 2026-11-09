<?php

namespace App\Entity;

use JumPHP\Entity\Entity;
use JumPHP\Entity\Gravity;
use JumPHP\Entity\Sprite;
use JumPHP\Physics\TileCollision;
use JumPHP\Renderer\Sdl;
use JumPHP\Util\Math;

/**
 * The hero: runs, jumps (higher while the button is held), animated from a sprite sheet drawn in code.
 * Traits: Sprite (animation), Gravity (falling), TileCollision (onGround... filled by CollisionSystem).
 */
final class Player extends Entity
{
    use Sprite, Gravity, TileCollision {
        Sprite::update as updateSprite;
        Gravity::update as updateGravity;
    }

    private const RUN_SPEED = 110.0;
    private const ACCEL = 900.0;
    private const JUMP_SPEED = 290.0;

    /** 3 frames of 12 x 14 pixels: idle, walk, jump (Sprite animations point into this sheet). */
    private const SHEET = [
        '...####........####........####.....',
        '..######......######......######....',
        '..##o#o#......##o#o#......##o#o#....',
        '..######......######......######....',
        '...####........####........####.....',
        '....##..........##.........#####....',
        '..######......######......######....',
        '.########....########....#.####.#...',
        '#.######.#..#.######.#.....####.....',
        '..######......######......######....',
        '..##..##.......##.##......##..##....',
        '..##..##.......##..##....##....##...',
        '..##..##......##...##....#......#...',
        '.###..###....###...###..##......##..',
    ];

    protected array $animations = [
        'idleRight' => ['coords' => [['x' => 0, 'y' => 0, 'w' => 12, 'h' => 14]]],
        'walkRight' => ['coords' => [['x' => 12, 'y' => 0, 'w' => 12, 'h' => 14], ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 14]], 'duration' => 0.12],
        'jumpRight' => ['coords' => [['x' => 24, 'y' => 0, 'w' => 12, 'h' => 14]]],
        'idleLeft' => 'reverseIdleRight',
        'walkLeft' => 'reverseWalkRight',
        'jumpLeft' => 'reverseJumpRight',
    ];

    public function __construct(Sdl $sdl, float $x, float $y)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = 10;
        $this->height = 14;
        $this->gravity = 800.0;
        $this->maxFallSpeed = 400.0;
        if (!$sdl->hasTexture('player')) {
            $sdl->createTextureFromPixels('player', self::SHEET, ['#' => '#ffd23f', 'o' => '#202020']);
        }
        $this->initSprite($sdl, 'player', 'idleRight');
    }

    /** $move: -1, 0, 1. $jumpPressed: this frame. $jumpHeld: still held (variable jump height). */
    public function control(int $move, bool $jumpPressed, bool $jumpHeld, float $dt): void
    {
        $this->vx = Math::approach($this->vx, $move * self::RUN_SPEED, self::ACCEL * $dt);
        if ($move !== 0) {
            $this->facing = $move < 0 ? 'left' : 'right';
        }
        if ($jumpPressed && $this->onGround) {
            $this->vy = -self::JUMP_SPEED;
        }
        if (!$jumpHeld && $this->vy < -100) {
            $this->vy = -100; // released early: shorter jump
        }
    }

    public function update(float $deltaTime): void
    {
        $this->updateGravity($deltaTime);
        // no parent::update(): CollisionSystem::move() moves the player against the tiles
        $this->state = !$this->onGround ? 'jump' : (abs($this->vx) > 10 ? 'walk' : 'idle');
        $this->pickAnimation();
        $this->updateSprite($deltaTime);
    }

    public function render(\JumPHP\Renderer\Renderer $r, float $camX = 0, float $camY = 0): void
    {
        // the sprite is 12 px wide, the body 10: centre it
        $this->drawAt($r, $this->x - 1 - $camX, $this->y - $camY);
    }
}
