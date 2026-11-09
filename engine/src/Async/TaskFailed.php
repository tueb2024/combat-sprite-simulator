<?php

namespace JumPHP\Async;

/**
 * An exception thrown by work that ran in another thread. Exceptions cannot cross threads: this one carries
 * the original class, message, code, file and line.
 */
final class TaskFailed extends \RuntimeException
{
    public function __construct(public readonly string $class, string $message, int $code, string $file, int $line)
    {
        parent::__construct("$class: $message", $code);
        $this->file = $file;
        $this->line = $line;
    }

    /** @internal Tasks: runs $work, never throws (an exception escaping a thread reaches the main thread). */
    public static function guard(callable $work): \Closure
    {
        return static function (mixed ...$args) use ($work): array {
            try {
                return [true, $work(...$args)];
            } catch (\Throwable $e) {
                return [false, [get_class($e), $e->getMessage(), (int)$e->getCode(), $e->getFile(), $e->getLine()]];
            }
        };
    }

    /** @internal Tasks: [ok, value] from guard() -> settles the task */
    public static function settle(Task $task, mixed $outcome): void
    {
        if (!is_array($outcome) || count($outcome) !== 2) {
            $task->settle($outcome);
        } elseif ($outcome[0] === true) {
            $task->settle($outcome[1]);
        } else {
            [$class, $message, $code, $file, $line] = $outcome[1];
            $task->settle(null, new self($class, $message, $code, $file, $line));
        }
    }
}
