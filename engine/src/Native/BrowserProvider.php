<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;
use JumPHP\Bridge\JsBridge;

/**
 * The browser build: links open in a new tab, text to the clipboard, and the system's file chooser (an <input type=file>: the chosen
 * files are written into the engine's memory, /tmp/jumphp-picked/<name>, and the Task gives those paths; a folder or a save dialog: no).
 * The page does them through JsBridge.
 */
final class BrowserProvider extends BaseProvider
{
    protected array $features = ['openUrl', 'copy', 'open'];
    private int $nextPick = 1;
    /** @var array<int, array{task: Task, multiple: bool}> */
    private array $picks = [];
    private bool $listening = false;

    public function __construct(private readonly JsBridge $bridge)
    {
    }

    public function open(array $options): Task
    {
        if (!empty($options['folder'])) {
            return $this->unsupported('folder');
        }
        if (!$this->listening) {
            $this->listening = true;
            $this->bridge->on('filepick', function (array $e): void {
                $pick = $this->picks[(int)($e['id'] ?? 0)] ?? null;
                if ($pick === null) {
                    return;
                }
                unset($this->picks[(int)$e['id']]);
                $paths = is_array($e['paths'] ?? null) && $e['paths'] !== [] ? array_map('strval', $e['paths']) : null;
                $pick['task']->settle($paths === null ? null : ($pick['multiple'] ? $paths : $paths[0]));
            });
        }
        $task = new Task();
        $id = $this->nextPick++;
        $multiple = !empty($options['multiple']);
        $this->picks[$id] = ['task' => $task, 'multiple' => $multiple];
        $accept = [];
        foreach ((array)($options['filters'] ?? []) as $extensions) {
            foreach ((array)$extensions as $ext) {
                $accept[] = '.' . ltrim((string)$ext, '.');
            }
        }
        $this->bridge->send('filepick', ['id' => $id, 'multiple' => $multiple, 'accept' => implode(',', $accept)]);
        return $task;
    }

    public function name(): string
    {
        return 'browser';
    }

    public function copy(string $text): bool
    {
        return $this->bridge->send('device', ['do' => 'copy', 'text' => $text]);
    }

    public function openUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 && $this->bridge->send('open', ['url' => $url]);
    }
}
