<?php
// Signatures of the TrueAsync API used by JumPHP\Async (https://github.com/true-async/php-async, v0.11.3),
// for phpstan and IDEs. The extension itself only exists in the async runtime (docker/desktop-async).
// Full API: https://true-async.github.io/en/docs.html

namespace Async;

interface Completable
{
    public function cancel(?AsyncCancellation $cancellation = null): void;
    public function isCompleted(): bool;
    public function isCancelled(): bool;
}

class AsyncCancellation extends \Exception {}
class AsyncException extends \Exception {}

class RemoteException extends AsyncException
{
    public function getRemoteException(): ?\Throwable {}
    public function getRemoteClass(): string {}
}

class ThreadTransferException extends AsyncException {}

final class Thread implements Completable
{
    public function isRunning(): bool {}
    public function isCompleted(): bool {}
    public function isCancelled(): bool {}
    public function getResult(): mixed {}
    public function getException(): mixed {}
    public function cancel(?AsyncCancellation $cancellation = null): void {}
}

final class Future implements Completable
{
    public function isCompleted(): bool {}
    public function isCancelled(): bool {}
    public function cancel(?AsyncCancellation $cancellation = null): void {}
    public function await(?Completable $cancellation = null): mixed {}
}

final class ThreadPool
{
    public function __construct(int $workers = 0, int $queueSize = 0, ?\Closure $bootloader = null, bool $coroutine = false, int $concurrency = 0) {}
    public function submit(callable $task, mixed ...$args): Future {}
    public function map(array $items, callable $task): array {}
    public function close(): void {}
    public function cancel(): void {}
    public function isClosed(): bool {}
    public function getPendingCount(): int {}
    public function getRunningCount(): int {}
    public function getCompletedCount(): int {}
    public function getWorkerCount(): int {}
}

final class ThreadChannel
{
    public function __construct(int $capacity = 16) {}
    public function send(mixed $value, ?Completable $cancellationToken = null): void {}
    public function recv(?Completable $cancellationToken = null): mixed {}
    public function close(): void {}
    public function isClosed(): bool {}
    public function count(): int {}
    public function isEmpty(): bool {}
    public function isFull(): bool {}
}

function spawn_thread(\Closure $task, bool $inherit = true, ?\Closure $bootloader = null): Thread {}
function available_parallelism(): int {}
function delay(int $ms): void {}
function suspend(): void {}
function await(Completable $awaitable, ?Completable $cancellation = null): mixed {}
