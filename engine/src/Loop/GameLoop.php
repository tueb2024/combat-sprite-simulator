<?php

namespace JumPHP\Loop;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * The ReactPHP event loop, shared by the engine and the game (timers, deferred calls).
 *
 * Desktop: Application::run() calls Loop::run(), which blocks until the game quits.
 * Browser: nothing may block; the page calls jumphp_frame() at each requestAnimationFrame, which runs
 * start(1): exactly one turn of the loop (due timers, futureTicks), then gives the hand back.
 */
class GameLoop
{
    /** @var array<string, bool> */
    private array $eachTicks = [];
    private int $ticksLeft = 0;
    private ?\Closure $countTick = null;

    public function __construct(private ?LoopInterface $loop = null)
    {
        $this->loop ??= Loop::get();
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    public static function nextTick(\Closure $closure): void
    {
        Loop::futureTick($closure);
    }

    public static function addTimer(float|int $seconds, \Closure $closure): \React\EventLoop\TimerInterface
    {
        return Loop::addTimer($seconds, $closure);
    }

    public static function addPeriodicTimer(float|int $seconds, \Closure $closure): \React\EventLoop\TimerInterface
    {
        return Loop::addPeriodicTimer($seconds, $closure);
    }

    public static function cancelTimer(\React\EventLoop\TimerInterface $timer): void
    {
        Loop::cancelTimer($timer);
    }

    /** Runs the loop; with $maxTicks > 0 it stops after that many turns (browser: start(1) per frame). */
    public function start(int $maxTicks = -1): void
    {
        if ($maxTicks > 0) {
            // one closure made once: a closure re-planning itself through a reference is a cycle per
            // frame, left to the garbage collector (memory growing, then a long pause in the browser)
            $this->ticksLeft = $maxTicks;
            $this->countTick ??= function (): void {
                if (--$this->ticksLeft > 0) {
                    $this->loop->futureTick($this->countTick);
                } else {
                    $this->loop->stop();
                }
            };
            $this->loop->futureTick($this->countTick);
        }

        $this->loop->run();
    }

    public function stop(): void
    {
        $this->loop->stop();
    }

    /** Calls $closure at every turn of the loop until deleteEachTick(); returns its id. */
    public function eachTick(\Closure $closure): string
    {
        $id = uniqid('eachTick_', true);
        $this->eachTicks[$id] = true;

        $tick = null;
        $tick = function () use (&$tick, $closure, $id): void {
            if (!isset($this->eachTicks[$id])) {
                return;
            }
            if ($this->eachTicks[$id]) {
                $closure();
            }
            $this->loop->futureTick($tick);
        };
        $this->loop->futureTick($tick);

        return $id;
    }

    public function pauseEachTick(string $id): void
    {
        if (isset($this->eachTicks[$id])) {
            $this->eachTicks[$id] = false;
        }
    }

    public function resumeEachTick(string $id): void
    {
        if (isset($this->eachTicks[$id])) {
            $this->eachTicks[$id] = true;
        }
    }

    public function deleteEachTick(string $id): void
    {
        unset($this->eachTicks[$id]);
    }
}
