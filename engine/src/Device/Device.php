<?php

namespace JumPHP\Device;

use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\Engine;
use JumPHP\Kernel\Platform;

/**
 * Phone / tablet features ($this->engine->device()). Every call is safe on every target: what a device or a
 * browser does not have does nothing (and returns false / null).
 *
 *   $device->vibrate(40);                       // ms, or a pattern [on, off, on...] (Android browsers)
 *   $device->keepAwake();                       // the screen does not turn off while playing (Wake Lock)
 *   $device->lockOrientation('landscape');      // 'landscape' | 'portrait' | 'any' (fullscreen Android)
 *   $device->setFullscreen(true);               // browser: done at the next tap / key if not allowed now
 *   $device->copy('ROOM ABCD');                 // clipboard
 *   $device->share('My score: 1200');           // the phone's share sheet (else false)
 *   $device->enableMotion();                    // asks the permission (iOS) at the next tap, then:
 *   [$x, $y] = $device->tilt() ?? [0, 0];       // -1..1: phone tilted left/right, forward/back
 *   $device->battery();                         // ['level' => 0..1, 'charging' => bool] or null
 *
 * Browser: the page does the work right after the frame (engine/web/jumphp-device.js); state (tilt, battery,
 * fullscreen) comes back through JsBridge 'device' events. Desktop: fullscreen = the window, battery =
 * SDL_GetPowerInfo, keepAwake = screen saver off; no vibration, clipboard or tilt (no SDL binding).
 */
class Device
{
    /** @var array{0:float,1:float}|null */
    private ?array $tilt = null;
    /** @var array{0:float,1:float,2:float}|null */
    private ?array $motion = null;
    private ?array $battery = null;
    private bool $awake = false;
    private bool $motionRequested = false;

    public function __construct(private JsBridge $bridge, private ?Engine $engine = null)
    {
        $bridge->on('device', fn (array $e) => $this->deviceEvent($e));
    }

    public function isMobile(): bool
    {
        return Platform::isMobile();
    }

    /** The game runs as an installed app (PWA, home screen icon), not in a browser tab. */
    public function isStandalone(): bool
    {
        return (bool)Platform::setting('JUMPHP_STANDALONE', false);
    }

    /** Vibrates: milliseconds, or a pattern [vibrate, pause, vibrate...]. */
    public function vibrate(int|array $pattern): bool
    {
        $pattern = is_array($pattern) ? array_map('intval', array_values($pattern)) : [$pattern];
        return $this->bridge->send('device', ['do' => 'vibrate', 'pattern' => $pattern]);
    }

    /** Keeps the screen on (or lets it sleep again with false). */
    public function keepAwake(bool $on = true): void
    {
        $this->awake = $on;
        if (Platform::isWasm()) {
            $this->bridge->send('device', ['do' => 'awake', 'on' => $on]);
        } elseif (function_exists('SDL_DisableScreenSaver')) {
            $on ? \SDL_DisableScreenSaver() : \SDL_EnableScreenSaver();
        }
    }

    public function isKeptAwake(): bool
    {
        return $this->awake;
    }

    /** 'landscape', 'portrait' or 'any' (unlock). Browsers allow it in fullscreen only (Android). */
    public function lockOrientation(string $orientation): bool
    {
        if (!in_array($orientation, ['landscape', 'portrait', 'any'], true)) {
            throw new \InvalidArgumentException("orientation: landscape, portrait or any (not '$orientation')");
        }
        return $this->bridge->send('device', ['do' => 'orientation', 'o' => $orientation]);
    }

    /** Browser: needs a user gesture, so it may happen at the next tap / key. Desktop: the window. */
    public function setFullscreen(bool $on): void
    {
        if (Platform::isWasm()) {
            $this->bridge->send('device', ['do' => 'fullscreen', 'on' => $on]);
            return;
        }
        $window = $this->engine?->sdl->getWindow();
        if ($window !== null && $window->isFullscreen() !== $on) {
            $this->engine->toggleFullscreen();
        }
    }

    public function isFullscreen(): bool
    {
        if (Platform::isWasm()) {
            return $this->engine?->lifecycle()->isFullscreen() ?? false;
        }
        return $this->engine?->sdl->getWindow()?->isFullscreen() ?? false;
    }

    /** Text to the clipboard (browser only: the SDL binding has no clipboard). */
    public function copy(string $text): bool
    {
        return $this->bridge->send('device', ['do' => 'copy', 'text' => $text]);
    }

    /** The share sheet of the phone (Web Share API), else false. */
    public function share(string $text, ?string $url = null): bool
    {
        return $this->bridge->send('share', ['text' => $text] + ($url !== null ? ['url' => $url] : []));
    }

    /**
     * Turns on the tilt / motion sensors. iOS asks the player first: the page shows the question at the next
     * tap. Until the first reading, tilt() and motion() return null.
     */
    public function enableMotion(): bool
    {
        if ($this->motionRequested) {
            return Platform::isWasm();
        }
        $this->motionRequested = true;
        return $this->bridge->send('device', ['do' => 'motion']);
    }

    /** @return array{0:float,1:float}|null [x, y] in -1..1 (45° = 1): x > 0 tilted right, y > 0 top towards the player */
    public function tilt(): ?array
    {
        return $this->tilt;
    }

    /** @return array{0:float,1:float,2:float}|null acceleration with gravity, m/s² (shake: a big length) */
    public function motion(): ?array
    {
        return $this->motion;
    }

    /** @return array{level: float, charging: bool}|null level 0..1 */
    public function battery(): ?array
    {
        if (Platform::isDesktop() && function_exists('SDL_GetPowerInfo')) {
            $secs = $pct = 0;
            $state = (int)\SDL_GetPowerInfo($secs, $pct);
            // SDL_POWERSTATE_UNKNOWN 0, ON_BATTERY 1, NO_BATTERY 2, CHARGING 3, CHARGED 4
            if ($state === 0 || $state === 2 || $pct < 0) {
                return null;
            }
            return ['level' => $pct / 100, 'charging' => $state >= 3];
        }
        return $this->battery;
    }

    /** @internal JsBridge 'device' events: {tilt: [x, y]}, {motion: [x, y, z]}, {battery: {level, charging}} */
    public function deviceEvent(array $e): void
    {
        if (isset($e['tilt']) && is_array($e['tilt'])) {
            $this->tilt = [(float)$e['tilt'][0], (float)$e['tilt'][1]];
        }
        if (isset($e['motion']) && is_array($e['motion'])) {
            $this->motion = [(float)$e['motion'][0], (float)$e['motion'][1], (float)$e['motion'][2]];
        }
        if (isset($e['battery']) && is_array($e['battery'])) {
            $this->battery = ['level' => (float)$e['battery']['level'], 'charging' => (bool)$e['battery']['charging']];
        }
    }
}
