<?php

namespace JumPHP\Debug;

/** Frames per second over the last second, and the time of the slowest frame. */
final class FpsCounter
{
    private int $frames = 0;
    private float $elapsed = 0.0;
    private float $worst = 0.0;
    public int $fps = 0;
    public float $worstMs = 0.0;

    public function tick(float $dt): void
    {
        $this->frames++;
        $this->elapsed += $dt;
        $this->worst = max($this->worst, $dt);
        if ($this->elapsed >= 1.0) {
            $this->fps = (int)round($this->frames / $this->elapsed);
            $this->worstMs = round($this->worst * 1000, 1);
            $this->frames = 0;
            $this->elapsed = 0.0;
            $this->worst = 0.0;
        }
    }
}
