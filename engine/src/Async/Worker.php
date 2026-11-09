<?php

namespace JumPHP\Async;

/**
 * Runs inside a Web Worker (engine/web/jumphp-worker.js): one job file in, one result file out.
 * Never throws (an uncaught exception would end the worker's PHP): failures become [false, info].
 */
final class Worker
{
    public static function run(string $in, string $out): void
    {
        try {
            [$closure, $args] = unserialize((string)file_get_contents($in));
            $outcome = TaskFailed::guard($closure->getClosure())(...$args);
        } catch (\Throwable $e) {
            $outcome = [false, [get_class($e), $e->getMessage(), (int)$e->getCode(), $e->getFile(), $e->getLine()]];
        }
        try {
            $data = serialize($outcome);
        } catch (\Throwable $e) {
            $data = serialize([false, [get_class($e), 'the result cannot cross to the game: ' . $e->getMessage(), 0, __FILE__, __LINE__]]);
        }
        file_put_contents($out, $data);
    }
}
