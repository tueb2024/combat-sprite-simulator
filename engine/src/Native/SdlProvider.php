<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * Games on the desktop (SDL): SDL's own message box (modal: the game waits while it is open, like any system
 * message box). The rest needs a desktop app (Tauri).
 */
final class SdlProvider extends BaseProvider
{
    protected array $features = ['message', 'clipboard'];

    public function name(): string
    {
        return 'sdl';
    }

    public static function isAvailable(): bool
    {
        return function_exists('SDL_ShowSimpleMessageBox');
    }

    public function message(string $message, string $title, string $kind): Task
    {
        $flags = match ($kind) {
            'error' => \SDL_MESSAGEBOX_ERROR,
            'warning' => \SDL_MESSAGEBOX_WARNING,
            default => \SDL_MESSAGEBOX_INFORMATION,
        };
        $window = null;
        try {
            $window = \JumPHP\Kernel\Engine::get()->sdl->getWindow()?->getWindow();
        } catch (\LogicException) {
        }
        $ok = \SDL_ShowSimpleMessageBox($flags, $title, $message, $window instanceof \SDL_Window ? $window : null) === 0;
        return self::done($ok);
    }

    /** @var array{copy: ?list<string>, paste: ?list<string>}|null the commands that write and read the clipboard here, found once */
    private static ?array $tools = null;

    /** @return array{copy: ?list<string>, paste: ?list<string>} */
    private static function tools(): array
    {
        if (self::$tools !== null) {
            return self::$tools;
        }
        $has = static fn (string $cmd): bool => trim((string)@shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . escapeshellarg($cmd) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'))) !== '';
        $tools = ['copy' => null, 'paste' => null];
        if (PHP_OS_FAMILY === 'Windows') {
            $tools = ['copy' => ['powershell', '-NoProfile', '-Command', '$t = [Console]::In.ReadToEnd(); Set-Clipboard -Value $t'],
                'paste' => ['powershell', '-NoProfile', '-Command', '[Console]::OutputEncoding = [Text.Encoding]::UTF8; Get-Clipboard -Raw']];
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $tools = ['copy' => ['pbcopy'], 'paste' => ['pbpaste']];
        } elseif (getenv('WAYLAND_DISPLAY') && $has('wl-copy')) {
            $tools = ['copy' => ['wl-copy'], 'paste' => ['wl-paste', '--no-newline']];
        } elseif ($has('xclip')) {
            $tools = ['copy' => ['xclip', '-selection', 'clipboard', '-i'], 'paste' => ['xclip', '-selection', 'clipboard', '-o']];
        } elseif ($has('xsel')) {
            $tools = ['copy' => ['xsel', '--clipboard', '--input'], 'paste' => ['xsel', '--clipboard', '--output']];
        }
        return self::$tools = $tools;
    }

    public function copy(string $text): bool
    {
        $command = self::tools()['copy'];
        if ($command === null) {
            return false;
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        // xclip / wl-copy keep the text for the other programs by staying in the background: not waited for
        return true;
    }

    public function paste(): Task
    {
        $command = self::tools()['paste'];
        if ($command === null) {
            return $this->unsupported('paste');
        }
        $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return $this->unsupported('paste');
        }
        stream_set_blocking($pipes[1], true);
        stream_set_timeout($pipes[1], 2);
        $text = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return self::done($text);
    }
}
