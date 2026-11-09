<?php

namespace JumPHP\Tauri;

use Evenement\EventEmitter;
use JumPHP\Async\Task;
use JumPHP\Async\Tasks;
use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;
use JumPHP\Loop\GameLoop;
use JumPHP\Native\Autostart;
use JumPHP\Save\Options;
use JumPHP\Save\Storage;
use JumPHP\Util\Lang;
use JumPHP\Util\Time;
use React\EventLoop\Loop;

/**
 * A desktop app: native windows whose pages are HTML / CSS / JS (Tauri), driven by PHP. The app's app.php:
 *
 *   require __DIR__ . '/vendor/autoload.php';
 *   exit(JumPHP\Tauri\App::boot(__DIR__)->run());
 *
 * PHP is the main program, like for a game: run() starts the native runtime from PHP's main thread and the
 * runtime calls frame() at every frame (60 a second by default, and at once when something happens), where
 * PHP handles what happened, runs one turn of the ReactPHP loop (timers, HTTP, tasks) and sends its commands.
 *
 * config/game.yaml (under game:): id, title, version, lang, ui: webview, main: App\Main (a class implementing
 * AppMain: it registers the commands and listeners), and app: {identifier, pages, tick_hz, icon, window: {...},
 * native_commands} (docs/tauri.md).
 *
 * Page -> PHP:  jumphp.invoke('greet', {name}) -> $app->command('greet', fn (array $p) => "Hello {$p['name']}")
 *               jumphp.send('clicked', {...})  -> $app->on('page:clicked', fn (array|null $p, string $window) => ...)
 * PHP -> page:  $app->window()->send('tick', [...]) or $app->send(...) (every window) -> jumphp.on('tick', ...)
 *
 * Events of the app: 'ready', 'frame' (float $dt), 'exit-requested' (NativeEvent: the last window closed; quits
 * unless prevented), 'page:<name>', 'error' (string $message), 'quit'. Windows have their own (Window).
 */
final class App extends EventEmitter
{
    private static ?App $current = null;

    public readonly GameLoop $gameLoop;
    public readonly Storage $storage;
    public readonly Options $options;
    private ?Autostart $autostart = null;
    public readonly Lang $lang;

    /** @var array<string, Window> */
    private array $windows = [];
    /** @var array<string, \Closure> */
    private array $commands = [];
    /** @var array<string, Tray> */
    private array $trays = [];
    /** @var array<string, \Closure> global shortcuts: accelerator -> callback */
    private array $shortcuts = [];
    /** @var array<int, Task> dialogs waiting for their answer, by id */
    private array $dialogs = [];
    private int $nextId = 1;
    private bool $running = false;
    private bool $quitting = false;
    private int $exitCode = 0;
    private int $frame = 0;
    private float $time = 0.0;
    private float $lastTime = 0.0;
    private ?\Throwable $crash = null;
    private ?AppMain $main = null;
    private ?Updater $updater = null;
    private ?Tasks $tasks = null;
    private ?Http $http = null;
    private ?JsBridge $bridge = null;
    /** JUMPHP_TAURI_TRACE=1: every command and event on stderr */
    private bool $trace = false;

    public function __construct(public readonly GameConfig $config, private readonly NativeRuntime $runtime)
    {
        $this->gameLoop = new GameLoop();
        $this->trace = (bool)Platform::setting('JUMPHP_TAURI_TRACE', false);
        $this->storage = Storage::fromConfig($config);
        $this->options = new Options($this->storage);
        $this->lang = Lang::fromConfig($config);
        $this->lang->setLang((string)$this->options->get('lang', $config->lang()));
        self::$current = $this;
        if ($this->option('native_commands', true)) {
            NativeCommands::register($this);
        }
    }

