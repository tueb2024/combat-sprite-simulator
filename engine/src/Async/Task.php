<?php

namespace JumPHP\Async;

/**
 * The result of some work running in the background (a thread, a pool worker, or the fallback queue).
 * Never blocks: check isDone() each frame, or give a callback to then() (called on the main thread,
 * at the start of a frame, before the scenes update).
 *
 *   $task = $this->engine->tasks()->thread(static fn () => Maze::generate(64, 64, $seed));
 *   ...
 *   if ($task->isDone()) { $maze = $task->result(); }      // result() rethrows the task's exception
 */
final class Task
{
    private bool $done = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    /** @var callable[] */
    private array $callbacks = [];
    /** @var callable[] */
    private array $errorCallbacks = [];

    /** @param object|null $handle Async\Thread / Async\Future; null: fallback queue, or not submitted yet */
    public function __construct(private ?object $handle = null)
    {
    }

    /** @internal the Async\Thread / Async\Future doing the work (null in fallback mode) */
    public function handle(): ?object
    {
        return $this->handle;
    }

    /** @internal Pool: the job left the local backlog for a worker */
    public function attach(object $handle): void
    {
        $this->handle = $handle;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** Done without exception. */
    public function isOk(): bool
    {
        return $this->done && $this->error === null;
    }

    /** The value returned by the work (null while not done). Rethrows its exception. */
    public function result(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }

    /** fn (mixed $result) on success; fn (\Throwable $e) with $onError. Called at once if already done. */
    public function then(callable $onDone, ?callable $onError = null): self
    {
        if ($this->done) {
            $this->error === null ? $onDone($this->result) : ($onError !== null ? $onError($this->error) : null);
            return $this;
        }
        $this->callbacks[] = $onDone;
        if ($onError !== null) {
            $this->errorCallbacks[] = $onError;
        }
        return $this;
    }

    /** @internal Tasks / Pool */
    public function settle(mixed $result, ?\Throwable $error = null): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->result = $result;
        $this->error = $error;
        foreach ($error === null ? $this->callbacks : $this->errorCallbacks as $callback) {
            $error === null ? $callback($result) : $callback($error);
        }
        $this->callbacks = $this->errorCallbacks = [];
    }
}
