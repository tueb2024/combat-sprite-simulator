<?php

namespace JumPHP\Loop;

/**
 * Fixed-rate updates from a variable frame time: game logic at a steady 60 Hz (deterministic physics,
 * same speed at 30, 60 or 144 fps), whatever the display does.
 *
 *   $this->step ??= new FixedStep(60);
 *   $this->step->run($dt, fn (float $h) => $this->tick($h));   // $h = 1/60
 *
 * alpha() is how far the next step is (0..1), to interpolate drawing between two steps if wanted.
 */
final class FixedStep
{
    private float $accumulator = 0.0;
    private float $step;

    public function __construct(float $hz = 60.0, private int $maxSteps = 5)
    {
        $this->step = 1.0 / $hz;
    }

    /** @return int the number of steps run */
    public function run(float $dt, callable $tick): int
    {
        $this->accumulator += $dt;
        $steps = 0;
        while ($this->accumulator >= $this->step && $steps < $this->maxSteps) {
            $tick($this->step);
            $this->accumulator -= $this->step;
            $steps++;
        }
        if ($steps === $this->maxSteps) {
            $this->accumulator = 0.0; // too slow: drop the backlog instead of spiralling
        }
        return $steps;
    }

    public function alpha(): float
    {
        return $this->accumulator / $this->step;
    }

    public function stepSeconds(): float
    {
        return $this->step;
    }

    public function reset(): void
    {
        $this->accumulator = 0.0;
    }
}
