<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;
use JumPHP\Tauri\App;

/** Desktop apps (JumPHP\Tauri\App): Tauri's dialog, notification and opener plugins, over the main window. */
final class TauriProvider extends BaseProvider
{
    protected array $features = ['message', 'confirm', 'open', 'save', 'folder', 'notify', 'openUrl', 'openPath', 'reveal', 'copy', 'paste'];

    public function __construct(private readonly App $app)
    {
    }

    public function name(): string
    {
        return 'tauri';
    }

    public function message(string $message, string $title, string $kind): Task
    {
        return $this->app->dialog('dialog.message', $this->parent() + ['message' => $message, 'title' => $title, 'kind' => $kind]);
    }

    public function confirm(string $message, string $title, ?string $ok, ?string $cancel): Task
    {
        $args = $this->parent() + ['message' => $message, 'title' => $title];
        if ($ok !== null && $cancel !== null) {
            $args += ['ok' => $ok, 'cancel' => $cancel];
        }
        return $this->app->dialog('dialog.confirm', $args);
    }

    public function open(array $options): Task
    {
        return $this->app->dialog('dialog.open', $this->parent() + self::fileOptions($options) + [
            'multiple' => (bool)($options['multiple'] ?? false),
            'folder' => (bool)($options['folder'] ?? false),
        ]);
    }

    public function save(array $options): Task
    {
        return $this->app->dialog('dialog.save', $this->parent() + self::fileOptions($options));
    }

    public function notify(string $title, string $body): bool
    {
        $this->app->sendCommand(['cmd' => 'notification.show', 'title' => $title, 'body' => $body]);
        return true;
    }

    public function openUrl(string $url): bool
    {
        if (!preg_match('#^(https?|mailto):#i', $url)) {
            return false;
        }
        $this->app->sendCommand(['cmd' => 'opener.url', 'url' => $url]);
        return true;
    }

    public function openPath(string $path): bool
    {
        $this->app->sendCommand(['cmd' => 'opener.path', 'path' => $path]);
        return true;
    }

    public function reveal(string $path): bool
    {
        $this->app->sendCommand(['cmd' => 'opener.reveal', 'path' => $path]);
        return true;
    }

    public function copy(string $text): bool
    {
        $this->app->sendCommand(['cmd' => 'clipboard.write', 'text' => $text]);
        return true;
    }

    public function paste(): Task
    {
        return $this->app->request('clipboard.read');
    }

    /** the dialog over the main window when it is open */
    private function parent(): array
    {
        return isset($this->app->windows()['main']) ? ['window' => 'main'] : [];
    }

    private static function fileOptions(array $o): array
    {
        $out = [];
        foreach (['title', 'directory', 'file_name'] as $key) {
            if (isset($o[$key]) && $o[$key] !== '') {
                $out[$key] = (string)$o[$key];
            }
        }
        if (!empty($o['filters']) && is_array($o['filters'])) {
            $out['filters'] = array_map(static fn ($exts): array => array_values(array_map(
                static fn ($e): string => (string)preg_replace('/^\*?\./', '', (string)$e), (array)$exts)), $o['filters']);
        }
        return $out;
    }
}
