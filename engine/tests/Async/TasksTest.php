<?php

namespace JumPHP\Tests\Async;

use JumPHP\Async\Task;
use JumPHP\Async\Tasks;
use JumPHP\Kernel\GameConfig;
use PHPUnit\Framework\TestCase;

/** Runs on both runtimes: fallback queue on PHP 8.3, real threads on the async runtime. */
final class TasksTest extends TestCase
{
    private function waitAll(Tasks $tasks, Task ...$list): void
    {
        for ($i = 0; $i < 5000 && array_filter($list, fn (Task $t) => !$t->isDone()) !== []; $i++) {
            $tasks->poll();
            usleep(1000);   // a frame's sleep: lets TrueAsync report finished threads
        }
    }

    public function testThreadResultAndCallback(): void
    {
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $seen = null;
        $task = $tasks->thread(static fn () => array_sum(range(1, 100)))->then(function ($r) use (&$seen) { $seen = $r; });
        self::assertFalse($task->isDone(), 'never done before a poll');
        $this->waitAll($tasks, $task);
        self::assertTrue($task->isOk());
        self::assertSame(5050, $task->result());
        self::assertSame(5050, $seen);
        self::assertSame(0, $tasks->pendingCount());
    }

    public function testThreadException(): void
    {
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $task = $tasks->thread(static fn () => throw new \RuntimeException('boom'));
        $this->waitAll($tasks, $task);
        self::assertTrue($task->isDone());
        self::assertFalse($task->isOk());
        self::assertStringContainsString('boom', (string)$task->error()?->getMessage());
        self::assertSame(__FILE__, $task->error()?->getFile());
    }

    public function testPoolUsesAutoloadedClasses(): void
    {
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $pool = $tasks->pool(2);
        $jobs = [];
        foreach ([1, 2, 3, 4] as $n) {
            // an engine class inside the worker: the bootloader loaded vendor/autoload.php there
            $jobs[] = $pool->submit(static fn (int $v) => \JumPHP\Util\Math::clamp($v * 10, 0, 25), $n);
        }
        $this->waitAll($tasks, ...$jobs);
        $pool->close();
        self::assertEquals([10, 20, 25, 25], array_map(fn (Task $t) => $t->result(), $jobs));
    }

    public function testPoolSubmitNeverBlocks(): void
    {
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $pool = $tasks->pool(2);
        $slow = static function (int $n): int {
            $s = 0;
            for ($i = 0; $i < 200000; $i++) {
                $s += $i % 3;
            }
            return $n;
        };
        $t = microtime(true);
        $jobs = [];
        for ($i = 0; $i < 300; $i++) {
            $jobs[] = $pool->submit($slow, $i);
        }
        self::assertLessThan(0.2, microtime(true) - $t, 'submitting must not wait for the workers');
        $this->waitAll($tasks, ...$jobs);
        $pool->close();
        self::assertSame(299, $jobs[299]->result());
        self::assertSame(0, $tasks->pendingCount());
    }

    public function testClosureCarryingThisIsRefused(): void
    {
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $this->expectException(\LogicException::class);
        $tasks->thread(fn () => 1);   // not static: carries $this (the test case)
    }

    public function testFallbackBudgetRunsAtLeastOneJobPerPoll(): void
    {
        if (Tasks::threaded()) {
            self::markTestSkipped('fallback only');
        }
        $tasks = new Tasks(new GameConfig([], dirname(__DIR__, 2)));
        $tasks->budget = 0.0;
        $a = $tasks->thread(static fn () => 1);
        $b = $tasks->thread(static fn () => 2);
        $tasks->poll();
        self::assertTrue($a->isDone());
        self::assertFalse($b->isDone());
        $tasks->poll();
        self::assertSame(2, $b->result());
    }
}