    /**
     * The app of the folder $root: its config, the native runtime (FFI, else an error saying what is missing),
     * its main window, then its main class (game.yaml main) to register commands and listeners.
     */
    public static function boot(string $root, ?NativeRuntime $runtime = null): self
    {
        $root = rtrim($root, '/');
        if (!defined('JUMPHP_ROOT')) {
            define('JUMPHP_ROOT', $root);
        }
        if (Platform::setting('JUMPHP_SMOKE') !== null) {
            set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $level)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $level, $file, $line);
            });
        }
        $config = GameConfig::load($root);
        $main = $config->get('main');
        // the command line first (AppCli): an exit code stops here, before any window or native library
        if ($runtime === null && is_string($main) && is_subclass_of($main, AppCli::class)) {
            $code = $main::cli(array_values(array_slice((array)($_SERVER['argv'] ?? []), 1)), $root);
            if ($code !== null) {
                exit($code);
            }
        }
        $runtime ??= TauriRuntime::create($root, $config->title()) ?? throw new \RuntimeException(self::missingRuntime($root));
        $app = new self($config, $runtime);
        $window = (array)$app->option('window', []);
        $start = $app->autostartPolicy();
        if ($start['hidden'] && $app->autostart()->startedAtLogin()) {
            $window['visible'] = false;      // started with the computer: in the tray until the user opens it
        }
        $app->createWindow('main', $window + ['title' => $config->title()]);
        // game.yaml app.autostart: true: on by default, once (the first start of the exported app); the user decides then
        // installed by an installer that asked the user (its "start with the computer" box): that choice stands
        $exe = $app->updater()->executable();
        $asked = $exe !== null && (is_file(dirname($exe) . '/.jumphp-autostart') || is_file(dirname($exe, 4) . '/.jumphp-autostart'));
        if ($start['default'] && !$asked && $app->options->get('autostart_set') === null && $app->autostart()->isSupported()) {
            $app->autostart()->enable();
            $app->options->set('autostart_set', true);
        }
        if ($main !== null) {
            if (!class_exists((string)$main)) {
                throw new \RuntimeException("game.main: class '$main' not found (namespace / file name?)");
            }
            $object = new $main();
            if (!$object instanceof AppMain) {
                throw new \RuntimeException("game.main: $main must implement " . AppMain::class);
            }
            $app->main = $object;
            $object->boot($app);
        }
        return $app;
    }

    /** The object of game.yaml `main` (tests: its state), null without one. */
    public function main(): ?AppMain
    {
        return $this->main;
    }

    /** The running app (for code that has no access to it: JumPHP\Native). */
    public static function current(): ?self
    {
        return self::$current;
    }

    /** @internal tests: no running app any more */
    public static function forgetCurrent(): void
    {
        self::$current = null;
    }

    /** A key of game.yaml's app: section. */
    public function option(string $key, mixed $default = null): mixed
    {
        return ((array)$this->config->get('app', []))[$key] ?? $default;
    }

    /** Runs until the app quits. @return int exit code */
    /** The page's bridge (browser: the one BrowserRuntime reads, so HTTP answers and the app's events both arrive). */
    private function bridge(): JsBridge
    {
        return $this->bridge ??= $this->runtime instanceof BrowserRuntime ? $this->runtime->bridge : new JsBridge();
    }

    /**
     * In a web page (BrowserRuntime): starts the app without blocking; the page then calls frame() at each of
     * its frames (engine/bootstrap/wasm.php). Desktop: run().
     */
    public function start(): void
    {
        $this->running = true;
        $this->lastTime = Time::now();
        $this->runtime->run($this->runtimeConfig(), fn () => $this->frame());
    }

    /** In a web page: the page's events between two frames (the tab hidden, the page closing). */
    public function events(): void
    {
        try {
            foreach ($this->runtime->poll() as $event) {
                $this->dispatch($event);
            }
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    /** @return array<string, mixed> what the native side starts with */
    private function runtimeConfig(): array
    {
        return [
            'identifier' => (string)$this->option('identifier', 'net.jumphp.' . preg_replace('/[^a-z0-9]+/', '', strtolower($this->config->id()))),
            'product_name' => $this->config->title(),
            'version' => (string)$this->config->get('version', '0.1.0'),
            'root' => $this->pagesDir(),
            'tick_hz' => (float)$this->option('tick_hz', 60),
            'engine_pages' => dirname(__DIR__, 2) . '/web/tauri',     // served as /__jumphp/ (the embedded game's page)
            'title' => $this->config->title(),
        ];
    }

    public function run(): int
    {
        $this->running = true;
        $this->lastTime = Time::now();
        $code = $this->runtime->run($this->runtimeConfig(), fn () => $this->frame());
        $this->running = false;
        $this->tasks?->shutdown();
        Loop::stop();   // no ReactPHP auto-run at the end of the script
        self::$current = $this->runtime instanceof FakeRuntime ? self::$current : null;
        if ($this->crash !== null) {
            return 1;
        }
        return $code < 0 ? 1 : ($this->exitCode ?: $code);
    }

    /** One frame (called by the native runtime): its events, one turn of the loop, the 'frame' listeners. */
    public function frame(): void
    {
        if ($this->quitting && $this->crash !== null) {
            return;
        }
        try {
            $now = Time::now();
            $dt = min(max($now - $this->lastTime, 0.0), 0.25);
            $this->lastTime = $now;
            $this->time += $dt;
            foreach ($this->runtime->poll() as $event) {
                $this->dispatch($event);
            }
            $this->tasks?->poll();
            $this->http?->poll();
            $this->emit('frame', [$dt]);
            $this->gameLoop->start(1);
            $this->frame++;
            $this->checkHeadless();
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    // --- windows --------------------------------------------------------------------------------------------

    /** The main window (or another one by its label). */
    public function window(string $label = 'main'): Window
    {
        return $this->windows[$label] ?? throw new \OutOfBoundsException("no window '$label'");
    }

    /** @return array<string, Window> */
    public function windows(): array
    {
        return $this->windows;
    }

    /**
     * A new window. Options: title, url (a page of the app's pages folder, default index.html, or http(s)://...),
     * width, height (logical pixels), min_width, min_height, x, y, center, resizable, fullscreen, maximized,
     * decorations, always_on_top, visible, devtools, icon (a PNG of the app).
     */
    public function createWindow(string $label, array $options = []): Window
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $label)) {
            throw new \InvalidArgumentException("window label '$label': letters, digits, - and _ only");
        }
        $window = $this->windows[$label] = new Window($this, $label);
        $icon = $options['icon'] ?? $this->option('icon', is_file($this->config->path('assets/icon.png')) ? 'assets/icon.png' : null);
        if ($icon !== null) {
            $options['icon'] = Platform::isAbsolutePath((string)$icon) ? $icon : $this->config->path((string)$icon);
        }
        $options['data_directory'] ??= $this->storage->path('webview');
        $this->sendCommand(['cmd' => 'window.create', 'window' => $label] + $options);
        return $window;
    }

    // --- page <-> PHP -----------------------------------------------------------------------------------------

    /**
     * What a page can call: jumphp.invoke($name, payload) resolves with the handler's return value (any JSON
     * value; a Task: its result when it ends), rejects with the message of what it throws.
     * $handler: fn (mixed $payload, Invocation $call): mixed
     */
    public function command(string $name, callable $handler): self
    {
        $this->commands[$name] = \Closure::fromCallable($handler);
        return $this;
    }

    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /** PHP -> every page: jumphp.on($name, fn (payload)). */
    public function send(string $name, mixed $payload = null): self
    {
        $this->sendCommand(['cmd' => 'webview.emit', 'name' => $name, 'payload' => $payload]);
        return $this;
    }

    // --- tray icons, global shortcuts ---------------------------------------------------------------------------

    /**
     * An icon in the system's tray (notification area), with a menu: ['icon' => 'assets/icon.png' (default: the
     * app's), 'tooltip' => '...', 'menu' => [['id' => 'show', 'text' => 'Show'], '-', ['id' => 'quit', 'text' => 'Quit']]].
     * Its events: 'menu' (string $itemId), 'click' (string $button: not sent on Linux, where the menu is the way).
     * Linux needs libayatana-appindicator3 (and a desktop that shows tray icons).
     */
    /**
     * The application's menu (Menu: items, roles, check items): on macOS the menu bar, elsewhere the menu of every
     * window that has none of its own. Clicks: $app->on('menu', fn (string $item, string $window, ?bool $checked)).
     */
    public function setMenu(array $menu): self
    {
        $this->sendCommand(['cmd' => 'menu.set', 'window' => '*', 'menu' => Menu::normalize($menu)]);
        return $this;
    }

    public function removeMenu(): self
    {
        $this->sendCommand(['cmd' => 'menu.remove', 'window' => '*']);
        return $this;
    }

    /** Changes one item of the application's menu: ['enabled' => bool, 'checked' => bool, 'text' => string]. */
    public function setMenuItem(string $item, array $changes): self
    {
        $this->sendCommand(['cmd' => 'menu.set_item', 'window' => '*', 'item' => $item] + array_intersect_key($changes, ['enabled' => 1, 'checked' => 1, 'text' => 1]));
        return $this;
    }

    /**
     * Starting with the computer (an exported app; docs/tauri.md "Starting with the computer"): isSupported(),
     * isEnabled(), set($on), startedAtLogin().
     */
    public function autostart(): Autostart
    {
        return $this->autostart ??= new Autostart($this->config->id(), $this->config->title(), (string)$this->runtimeConfig()['identifier'],
            $this->updater()->executable(), PHP_OS_FAMILY, null, array_values(array_map('strval', (array)($_SERVER['argv'] ?? []))));
    }

    /** @return array{default: bool, hidden: bool} game.yaml app.autostart: true, or {default: bool, hidden: bool} */
    private function autostartPolicy(): array
    {
        $o = $this->option('autostart', false);
        return is_array($o) ? ['default' => (bool)($o['default'] ?? false), 'hidden' => (bool)($o['hidden'] ?? false)]
            : ['default' => (bool)$o, 'hidden' => false];
    }

    /** The app's updates (an exported app, game.yaml app.update.url; docs/tauri.md "Updates"). */
    public function updater(): Updater
    {
        return $this->updater ??= new Updater($this);
    }

    public function tray(string $id = 'main', array $options = []): Tray
    {
        $tray = $this->trays[$id] ??= new Tray($this, $id);
        $tray->setUp($options);
        return $tray;
    }

    /** @internal Tray */
    public function forgetTray(string $id): void
    {
        unset($this->trays[$id]);
    }

    /**
     * A keyboard shortcut of the whole system (works while the app has no focus): 'CmdOrCtrl+Shift+K', 'Alt+F2'...
     * $callback(string $accelerator) when pressed. Linux: X11 only (not Wayland).
     */
    public function globalShortcut(string $accelerator, callable $callback): self
    {
        $this->shortcuts[$accelerator] = \Closure::fromCallable($callback);
        $this->sendCommand(['cmd' => 'shortcut.register', 'accelerator' => $accelerator]);
        return $this;
    }

    public function removeGlobalShortcut(string $accelerator): self
    {
        unset($this->shortcuts[$accelerator]);
        $this->sendCommand(['cmd' => 'shortcut.unregister', 'accelerator' => $accelerator]);
        return $this;
    }

    // --- the app ----------------------------------------------------------------------------------------------

    /** Quits after this frame (closes every window). */
    public function quit(int $code = 0): void
    {
        if ($this->quitting) {
            return;
        }
        $this->quitting = true;
        $this->exitCode = $code;
        $this->emit('quit');
        $this->sendCommand(['cmd' => 'app.exit', 'code' => $code]);
    }

    public function isRunning(): bool
    {
        return $this->running && !$this->quitting;
    }

    /** Seconds since the app started. */
    public function time(): float
    {
        return $this->time;
    }

    public function frameCount(): int
    {
        return $this->frame;
    }

    /** Frames a second the runtime calls frame() when nothing happens (default game.yaml app.tick_hz, 60). */
    public function setTickRate(float $hz): void
    {
        $this->sendCommand(['cmd' => 'app.tick', 'hz' => $hz]);
    }

    /** Background work, as in games (threads with the async runtime, between frames otherwise). */
    public function tasks(): Tasks
    {
        return $this->tasks ??= new Tasks($this->config);
    }

    /** Asynchronous HTTP (curl), as in games. */
    public function http(): Http
    {
        return $this->http ??= new Http($this->bridge());
    }

    /** The folder of the pages (game.yaml app.pages, default ui/). */
    public function pagesDir(): string
    {
        $pages = (string)$this->option('pages', 'ui');
        return Platform::isAbsolutePath($pages) ? $pages : $this->config->path($pages);
    }

    public function isSmoke(): bool
    {
        return Platform::setting('JUMPHP_SMOKE') !== null;
    }

    /** @internal the runtime that runs this app */
    public function runtime(): NativeRuntime
    {
        return $this->runtime;
    }

    /** @internal Window, JumPHP\Native: one command for the native side */
    public function sendCommand(array $command): void
    {
        if ($this->trace) {
            self::stderr('[tauri] -> ' . json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        $this->runtime->send($command);
    }

    /**
     * @internal JumPHP\Native: a native dialog; the Task ends with its answer (true / false, a path, a list of
     * paths, or null when cancelled).
     */
    public function dialog(string $cmd, array $args): Task
    {
        return $this->request($cmd, $args);
    }

    /**
     * @internal a command whose answer comes back later (dialogs, clipboard.read): the Task ends with it, or fails
     * with what the native side said
     */
    public function request(string $cmd, array $args = []): Task
    {
        $id = $this->nextId++;
        $task = $this->dialogs[$id] = new Task();
        $this->sendCommand(['cmd' => $cmd, 'id' => $id] + $args);
        return $task;
    }

    // --- events from the native side --------------------------------------------------------------------------

    private function dispatch(array $event): void
    {
        if ($this->trace) {
            self::stderr('[tauri] <- ' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        $window = isset($event['window']) ? ($this->windows[(string)$event['window']] ?? null) : null;
        switch ($event['type'] ?? '') {
            case 'ready':
                $this->emit('ready');
                break;
            case 'invoke':
                $this->invoke(new Invocation((int)($event['id'] ?? 0), (string)($event['name'] ?? ''), $event['payload'] ?? null,
                    (string)($event['window'] ?? 'main')));
                break;
            case 'page':
                $name = (string)($event['name'] ?? '');
                if ($name === '__loaded') {
                    $window?->dispatch('loaded', []);
                    $this->emit('loaded', [(string)($event['window'] ?? 'main')]);
                } else {
                    $this->emit('page:' . $name, [$event['payload'] ?? null, (string)($event['window'] ?? 'main')]);
                }
                break;
            case 'window':
                $window?->dispatch((string)($event['event'] ?? ''), $event);
                if (($event['event'] ?? '') === 'destroyed') {
                    unset($this->windows[(string)$event['window']]);
                }
                break;
            case 'dialog':
            case 'result':
                $task = $this->dialogs[(int)($event['id'] ?? 0)] ?? null;
                unset($this->dialogs[(int)($event['id'] ?? 0)]);
                isset($event['error'])
                    ? $task?->settle(null, new \RuntimeException((string)$event['error']))
                    : $task?->settle($event['result'] ?? null);
                break;
            case 'tray':
                $tray = $this->trays[(string)($event['tray'] ?? '')] ?? null;
                if (($event['event'] ?? '') === 'menu') {
                    $tray?->emit('menu', [(string)($event['item'] ?? '')]);
                } else {
                    $tray?->emit('click', [(string)($event['button'] ?? 'left')]);
                }
                break;
            case 'menu':
                $item = (string)($event['item'] ?? '');
                $checked = isset($event['checked']) ? (bool)$event['checked'] : null;
                $window?->emit('menu', [$item, $checked]);
                $this->emit('menu', [$item, (string)($event['window'] ?? '*'), $checked]);
                break;
            case 'shortcut':
                $accel = (string)($event['accelerator'] ?? '');
                $callback = $this->shortcuts[$accel] ?? null;
                if ($callback !== null && ($event['state'] ?? 'pressed') === 'pressed') {
                    $callback($accel);
                }
                $this->emit('shortcut', [$accel, (string)($event['state'] ?? 'pressed')]);
                break;
            case 'exit-requested':
                $e = new NativeEvent('exit-requested');
                $this->emit('exit-requested', [$e]);
                if (!$e->isDefaultPrevented()) {
                    $this->quit($this->exitCode);
                }
                break;
            case 'error':
                $message = (string)($event['message'] ?? 'native error') . (empty($event['cmd']) ? '' : " ({$event['cmd']})");
                if ($this->listeners('error') === []) {
                    self::stderr("[jumphp] native: $message\n");
                }
                $this->emit('error', [$message]);
                break;
            default:
                $this->emit('native', [$event]);
        }
    }

    private function invoke(Invocation $call): void
    {
        $reply = function (bool $ok, mixed $result) use ($call): void {
            $this->sendCommand(['cmd' => 'invoke.resolve', 'window' => $call->window, 'id' => $call->id, 'ok' => $ok, 'result' => $result]);
        };
        $failed = static fn (\Throwable $e): array => ['message' => $e->getMessage(), 'class' => get_class($e)];
        $handler = $this->commands[$call->name] ?? null;
        if ($handler === null) {
            $reply(false, ['message' => "no command '{$call->name}' (App::command())", 'class' => 'BadMethodCallException']);
            return;
        }
        try {
            $result = $handler($call->payload, $call);
        } catch (\Throwable $e) {
            if ($this->isSmoke()) {
                throw $e;
            }
            $reply(false, $failed($e));
            return;
        }
        if ($result instanceof Task) {
            $result->then(static fn (mixed $value) => $reply(true, $value), static fn (\Throwable $e) => $reply(false, $failed($e)));
        } else {
            $reply(true, $result);
        }
    }

    // --- headless runs (bin/jumphp app:test / app:shot) -------------------------------------------------------

    private function checkHeadless(): void
    {
        if (!Platform::isHeadless() || $this->quitting) {
            return;
        }
        $shot = Platform::setting('JUMPHP_SCREENSHOT');
        $max = (int)Platform::setting('JUMPHP_MAX_FRAMES', 300);
        if ($this->frame < $max) {
            return;
        }
        if ($shot !== null) {
            // the screen of the X server the app runs on (Xvfb): ImageMagick's import
            @mkdir(dirname((string)$shot), 0777, true);
            $g = isset($this->windows['main']) ? $this->windows['main']->geometry() : ['width' => 0];
            $crop = $g['width'] > 0 ? sprintf(' -crop %dx%d+%d+%d +repage', $g['width'], $g['height'], $g['x'], $g['y']) : '';
            exec('import -window root' . $crop . ' ' . escapeshellarg((string)$shot) . ' 2>&1', $out, $status);
            self::stderr($status === 0 ? "[jumphp] screenshot: $shot (frame {$this->frame})\n" : "[jumphp] screenshot failed: " . implode("\n", $out) . "\n");
        }
        if ($this->isSmoke() && $this->option('smoke_page', true) && isset($this->windows['main']) && !$this->windows['main']->isLoaded()) {
            $this->crashed(new \RuntimeException("the main window's page never loaded in {$this->frame} frames (" . $this->pagesDir() . ')'));
            return;
        }
        self::stderr("[jumphp] smoke test OK: {$this->frame} frames, " . count($this->windows) . " window(s)\n");
        $this->quit(0);
    }

    private function crashed(\Throwable $e): void
    {
        $this->crash = $e;
        self::stderr("[jumphp] CRASH: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString() . "\n");
        // the app's last word (a crash report saved for its next start): nothing it does may hide the crash
        try {
            $this->emit('crash', [$e]);
        } catch (\Throwable) {
        }
        $this->quit(1);
    }

    private static function stderr(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : @fopen('php://stderr', 'w');
        if ($stream) {
            fwrite($stream, $message);
        }
    }

    private static function missingRuntime(string $root): string
    {
        if (Platform::isWasm()) {
            return 'Desktop apps (JumPHP\Tauri) are native only: they do not run in the browser';
        }
        if (!extension_loaded('ffi')) {
            return "PHP's FFI extension is missing: run the app with bin/jumphp app:run (the jumphp/tauri image has it)";
        }
        return 'No Tauri runtime (' . TauriRuntime::platform() . '/' . TauriRuntime::fileName() . '): bin/jumphp tauri:build, or JUMPHP_TAURI_LIB=<file>'
            . " (looked from $root)";
    }
}
