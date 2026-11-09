<?php

namespace JumPHP\Net;

/**
 * Smooth remote objects: keep the received states with their time, draw them a little in the past
 * ($delay, 120 ms by default) by interpolating between the two states around that time. Movement stays
 * smooth even when packets arrive irregularly.
 *
 *   $this->remote->push($now, ['x' => $x, 'y' => $y]);   // when a state arrives
 *   $state = $this->remote->sample($now);                 // each frame: ['x' => ..., 'y' => ...] or null
 *
 * Numbers are interpolated, other values are taken from the older state.
 */
final class SnapshotBuffer
{
    /** @var array<int, array{0: float, 1: array}> */
    private array $states = [];

    public function __construct(public float $delay = 0.12, private int $capacity = 64)
    {
    }

    public function push(float $time, array $state): void
    {
        $this->states[] = [$time, $state];
        if (count($this->states) > $this->capacity) {
            array_shift($this->states);
        }
    }

    public function sample(float $now): ?array
    {
        if ($this->states === []) {
            return null;
        }
        $t = $now - $this->delay;
        $count = count($this->states);
        if ($t <= $this->states[0][0]) {
            return $this->states[0][1];
        }
        for ($i = $count - 1; $i > 0; $i--) {
            [$t0, $a] = $this->states[$i - 1];
            [$t1, $b] = $this->states[$i];
            if ($t >= $t0 && $t <= $t1) {
                $k = $t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 1.0;
                $out = $a;
                foreach ($b as $key => $value) {
                    if ((is_int($value) || is_float($value)) && isset($a[$key]) && (is_int($a[$key]) || is_float($a[$key]))) {
                        $out[$key] = $a[$key] + ($value - $a[$key]) * $k;
                    }
                }
                return $out;
            }
        }
        return $this->states[$count - 1][1]; // newer than everything: the latest
    }

    public function latest(): ?array
    {
        return $this->states === [] ? null : $this->states[array_key_last($this->states)][1];
    }

    public function clear(): void
    {
        $this->states = [];
    }
}
