<?php

namespace JumPHP\Input;

/**
 * Mouse in view pixels (InputManager converts from window pixels through the letterbox).
 * Buttons: 1 = left, 2 = middle, 3 = right.
 */
class Mouse
{
    public float $x = 0;
    public float $y = 0;
    /** @var array<int, bool> */
    private array $held = [];
    /** @var array<int, bool> */
    private array $pressed = [];
    /** @var array<int, bool> */
    private array $released = [];
    /** the wheel this frame, in notches (fractions with touchpads): y > 0 = away from the user (up), x > 0 = right */
    public float $wheelX = 0;
    public float $wheelY = 0;

    public function move(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function down(int $button): void
    {
        $this->held[$button] = true;
        $this->pressed[$button] = true;
    }

    public function up(int $button): void
    {
        unset($this->held[$button]);
        $this->released[$button] = true;
    }

    public function wheel(float $x, float $y): void
    {
        $this->wheelX += $x;
        $this->wheelY += $y;
    }

    public function isHeld(int $button = 1): bool
    {
        return isset($this->held[$button]);
    }

    public function isPressed(int $button = 1): bool
    {
        return isset($this->pressed[$button]);
    }

    public function isReleased(int $button = 1): bool
    {
        return isset($this->released[$button]);
    }

    public function resetTransientStates(): void
    {
        $this->pressed = [];
        $this->released = [];
        $this->wheelX = 0;
        $this->wheelY = 0;
    }
}
