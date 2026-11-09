<?php

namespace JumPHP\Runtime;

use Evenement\EventEmitter;
use JumPHP\Async\Task;
use JumPHP\Net\RuntimeLink;

/**
 * A RuntimeLink whose state you set by hand: for tests and samples (a desktop run has no runtime to find). `$engine->setRuntime(new ManualRuntime())`
 * then `->setState(RuntimeLink::READY)`; pair() answers `$answer` at once and moves the state to ready / refused like the real client.
 */
final class ManualRuntime extends EventEmitter implements RuntimeLink
{
    public int $checks = 0;
    /** @var list<array{string, string}> */
    public array $pairs = [];
    public bool $answer = true;

    public function __construct(private string $state = self::MISSING, private int $port = 47820)
    {
    }

    public function setState(string $state): void
    {
        if ($state !== $this->state) {
            $this->state = $state;
            $this->emit('change', [$state]);
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    public function bridgeUrl(): string
    {
        return 'ws://127.0.0.1:' . $this->port . '/v1';
    }

    public function check(): Task
    {
        $this->checks++;
        $task = new Task();
        $task->settle($this->state);
        return $task;
    }

    public function pair(string $app, string $title): Task
    {
        $this->pairs[] = [$app, $title];
        $this->setState($this->answer ? self::READY : self::REFUSED);
        $task = new Task();
        $task->settle($this->answer);
        return $task;
    }
}
