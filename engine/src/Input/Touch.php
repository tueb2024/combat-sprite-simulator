<?php

namespace JumPHP\Input;

/**
 * Touch screen gestures from SDL finger events (coordinates 0..1 of the screen):
 * swipes become the virtual actions 'touch:left', 'touch:right', 'touch:up', 'touch:down',
 * a tap becomes 'touch:tap'. Bind them in config/input.yaml like any key.
 * In the browser, engine/web/controls.js also shows an on-screen pad that sends real key events.
 */
class Touch
{
    private const SWIPE = 0.08;
    private const TAP_TIME = 0.25;

    /** @var array<string, bool> */
    private array $held = [];
    /** @var array<string, bool> */
    private array $pressed = [];
    private ?array $start = null;
    public float $x = 0;
    public float $y = 0;
    public bool $active = false;

    public function handle($event): void
    {
        $f = $event->tfinger;
        $this->x = (float)$f->x;
        $this->y = (float)$f->y;
        $now = microtime(true);
        if ($event->type === \SDL_FINGERDOWN) {
            $this->active = true;
            $this->start = [$this->x, $this->y, $now];
        } elseif ($event->type === \SDL_FINGERMOTION && $this->start !== null) {
            $dx = $this->x - $this->start[0];
            $dy = $this->y - $this->start[1];
            $this->setHeld('touch:left', $dx < -self::SWIPE);
            $this->setHeld('touch:right', $dx > self::SWIPE);
            $this->setHeld('touch:up', $dy < -self::SWIPE);
            $this->setHeld('touch:down', $dy > self::SWIPE);
        } elseif ($event->type === \SDL_FINGERUP) {
            $this->active = false;
            if ($this->start !== null && $this->held === [] && $now - $this->start[2] < self::TAP_TIME) {
                $this->pressed['touch:tap'] = true;
            }
            $this->held = [];
            $this->start = null;
        }
    }

    private function setHeld(string $name, bool $on): void
    {
        if ($on && !isset($this->held[$name])) {
            $this->pressed[$name] = true;
        }
        if ($on) {
            $this->held[$name] = true;
        } else {
            unset($this->held[$name]);
        }
    }

    public function isHeld(string $name): bool
    {
        return isset($this->held[$name]);
    }

    public function isPressed(string $name): bool
    {
        return isset($this->pressed[$name]);
    }

    public function resetTransientStates(): void
    {
        $this->pressed = [];
    }
}
