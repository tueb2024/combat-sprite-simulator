<?php

namespace JumPHP\Device;

use Evenement\EventEmitter;
use JumPHP\Kernel\Engine;
use JumPHP\Ui\MenuScene;

/**
 * The life of the page / window, the same events on both targets:
 *
 *   'hide' / 'show'        tab hidden or app in the background (browser), window minimized (desktop)
 *   'blur' / 'focus'       the game lost / got the keyboard focus
 *   'online' / 'offline'   network connection (browser; the desktop is always online)
 *   'resize' (int $w, int $h)          size of the page / window (CSS pixels in the browser)
 *   'orientation' (string $o)          'landscape' or 'portrait' (phones turned)
 *   'fullscreen' (bool $on)
 *   'close'                the page is going away / the game quits: save NOW (the browser may not run
 *                          another frame; the page writes var/ to IndexedDB right after this event)
 *
 * In the browser 'hide' and 'close' run at once, even between frames (the page calls PHP directly), so a
 * save made there is kept.
 *
 *   $this->engine->lifecycle()->on('hide', fn () => $this->engine->storage->writeJson('save.json', $this->state));
 *
 * Automatic behaviour (game.yaml):
 *   pause_scene: App\Scene\PauseScene   pushed when the game is hidden or loses the focus (not over a menu,
 *                                       not twice); `pause_on_blur: false` = only when hidden
 *   mute_when_hidden: true              no sound while hidden (default true)
 */
class Lifecycle extends EventEmitter
{
    private bool $visible = true;
    private bool $focused = true;
    private bool $online = true;
    private bool $fullscreen = false;
    private string $orientation = 'landscape';
    /** @var array{0:int,1:int} */
    private array $size = [0, 0];
    private bool $mutedByUs = false;
    private bool $closed = false;

    public function __construct(private ?Engine $engine = null)
    {
    }

    /** @internal Engine::boot(): page events, window events, automatic pause / mute */
    public function attach(Engine $engine): void
    {
        $this->engine = $engine;
        $engine->bridge->on('page', fn (array $e) => $this->pageEvent($e));
        $engine->input->on('window', fn (int $event, int $a = 0, int $b = 0) => $this->windowEvent($event, $a, $b));
        $engine->on('quit', fn () => $this->close());
        [$w, $h] = [$engine->config->width() * (int)$engine->config->get('scale'), $engine->config->height() * (int)$engine->config->get('scale')];
        $this->size = [$w, $h];
        $this->orientation = $w >= $h ? 'landscape' : 'portrait';

        $this->on('hide', fn () => $this->autoPause(true));
        $this->on('blur', fn () => $this->autoPause(false));
        $this->on('show', fn () => $this->unmute());
    }

    public function isVisible(): bool { return $this->visible; }
    public function hasFocus(): bool { return $this->focused; }
    public function isOnline(): bool { return $this->online; }
    public function isFullscreen(): bool { return $this->fullscreen; }
    /** 'landscape' or 'portrait' (of the screen in the browser, of the window on desktop) */
    public function orientation(): string { return $this->orientation; }
    /** @return array{0:int,1:int} page (CSS pixels) or window size */
    public function size(): array { return $this->size; }

    /** @internal the page (JsBridge 'page' events): {e: 'hide'|'show'|..., w, h, o, on} */
    public function pageEvent(array $e): void
    {
        switch ($e['e'] ?? '') {
            case 'hide': $this->setVisible(false); break;
            case 'show': $this->setVisible(true); break;
            case 'blur': $this->setFocus(false); break;
            case 'focus': $this->setFocus(true); break;
            case 'online': $this->setOnline(true); break;
            case 'offline': $this->setOnline(false); break;
            case 'close': $this->close(); break;
            case 'fullscreen':
                $on = (bool)($e['on'] ?? false);
                if ($on !== $this->fullscreen) {
                    $this->fullscreen = $on;
                    $this->emit('fullscreen', [$on]);
                }
                break;
            case 'resize':
                $this->resized((int)($e['w'] ?? 0), (int)($e['h'] ?? 0), isset($e['o']) ? (string)$e['o'] : null);
                break;
            case 'state': // first message of the page: the current state, without events
                $this->visible = (bool)($e['visible'] ?? true);
                $this->focused = (bool)($e['focus'] ?? true);
                $this->online = (bool)($e['online'] ?? true);
                $this->size = [(int)($e['w'] ?? 0), (int)($e['h'] ?? 0)];
                $this->orientation = (string)($e['o'] ?? $this->orientation);
                break;
        }
    }

    /** @internal desktop: SDL_WINDOWEVENT (InputManager 'window') */
    public function windowEvent(int $event, int $a = 0, int $b = 0): void
    {
        if (\JumPHP\Kernel\Platform::isHeadless()) {
            return;         // headless OpenGL runs have a window on Xvfb: never focused, nobody to pause for
        }
        switch ($event) {
            case \SDL_WINDOWEVENT_MINIMIZED:
            case \SDL_WINDOWEVENT_HIDDEN:
                $this->setVisible(false);
                break;
            case \SDL_WINDOWEVENT_RESTORED:
            case \SDL_WINDOWEVENT_SHOWN:
            case \SDL_WINDOWEVENT_MAXIMIZED:
                $this->setVisible(true);
                break;
            case \SDL_WINDOWEVENT_FOCUS_LOST:
                $this->setFocus(false);
                break;
            case \SDL_WINDOWEVENT_FOCUS_GAINED:
                $this->setFocus(true);
                break;
            case \SDL_WINDOWEVENT_SIZE_CHANGED:
                $this->resized($a, $b, null);
                break;
        }
    }

    private function setVisible(bool $on): void
    {
        if ($on === $this->visible) {
            return;
        }
        $this->visible = $on;
        $this->emit($on ? 'show' : 'hide');
    }

    private function setFocus(bool $on): void
    {
        if ($on === $this->focused) {
            return;
        }
        $this->focused = $on;
        $this->emit($on ? 'focus' : 'blur');
    }

    private function setOnline(bool $on): void
    {
        if ($on === $this->online) {
            return;
        }
        $this->online = $on;
        $this->emit($on ? 'online' : 'offline');
    }

    private function resized(int $w, int $h, ?string $orientation): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $this->size = [$w, $h];
        $this->emit('resize', [$w, $h]);
        $orientation ??= $w >= $h ? 'landscape' : 'portrait';
        if ($orientation !== $this->orientation) {
            $this->orientation = $orientation;
            $this->emit('orientation', [$orientation]);
        }
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->emit('close');
        // the page may come back (bfcache, cancelled unload): the next 'close' is a new one
        $this->engine?->gameLoop->addTimer(0.5, function (): void {
            $this->closed = false;
        });
    }

    private function autoPause(bool $hidden): void
    {
        if ($this->engine === null) {
            return;
        }
        $config = $this->engine->config;
        if ($hidden && $config->get('mute_when_hidden', true) && !$this->engine->sound->isMuted()) {
            $this->engine->sound->setMuted(true);
            $this->mutedByUs = true;
        }
        $pause = $config->get('pause_scene');
        if (!$pause || (!$hidden && !$config->get('pause_on_blur', true))) {
            return;
        }
        $current = $this->engine->scenes->current();
        if ($current === null || $current instanceof $pause || $current->isOverlay() || $current instanceof MenuScene
            || $this->engine->scenes->isTransitioning()) {
            return;
        }
        $this->engine->scenes->push((string)$pause);
    }

    private function unmute(): void
    {
        if ($this->mutedByUs && $this->engine !== null) {
            $this->engine->sound->setMuted(false);
        }
        $this->mutedByUs = false;
    }
}
