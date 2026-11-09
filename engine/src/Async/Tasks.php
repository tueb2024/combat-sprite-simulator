<?php

namespace JumPHP\Async;

use JumPHP\Kernel\GameConfig;

/**
 * Background work for games, three backends behind one API (backend()):
 * - 'threads': real OS threads with the "async" runtime (PHP 8.6 ZTS + TrueAsync, game.yaml `runtime: async`);
 * - 'workers': in the browser, a pool of Web Workers each running its own PHP engine (WebWorkers);
 * - 'frames': elsewhere (PHP 8.3 desktop), the work runs on the main thread between frames, a few
 *   milliseconds per frame ($budget), so the game keeps moving.
 *
 *   $tasks = $this->engine->tasks();
 *   $task = $tasks->thread(static fn () => heavy($data));        // one thread for one job
 *   $pool = $tasks->pool(4);                                      // N workers, many jobs
 *   $jobs = array_map(fn ($row) => $pool->submit(static fn (int $y) => render_row($y), $row), range(0, 179));
 *   ... each frame: if ($task->isDone()) ...  (the engine polls them before the scenes update)
 *
 * What runs in a thread is a COPY: a separate PHP world with the game's autoloader (vendor/autoload.php), no
 * engine, no SDL, no scene. Give it plain values (numbers, strings, arrays, simple objects) and use `static fn`
 * (a closure using $this would try to copy the scene). It returns plain values too. No references, no
 * resources (files, textures, sounds).
 */
final class Tasks
{
    /** Fallback (no threads): milliseconds of queued work run per frame (at least one job). */
    public float $budget = 4.0;

    /** @var Task[] tasks waiting for a thread / future */
    private array $pending = [];
    /** @var array<int, array{0: Task, 1: callable, 2: array}> fallback queue */
    private array $queue = [];
    /** @var Pool[] */
    private array $pools = [];
    private ?WebWorkers $web = null;

    public function __construct(private readonly GameConfig $config)
    {
    }

    /** 'threads' (TrueAsync), 'workers' (browser Web Workers) or 'frames' (main thread, between frames). */
    public static function backend(): string
    {
        // under Xdebug (step debugging) TrueAsync's threads crash PHP: the jobs run between frames, where breakpoints work
        if (PHP_ZTS && function_exists('Async\spawn_thread') && !extension_loaded('xdebug') && !extension_loaded('php_debugger')) {
            return 'threads';
        }
        return WebWorkers::available() > 0 ? 'workers' : 'frames';
    }

    /** Does the work run in parallel with the game (threads or Web Workers)? */
    public static function threaded(): bool
    {
        return self::backend() !== 'frames';
    }

    /** How many jobs can run at once (1 without parallelism). */
    public static function workers(): int
    {
        return match (self::backend()) {
            'threads' => max(1, \Async\available_parallelism()),
            'workers' => WebWorkers::available(),
            default => 1,
        };
    }

    /** Runs $task in its own thread (fallback: between frames). */
    public function thread(\Closure $task): Task
    {
        self::checkPortable($task);
        if (self::backend() === 'workers') {
            $result = new Task();
            $this->web()->submit($result, $task, []);
            return $result;
        }
        if (self::backend() === 'frames') {
            return $this->enqueue($task, []);
        }
        $guarded = TaskFailed::guard($task);
        $result = new Task(\Async\spawn_thread(static fn () => $guarded(), true, $this->bootloader()));
        $this->pending[] = $result;
        return $result;
    }

    /** A pool of $workers threads (0: one per core) sharing a job queue. close() it when done. */
    public function pool(int $workers = 0): Pool
    {
        return $this->pools[] = new Pool($this, $workers);
    }

    /** @internal Pool: watch a Future */
    public function watch(Task $task): Task
    {
        $this->pending[] = $task;
        return $task;
    }

    /** @internal Pool: the browser workers */
    public function web(): WebWorkers
    {
        return $this->web ??= new WebWorkers();
    }

    /** @internal Pool fallback */
    public function enqueue(callable $task, array $args): Task
    {
        $result = new Task();
        $this->queue[] = [$result, $task, $args];
        return $result;
    }

    /** Tasks not finished yet. */
    public function pendingCount(): int
    {
        $backlog = array_sum(array_map(fn (Pool $p) => $p->backlogCount(), $this->pools));
        return count($this->pending) + count($this->queue) + $backlog + ($this->web?->pendingCount() ?? 0);
    }

    /**
     * Called by the engine at the start of each frame: settles finished tasks (their then() callbacks run
     * here), and in fallback mode runs queued work for up to $budget ms.
     */
    public function poll(): void
    {
        $this->pools = array_values(array_filter($this->pools, fn (Pool $p) => !$p->isClosed()));
        foreach ($this->pools as $pool) {
            $pool->pump();
        }
        if ($this->web !== null) {
            // workers that could not start: their jobs come back to run here
            foreach ($this->web->poll() as $job) {
                $this->queue[] = $job;
            }
        }
        if ($this->pending !== []) {
            // finished threads are only reported when the main thread passes through the TrueAsync reactor:
            // the desktop loop sleeps between frames, headless runs (no sleep) need this short wait
            if (\JumPHP\Kernel\Platform::isHeadless()) {
                \Async\delay(1);
            }
            foreach ($this->pending as $i => $task) {
                $handle = $task->handle();
                if ($handle !== null && $handle->isCompleted()) {
                    unset($this->pending[$i]);
                    $this->settleFromHandle($task);
                }
            }
        }
        if ($this->queue !== []) {
            $end = microtime(true) + $this->budget / 1000;
            do {
                [$task, $work, $args] = array_shift($this->queue);
                try {
                    $task->settle($work(...$args));
                } catch (\Throwable $e) {
                    $task->settle(null, $e);
                }
            } while ($this->queue !== [] && microtime(true) < $end);
        }
    }

    /** Stops the pools (engine shutdown). */
    public function shutdown(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
        $this->pools = [];
    }

    /**
     * A closure created in a method carries $this (the scene, the engine, SDL resources...) even when it does
     * not use it: it cannot go to a thread. Checked in both modes, so the mistake shows without threads too.
     */
    public static function checkPortable(callable $task): void
    {
        if ($task instanceof \Closure && (new \ReflectionFunction($task))->getClosureThis() !== null) {
            throw new \LogicException('Work sent to a thread must be a static closure (static fn () => ...) or a static method: this closure carries $this');
        }
    }

    /** First thing every thread runs: the game's autoloader, so its classes exist there too. */
    public function bootloader(): \Closure
    {
        $autoload = $this->config->path('vendor/autoload.php');
        return static function () use ($autoload): void {
            if (is_file($autoload)) {
                require_once $autoload;
            }
        };
    }

    private function settleFromHandle(Task $task): void
    {
        $handle = $task->handle();
        try {
            // the work is wrapped by TaskFailed::guard(): [ok, value]; anything else is the runtime failing
            $outcome = $handle instanceof \Async\Thread ? $handle->getResult() : $handle->await();
            TaskFailed::settle($task, $outcome);
        } catch (\Throwable $e) {
            $task->settle(null, $e);
        }
    }
}
