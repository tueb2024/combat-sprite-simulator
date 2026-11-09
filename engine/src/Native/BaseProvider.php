<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/** Every feature unsupported (a failed Task, or false): providers override what they have. */
abstract class BaseProvider implements Provider
{
    /** @var list<string> */
    protected array $features = [];

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function message(string $message, string $title, string $kind): Task
    {
        return $this->unsupported('message');
    }

    public function confirm(string $message, string $title, ?string $ok, ?string $cancel): Task
    {
        return $this->unsupported('confirm');
    }

    public function open(array $options): Task
    {
        return $this->unsupported(!empty($options['folder']) ? 'folder' : 'open');
    }

    public function save(array $options): Task
    {
        return $this->unsupported('save');
    }

    public function notify(string $title, string $body): bool
    {
        return false;
    }

    public function openUrl(string $url): bool
    {
        return false;
    }

    public function openPath(string $path): bool
    {
        return false;
    }

    public function reveal(string $path): bool
    {
        return false;
    }

    public function copy(string $text): bool
    {
        return false;
    }

    public function paste(): Task
    {
        return $this->unsupported('paste');
    }

    protected function unsupported(string $feature): Task
    {
        $task = new Task();
        $task->settle(null, new UnsupportedFeature($feature, $this->name()));
        return $task;
    }

    protected static function done(mixed $value): Task
    {
        $task = new Task();
        $task->settle($value);
        return $task;
    }
}
