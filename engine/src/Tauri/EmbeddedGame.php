<?php

namespace JumPHP\Tauri;

use JumPHP\Kernel\Engine;
use JumPHP\Kernel\GameConfig;

/**
 * A hybrid game in ONE window (game.yaml `ui: hybrid` + `app: {embed: true}`): SDL draws into memory (no SDL
 * window: Sdl::initSDL() sees Hybrid::embedded()), each picture goes to the Tauri runtime (NativeRuntime::picture(),
 * a BMP), and JumPHP's page (engine/web/tauri/game.html, served as /__jumphp/game.html) draws it on a canvas, sharp,
 * letterboxed, and sends the keyboard, the mouse and the wheel back here, into the engine's InputManager.
 * Gamepads are read by SDL as usual. HTML around the game: the app's own pages in other windows, or
 * $app->window()->eval() / send() into this one.
 */
final class EmbeddedGame
{
    /** browser KeyboardEvent.key (or .code) -> SDL key name (SDL_GetKeyFromName) */
    private const NAMES = [
        'ArrowLeft' => 'Left', 'ArrowRight' => 'Right', 'ArrowUp' => 'Up', 'ArrowDown' => 'Down',
        'Enter' => 'Return', ' ' => 'Space', 'Escape' => 'Escape', 'Backspace' => 'Backspace', 'Tab' => 'Tab',
        'Delete' => 'Delete', 'Insert' => 'Insert', 'Home' => 'Home', 'End' => 'End', 'PageUp' => 'PageUp',
        'PageDown' => 'PageDown', 'CapsLock' => 'CapsLock', 'Pause' => 'Pause',
        'ShiftLeft' => 'Left Shift', 'ShiftRight' => 'Right Shift', 'ControlLeft' => 'Left Ctrl',
        'ControlRight' => 'Right Ctrl', 'AltLeft' => 'Left Alt', 'AltRight' => 'Right Alt', 'MetaLeft' => 'Left GUI',
        'MetaRight' => 'Right GUI', 'NumpadEnter' => 'Keypad Enter', 'NumpadAdd' => 'Keypad +',
        'NumpadSubtract' => 'Keypad -', 'NumpadMultiply' => 'Keypad *', 'NumpadDivide' => 'Keypad /',
        'NumpadDecimal' => 'Keypad .',
    ];

    private int $pictures = 0;
    private bool $fullscreen = false;

    public function __construct(private readonly App $app, private readonly Engine $engine, private readonly GameConfig $config)
    {
    }

    /** Hybrid::run(): the window, the page's commands and events, a picture after each frame. */
    public function start(): void
    {
        $scale = max(1, (int)$this->config->get('scale', 3));
        $this->fullscreen = (bool)$this->config->get('fullscreen', false);
        $width = (int)$this->config->get('width', 320);
        $height = (int)$this->config->get('height', 180);
        $window = (array)($this->config->get('app', [])['window'] ?? []);
        $this->app->createWindow('main', ['url' => '__jumphp/game.html'] + $window + [
            'title' => $this->config->title(),
            'width' => $width * $scale,
            'height' => $height * $scale,
            'min_width' => $width,
            'min_height' => $height,
            'fullscreen' => (bool)$this->config->get('fullscreen', false),
        ]);
        $this->app->command('__embed.info', fn (): array => [
            'width' => $width,
            'height' => $height,
            'background' => $this->config->get('background', [0, 0, 0]),
            'integer_scale' => (bool)$this->config->get('integer_scale', false),
        ]);
        $this->app->on('page:__input', function (mixed $event): void {
            if (is_array($event)) {
                $this->input($event);
            }
        });
        // the game ends with its window
        $this->app->window()->on('closed', fn () => $this->engine->quit());
        $this->engine->on('rendered', function (): void {
            $bmp = $this->engine->sdl->embeddedPicture();
            if ($bmp !== null) {
                $this->app->runtime()->picture($bmp);
                $this->pictures++;
            }
        });
        // F11 (the engine's fullscreen key) has no SDL window to change: the Tauri one
        $this->engine->on('fullscreen-request', function (): void {
            $this->fullscreen = !$this->fullscreen;
            $this->app->window()->setFullscreen($this->fullscreen);
        });
    }

    public function pictures(): int
    {
        return $this->pictures;
    }

    /** One event of the page: {t: 'down' | 'up', key, code} | {t: 'move', x, y} | {t: 'mdown' | 'mup', b} | {t: 'blur'} */
    public function input(array $e): void
    {
        $input = $this->engine->input;
        switch ($e['t'] ?? '') {
            case 'down':
                $key = self::sdlKey((string)($e['key'] ?? ''), (string)($e['code'] ?? ''));
                if ($key !== 0 && !$input->getKeyboard()->isKeyHeld($key)) {
                    $input->pressKey($key);
                }
                break;
            case 'up':
                $key = self::sdlKey((string)($e['key'] ?? ''), (string)($e['code'] ?? ''));
                if ($key !== 0) {
                    $input->getKeyboard()->up($key);
                }
                break;
            case 'move':
                $input->getMouse()->move((float)($e['x'] ?? 0), (float)($e['y'] ?? 0));
                break;
            case 'mdown':
                $input->getMouse()->down((int)($e['b'] ?? 1));
                break;
            case 'mup':
                $input->getMouse()->up((int)($e['b'] ?? 1));
                break;
            case 'blur':
                // the window lost the focus: no key stays held
                $input->getKeyboard()->releaseAll();
                break;
        }
    }

    /** A browser key (KeyboardEvent.key, .code) as an SDL key code (0: unknown). */
    public static function sdlKey(string $key, string $code): int
    {
        $name = self::NAMES[$code] ?? self::NAMES[$key] ?? null;
        if ($name === null && preg_match('/^F([1-9]|1[0-2])$/', $key)) {
            $name = $key;
        }
        if ($name === null && preg_match('/^Numpad([0-9])$/', $code, $m)) {
            $name = 'Keypad ' . $m[1];
        }
        if ($name === null && in_array($key, ['Shift', 'Control', 'Alt', 'Meta'], true)) {
            $name = ['Shift' => 'Left Shift', 'Control' => 'Left Ctrl', 'Alt' => 'Left Alt', 'Meta' => 'Left GUI'][$key];
        }
        if ($name !== null) {
            return function_exists('SDL_GetKeyFromName') ? (int)\SDL_GetKeyFromName($name) : 0;
        }
        // a character: SDL's key codes of printable keys are their (lower case) character
        if (mb_strlen($key) === 1) {
            $char = mb_strtolower($key);
            return strlen($char) === 1 ? ord($char) : (int)mb_ord($char);
        }
        return 0;
    }
}
