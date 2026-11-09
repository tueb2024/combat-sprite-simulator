<?php

namespace JumPHP\Async;

/**
 * A fixed set of worker threads sharing a job queue (Tasks::pool()). Threads are started once, so many small
 * jobs cost much less than one thread each. In the browser the jobs go to the page's Web Workers (shared by
 * all pools); elsewhere without threads they run between frames (Tasks::$budget).
 *
 *   $pool = $this->engine->tasks()->pool();              // one worker per core
 *   $task = $pool->submit(static fn (int $y) => Fractal::row($y, 320), 42);
 *   $pool->close();                                       // in exit(): stops the workers
 *
 * submit() never blocks the frame: TrueAsync's own queue is kept short (QUEUE) and the other jobs wait in a
 * local backlog, handed to the workers at each frame (Tasks::poll()).
 */
final class Pool
{
    /** Jobs queued in TrueAsync at once (its submit() would suspend the caller, i.e. freeze the game, when full). */
    private const QUEUE = 64;

    private ?object $pool = null;
    private bool $closed = false;
    /** @var array<int, array{0: Task, 1: \Closure, 2: array}> */
    private array $backlog = [];
    /** @var int[] browser job ids (cancelled by close()) */
    private array $webJobs = [];

    public function __construct(private readonly Tasks $tasks, int $workers = 0)
    {
        if (Tasks::backend() === 'workers') {
            $tasks->web()->warm();
        }
        if (Tasks::backend() === 'threads') {
            $this->pool = new \Async\ThreadPool(max(0, $workers), self::QUEUE, $tasks->bootloader());
        }
    }

    /** Queues fn (...$args) on a worker. Static closures or static methods, plain-value args. */
    public function submit(callable $task, mixed ...$args): Task
    {
        if ($this->closed) {
            throw new \LogicException('This pool is closed');
        }
        Tasks::checkPortable($task);
        if (Tasks::backend() === 'workers') {
            $result = new Task();
            $this->webJobs[] = $this->tasks->web()->submit($result, $task, $args);
            return $result;
        }
        if ($this->pool === null) {
            return $this->tasks->enqueue($task, $args);
        }
        $result = new Task();
        $this->backlog[] = [$result, TaskFailed::guard($task), $args];
        $this->pump();
        return $result;
    }

    /** @internal Tasks::poll(): moves backlog jobs to the workers while TrueAsync's queue has room. */
    public function pump(): void
    {
        /** @var \Async\ThreadPool|null $pool */
        $pool = $this->pool;
        while ($pool !== null && $this->backlog !== [] && $pool->getPendingCount() < self::QUEUE - 1) {
            [$task, $work, $args] = array_shift($this->backlog);
            $task->attach($pool->submit($work, ...$args));
            $this->tasks->watch($task);
        }
    }

    /** Jobs not handed to a worker yet. */
    public function backlogCount(): int
    {
        return count($this->backlog);
    }

    public function workerCount(): int
    {
        /** @var \Async\ThreadPool|null $pool */
        $pool = $this->pool;
        return $pool !== null ? $pool->getWorkerCount() : max(1, WebWorkers::available());
    }

    /** Do the jobs run in parallel with the game (threads or Web Workers)? */
    public function isThreaded(): bool
    {
        return $this->pool !== null || Tasks::backend() === 'workers';
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Stops the workers; jobs still waiting in the backlog are dropped (their tasks never finish). */

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->backlog = [];
        if ($this->webJobs !== []) {
            $this->tasks->web()->cancel($this->webJobs);
            $this->webJobs = [];
        }
        /** @var \Async\ThreadPool|null $pool */
        $pool = $this->pool;
        $pool?->close();
    }
}
