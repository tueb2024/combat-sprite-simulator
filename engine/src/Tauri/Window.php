<?php

namespace JumPHP\Tauri;

use Evenement\EventEmitter;

/**
 * A native window of a desktop app, with its page (a WebView). Every call is a command run after the
 * current frame; what happens to the window comes back as events:
 *
 *   'close-requested' (NativeEvent: preventDefault() keeps it open), 'closed', 'focus', 'blur',
 *   'resized' (int $width, int $height: physical pixels), 'moved' (int $x, int $y), 'drop' (list<string> $paths:
 *   files dropped on the window), 'loaded' (the page is ready: its window.jumphp works).
 *
 *   $app->window()->setTitle('Untitled - Notes')->setSize(1000, 700);
 *   $app->window()->send('saved', ['file' => $path]);              // jumphp.on('saved', ...) in the page
 */
final class Window extends EventEmitter
{
    private bool $open = true;
    private bool $loaded = false;
    /** @var array{x: int, y: int, width: int, height: int} where it is on the screen (physical pixels), as last told */
    private array $geometry = ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];

    public function __construct(private readonly App $app, public readonly string $label)
    {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** The page loaded (its first jumphp call arrived). */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** @return array{x: int, y: int, width: int, height: int} physical pixels, from its last 'moved' / 'resized' */
    public function geometry(): array
    {
        return $this->geometry;
    }

    public function setTitle(string $title): self
    {
        return $this->cmd('window.set_title', ['title' => $title]);
    }

    /** Logical pixels (the same on a high-density screen). */
    public function setSize(float $width, float $height): self
    {
        return $this->cmd('window.set_size', ['width' => $width, 'height' => $height]);
    }

    public function setPosition(float $x, float $y): self
    {
        return $this->cmd('window.set_position', ['x' => $x, 'y' => $y]);
    }

    public function center(): self
    {
        return $this->cmd('window.center');
    }

    public function setFullscreen(bool $on = true): self
    {
        return $this->cmd('window.set_fullscreen', ['on' => $on]);
    }

    public function setResizable(bool $on = true): self
    {
        return $this->cmd('window.set_resizable', ['on' => $on]);
    }

    public function setAlwaysOnTop(bool $on = true): self
    {
        return $this->cmd('window.set_always_on_top', ['on' => $on]);
    }

    public function show(): self
    {
        return $this->cmd('window.show');
    }

    public function hide(): self
    {
        return $this->cmd('window.hide');
    }

    public function focus(): self
    {
        return $this->cmd('window.focus');
    }

    public function maximize(): self
    {
        return $this->cmd('window.maximize');
    }

    public function unmaximize(): self
    {
        return $this->cmd('window.unmaximize');
    }

    public function minimize(): self
    {
        return $this->cmd('window.minimize');
    }

    /** Closes it now (no 'close-requested'). The last one closed: the app's 'exit-requested'. */
    public function close(): self
    {
        return $this->cmd('window.close');
    }

    /** Another page of the app ('settings.html') or a web address. */
    public function navigate(string $url): self
    {
        $this->loaded = false;
        return $this->cmd('window.navigate', ['url' => $url]);
    }

    /** Runs JavaScript in the page (its result is not returned: send it back with jumphp.send()). */
    public function eval(string $js): self
    {
        return $this->cmd('webview.eval', ['js' => $js]);
    }

    /** PHP -> page: the page's jumphp.on($name, fn (payload)) listeners get $payload (any JSON value). */
    public function send(string $name, mixed $payload = null): self
    {
        return $this->cmd('webview.emit', ['name' => $name, 'payload' => $payload]);
    }

    /**
     * This window's menu (Menu): clicks come as $window->on('menu', fn (string $item, ?bool $checked)) and
     * $app->on('menu'). On macOS it becomes the application's menu bar.
     */
    public function setMenu(array $menu): self
    {
        return $this->cmd('menu.set', ['menu' => Menu::normalize($menu)]);
    }

    public function removeMenu(): self
    {
        return $this->cmd('menu.remove');
    }

    /** Changes one item of this window's menu: ['enabled' => bool, 'checked' => bool, 'text' => string]. */
    public function setMenuItem(string $item, array $changes): self
    {
        return $this->cmd('menu.set_item', ['item' => $item] + array_intersect_key($changes, ['enabled' => 1, 'checked' => 1, 'text' => 1]));
    }

    /** @internal App: an event of this window */
    public function dispatch(string $event, array $data): void
    {
        switch ($event) {
            case 'close-requested':
                $e = new NativeEvent('close-requested', $data);
                $this->emit('close-requested', [$e]);
                if (!$e->isDefaultPrevented()) {
                    $this->close();
                }
                break;
            case 'destroyed':
                $this->open = false;
                $this->emit('closed');
                break;
            case 'resized':
                $this->geometry['width'] = (int)($data['width'] ?? 0);
                $this->geometry['height'] = (int)($data['height'] ?? 0);
                $this->emit('resized', [(int)($data['width'] ?? 0), (int)($data['height'] ?? 0)]);
                break;
            case 'moved':
                $this->geometry['x'] = (int)($data['x'] ?? 0);
                $this->geometry['y'] = (int)($data['y'] ?? 0);
                $this->emit('moved', [(int)($data['x'] ?? 0), (int)($data['y'] ?? 0)]);
                break;
            case 'drop':
                $this->emit('drop', [array_values(array_map('strval', (array)($data['paths'] ?? [])))]);
                break;
            case 'loaded':
                $this->loaded = true;
                $this->emit('loaded');
                break;
            default:
                $this->emit($event, [$data]);
        }
    }

    private function cmd(string $cmd, array $args = []): self
    {
        $this->app->sendCommand(['cmd' => $cmd, 'window' => $this->label] + $args);
        return $this;
    }
}
