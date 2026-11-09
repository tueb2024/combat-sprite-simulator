<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Async\Task;

/** The default RuntimeLink, when the `jumphp/runtime` extension is not installed: it is never there, the server's bridge is used. */
final class NoRuntime extends EventEmitter implements RuntimeLink
{
    public function state(): string
    {
        return self::UNSUPPORTED;
    }

    public function bridgeUrl(): string
    {
        return 'ws://127.0.0.1:47820/v1';
    }

    public function check(): Task
    {
        $task = new Task();
        $task->settle(self::UNSUPPORTED);
        return $task;
    }

    public function pair(string $app, string $title): Task
    {
        $task = new Task();
        $task->settle(false);
        return $task;
    }
}
