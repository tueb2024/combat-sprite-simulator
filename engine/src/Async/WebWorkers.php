<?php

namespace JumPHP\Async;

use Laravel\SerializableClosure\UnsignedSerializableClosure;

/**
 * Browser backend of Tasks / Pool: a pool of Web Workers, each running its own PHP engine (php-web.wasm)
 * with the game's files (engine/web/jumphp-worker.js). Jobs and results go through the emscripten file
 * system, polled by the page once per frame:
 *
 *   PHP  -> /tmp/jumphp-tasks/out/<id>.job   serialize([closure, args])    (the page takes it when a worker is idle)
 *   page -> /tmp/jumphp-tasks/in/<id>.res    serialize([ok, value]) from the worker (TaskFailed::guard)
 *   page -> /tmp/jumphp-tasks/in/<id>.err    the worker crashed (text)
 *   page -> /tmp/jumphp-tasks/disabled       workers could not start: the jobs run between frames instead
 *   PHP  -> /tmp/jumphp-tasks/warm           start the workers now (a pool was created: hides their boot time)
 *
 * Closures cross with laravel/serializable-closure (their source code + captured values), so the same
 * `static fn` as for threads works; the scope class (self::) must be autoloadable in the worker.
 */
final class WebWorkers
{
    public const DIR = '/tmp/jumphp-tasks';

    private int $next = 0;
    /** @var array<int, array{0: Task, 1: callable, 2: array}> id => job (kept to rerun it here if workers fail) */
    private array $jobs = [];
    private bool $disabled = false;

    public function __construct()
    {
        foreach (['/out', '/in'] as $dir) {
            is_dir(self::DIR . $dir) || mkdir(self::DIR . $dir, 0777, true);
        }
    }

    /** Workers the page offers (prelude JUMPHP_WORKERS), 0 outside the browser or inside a worker. */
    public static function available(): int
    {
        if (!\JumPHP\Kernel\Platform::isWasm() || defined('JUMPHP_WORKER')) {
            return 0;
        }
        return max(0, (int)\JumPHP\Kernel\Platform::setting('JUMPHP_WORKERS', 0));
    }

    /** Asks the page to start its workers before the first job (each one boots a PHP engine: ~0.5-1 s). */
    public function warm(): void
    {
        is_file(self::DIR . '/warm') || touch(self::DIR . '/warm');
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /** @return int the job id */
    public function submit(Task $task, callable $work, array $args): int
    {
        $id = ++$this->next;
        $this->jobs[$id] = [$task, $work, $args];
        $closure = $work instanceof \Closure ? $work : \Closure::fromCallable($work);
        $payload = serialize([new UnsignedSerializableClosure($closure), $args]);
        $file = sprintf('%s/out/%09d', self::DIR, $id);
        file_put_contents("$file.tmp", $payload);
        rename("$file.tmp", "$file.job");   // the page only takes complete files
        return $id;
    }

    /** Forgets jobs (closed pool): those not started yet are removed, late results are ignored. */
    public function cancel(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->jobs[$id]);
            @unlink(sprintf('%s/out/%09d.job', self::DIR, $id));
        }
    }

    public function pendingCount(): int
    {
        return count($this->jobs);
    }

    /**
     * Settles the finished jobs. @return array<int, array{0: Task, 1: callable, 2: array}> jobs to run on the
     * main thread instead (the page could not start any worker)
     */
    public function poll(): array
    {
        if ($this->jobs === []) {
            return [];
        }
        if (!$this->disabled && is_file(self::DIR . '/disabled')) {
            $this->disabled = true;
            \JumPHP\Kernel\Application::stderr("[jumphp] no Web Worker could start: tasks run between frames\n");
        }
        if ($this->disabled) {
            foreach (array_keys($this->jobs) as $id) {
                @unlink(sprintf('%s/out/%09d.job', self::DIR, $id));
            }
            $jobs = $this->jobs;
            $this->jobs = [];
            return array_values($jobs);
        }
        foreach (scandir(self::DIR . '/in') ?: [] as $name) {
            if (!preg_match('/^(\d+)\.(res|err)$/', $name, $m)) {
                continue;
            }
            $file = self::DIR . '/in/' . $name;
            $data = (string)file_get_contents($file);
            unlink($file);
            $id = (int)$m[1];
            if (!isset($this->jobs[$id])) {
                continue;                       // cancelled
            }
            $task = $this->jobs[$id][0];
            unset($this->jobs[$id]);
            if ($m[2] === 'err') {
                $task->settle(null, new TaskFailed('WorkerError', $data, 0, 'jumphp-worker.js', 0));
                continue;
            }
            $outcome = @unserialize($data);
            if ($outcome === false && $data !== serialize(false)) {
                $task->settle(null, new TaskFailed('WorkerError', 'unreadable result', 0, 'jumphp-worker.js', 0));
                continue;
            }
            TaskFailed::settle($task, $outcome);
        }
        return [];
    }
}
