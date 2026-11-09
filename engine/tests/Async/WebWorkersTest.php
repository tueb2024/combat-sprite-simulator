<?php

namespace JumPHP\Tests\Async;

use JumPHP\Async\Task;
use JumPHP\Async\TaskFailed;
use JumPHP\Async\WebWorkers;
use JumPHP\Async\Worker;
use PHPUnit\Framework\TestCase;

/**
 * The browser protocol without a browser: this test plays the page (engine/web/jumphp.js) and the worker
 * (jumphp-worker.js), which take the .job files, run Worker::run() and write the .res files.
 */
final class WebWorkersTest extends TestCase
{
    private const LIMIT = 3;

    protected function setUp(): void
    {
        exec('rm -rf ' . escapeshellarg(WebWorkers::DIR));
    }

    /** What the page + one worker do with every waiting job. */
    private function runJobs(): void
    {
        foreach (glob(WebWorkers::DIR . '/out/*.job') ?: [] as $job) {
            $id = basename($job, '.job');
            Worker::run($job, WebWorkers::DIR . "/in/$id.tmp");
            unlink($job);
            rename(WebWorkers::DIR . "/in/$id.tmp", WebWorkers::DIR . "/in/$id.res");
        }
    }

    public function testJobRoundTrip(): void
    {
        $web = new WebWorkers();
        $limit = self::LIMIT;
        $ok = new Task();
        $web->submit($ok, static fn (int $n) => array_sum(range(1, min($n, $limit))), [10]);
        $bad = new Task();
        $web->submit($bad, static fn () => throw new \DomainException('nope', 3), []);
        self::assertCount(2, glob(WebWorkers::DIR . '/out/*.job') ?: []);

        $web->poll();
        self::assertFalse($ok->isDone(), 'nothing before the worker answers');
        $this->runJobs();
        $web->poll();

        self::assertSame(6, $ok->result());
        self::assertInstanceOf(TaskFailed::class, $bad->error());
        self::assertSame('DomainException', $bad->error()->class);
        self::assertSame(3, $bad->error()->getCode());
        self::assertSame(0, $web->pendingCount());
    }

    public function testCancelRemovesWaitingJobsAndIgnoresLateResults(): void
    {
        $web = new WebWorkers();
        $a = new Task();
        $idA = $web->submit($a, static fn () => 1, []);
        $b = new Task();
        $web->submit($b, static fn () => 2, []);
        $this->runJobs();           // both already computed by the workers...
        $web->cancel([$idA]);       // ...but the pool of $a was closed meanwhile
        $web->poll();
        self::assertFalse($a->isDone());
        self::assertSame(2, $b->result());
    }

    public function testDisabledWorkersGiveTheJobsBack(): void
    {
        $web = new WebWorkers();
        $task = new Task();
        $web->submit($task, static fn () => 42, []);
        touch(WebWorkers::DIR . '/disabled');
        $jobs = $web->poll();
        self::assertCount(1, $jobs);
        [$back, $work, $args] = $jobs[0];
        self::assertSame($task, $back);
        self::assertSame(42, $work(...$args));
        self::assertSame([], glob(WebWorkers::DIR . '/out/*.job') ?: []);
    }
}
