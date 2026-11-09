<?php

namespace JumPHP\Input;

use Evenement\EventEmitter;
use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\Platform;

/**
 * Gamepads, with the same button names everywhere (the "standard" layout of the W3C Gamepad API, Xbox names):
 *
 *   a b x y          face buttons (bottom, right, left, top: PlayStation cross circle square triangle)
 *   lb rb lt rt      shoulders and triggers;   select start home;   ls rs (stick clicks)
 *   up down left right   d-pad (in actions: d-pad OR left stick)
 *   ls_left ls_right ls_up ls_down / rs_...   stick directions only
 *
 * In config/input.yaml: `pad0:a`, `pad0:start`, `pad1:left`, `pad0:rt`... (raw numbers still work:
 * `pad0:button3`). Analog values and the pads themselves:
 *
 *   $pad = $this->engine->input->getGamepad();
 *   $pad->axis(0, 'lx');           // -1..1 (dead zone applied): lx ly rx ry; triggers lt rt 0..1
 *   $pad->isButtonHeld(0, 'a');    $pad->count();   $pad->name(0);   $pad->isConnected(1)
 *   $pad->rumble(0, 0.8, 0.3, 0.2); // strong, weak motor (0..1), seconds (browser: Chromium; desktop: no)
 *   $pad->on('connect', fn (int $pad, string $name) => ...);   'disconnect' (int $pad)
 *
 * Browser: the page reads navigator.getGamepads() before each frame (engine/web/jumphp.js) and sends the
 * pads' state (JsBridge 'gamepad'); pads with the "standard" mapping (nearly all) get the names above.
 * Desktop: SDL joystick events (the binding has no SDL_GameController API) translated with a table for Xbox
 * (XInput) and PlayStation pads; other pads get the Xbox table. The SDL binding gives no hat events: on desktop
 * a d-pad reported as a hat (most Xbox pads on Linux) is not seen — use the left stick (`pad0:left` reads both).
 */
class Gamepad extends EventEmitter
{
    /** Index = the W3C "standard" button number. */
    public const BUTTONS = ['a', 'b', 'x', 'y', 'lb', 'rb', 'lt', 'rt', 'select', 'start', 'ls', 'rs', 'up', 'down', 'left', 'right', 'home'];
    public const AXES = ['lx', 'ly', 'rx', 'ry'];
    public float $deadZone = 0.35;

    /** SDL joystick numbers (desktop, Linux evdev order) -> names. Axes: lx ly lt rx ry rt (triggers -1..1). */
    private const LAYOUTS = [
        'xbox' => [
            'buttons' => ['a', 'b', 'x', 'y', 'lb', 'rb', 'select', 'start', 'home', 'ls', 'rs'],
            'axes' => ['lx', 'ly', 'lt', 'rx', 'ry', 'rt'],
        ],
        'playstation' => [
            'buttons' => ['a', 'b', 'y', 'x', 'lb', 'rb', 'lt', 'rt', 'select', 'start', 'home', 'ls', 'rs'],
            'axes' => ['lx', 'ly', 'lt', 'rx', 'ry', 'rt'],
        ],
    ];

    /** @var array<int, array{name: string, layout: ?string, raw: array<int, float>, axes: array<int, float>, sdl: mixed}> */
    private array $pads = [];
    /** @var array<string, bool> */
    private array $held = [];
    /** @var array<string, bool> */
    private array $pressed = [];

    public function __construct(private ?JsBridge $bridge = null)
    {
        $bridge?->on('gamepad', fn (array $e) => $this->pageState($e));
    }

    /** Desktop: opens the joysticks SDL sees (at boot and when one is plugged in). */
    public function open(): void
    {
        if (Platform::isWasm() || !function_exists('SDL_NumJoysticks')) {
            return;
        }
        for ($i = 0, $n = \SDL_NumJoysticks(); $i < $n; $i++) {
            if (isset($this->pads[$i])) {
                continue;
            }
            $joystick = \SDL_JoystickOpen($i);
            if (!$joystick) {
                continue;
            }
            $name = (string)\SDL_JoystickName($joystick);
            $layout = preg_match('/playstation|dualshock|dualsense|sony|wireless controller|ps[345]/i', $name) ? 'playstation' : 'xbox';
            $axes = [];
            foreach (self::LAYOUTS[$layout]['axes'] as $index => $axis) {
                $axes[$index] = \SDL_JoystickGetAxis($joystick, $index) / 32767;
            }
            $this->pads[$i] = ['name' => $name, 'layout' => $layout, 'raw' => [], 'axes' => $axes, 'sdl' => $joystick];
            $this->emit('connect', [$i, $name]);
        }
    }

    /** Pads connected. */
    public function count(): int
    {
        return count($this->pads);
    }

    public function isConnected(int $pad): bool
    {
        return isset($this->pads[$pad]);
    }

    public function name(int $pad): ?string
    {
        return $this->pads[$pad]['name'] ?? null;
    }

    /**
     * Analog value: lx ly rx ry -1..1 (y down is positive; dead zone gives 0), lt rt 0..1.
     */
    public function axis(int $pad, string $axis): float
    {
        $p = $this->pads[$pad] ?? null;
        if ($p === null) {
            return 0.0;
        }
        if ($axis === 'lt' || $axis === 'rt') {
            return $this->trigger($p, $axis);
        }
        $index = $p['layout'] === null ? array_search($axis, self::AXES, true) : array_search($axis, self::LAYOUTS[$p['layout']]['axes'], true);
        $value = $index === false ? 0.0 : (float)($p['axes'][$index] ?? 0.0);
        if (abs($value) < $this->deadZone) {
            return 0.0;
        }
        // the dead zone rescaled: the stick still goes smoothly from 0 to 1
        return ($value <=> 0) * min(1.0, (abs($value) - $this->deadZone) / (1 - $this->deadZone));
    }

    /** A named button (a, start, up...) of one pad. */
    public function isButtonHeld(int $pad, string $button): bool
    {
        return isset($this->held["pad$pad:$button"]);
    }

    /** Vibration (0..1 each motor). Browser only (Chromium with a pad that has motors). */
    public function rumble(int $pad, float $strong, float $weak = 0.0, float $seconds = 0.2): bool
    {
        if (!isset($this->pads[$pad]) || $this->bridge === null) {
            return false;
        }
        return $this->bridge->send('gamepad', ['do' => 'rumble', 'pad' => $pad, 'strong' => max(0.0, min(1.0, $strong)),
            'weak' => max(0.0, min(1.0, $weak)), 'ms' => (int)round($seconds * 1000)]);
    }

    /** @internal desktop: SDL joystick events */
    public function handle($event): void
    {
        if (Platform::isWasm()) {
            return;   // the page's Gamepad API state is used instead
        }
        if ($event->type === \SDL_JOYBUTTONDOWN || $event->type === \SDL_JOYBUTTONUP) {
            $b = $event->jbutton ?? null;
            if ($b !== null && isset($this->pads[$b->which])) {
                $this->pads[$b->which]['raw'][$b->button] = $event->type === \SDL_JOYBUTTONDOWN ? 1.0 : 0.0;
            }
        } elseif ($event->type === \SDL_JOYAXISMOTION) {
            $a = $event->jaxis ?? null;
            if ($a !== null && isset($this->pads[$a->which])) {
                $this->pads[$a->which]['axes'][$a->axis] = max(-1.0, $a->value / 32767);
            }
        }
    }

    /** @internal JsBridge 'gamepad': {pads: [{i, id, std, b: [values], a: [values]}]} (only when it changed) */
    public function pageState(array $e): void
    {
        $seen = [];
        foreach ((array)($e['pads'] ?? []) as $p) {
            $i = (int)$p['i'];
            $seen[$i] = true;
            $new = !isset($this->pads[$i]);
            $this->pads[$i] = [
                'name' => (string)($p['id'] ?? 'gamepad'),
                'layout' => empty($p['std']) ? 'xbox' : null,     // not "standard": raw numbers, Xbox guess
                'raw' => array_map('floatval', (array)($p['b'] ?? [])),
                'axes' => array_map('floatval', (array)($p['a'] ?? [])),
                'sdl' => null,
            ];
            if ($new) {
                $this->emit('connect', [$i, $this->pads[$i]['name']]);
            }
        }
        foreach (array_keys($this->pads) as $i) {
            if (!isset($seen[$i])) {
                unset($this->pads[$i]);
                $this->emit('disconnect', [$i]);
            }
        }
    }

    /** @internal desktop: a joystick was unplugged (instance id) */
    public function close(int $pad): void
    {
        if (isset($this->pads[$pad])) {
            if ($this->pads[$pad]['sdl']) {
                \SDL_JoystickClose($this->pads[$pad]['sdl']);
            }
            unset($this->pads[$pad]);
            $this->emit('disconnect', [$pad]);
        }
    }

    /** @internal InputManager, once per frame after the events: held / pressed names */
    public function update(): void
    {
        $held = [];
        foreach ($this->pads as $i => $p) {
            $names = $p['layout'] === null ? self::BUTTONS : self::LAYOUTS[$p['layout']]['buttons'];
            foreach ($p['raw'] as $index => $value) {
                if ($value > 0.5) {
                    $held["pad$i:button$index"] = true;
                    if (isset($names[$index])) {
                        $held["pad$i:{$names[$index]}"] = true;
                    }
                }
            }
            if ($p['layout'] !== null) {     // desktop triggers are axes
                foreach (['lt', 'rt'] as $t) {
                    if ($this->trigger($p, $t) > 0.5) {
                        $held["pad$i:$t"] = true;
                    }
                }
            }
            foreach (['l' => ['lx', 'ly'], 'r' => ['rx', 'ry']] as $stick => [$ax, $ay]) {
                $x = $this->axis($i, $ax);
                $y = $this->axis($i, $ay);
                foreach (['left' => $x < 0, 'right' => $x > 0, 'up' => $y < 0, 'down' => $y > 0] as $dir => $on) {
                    if ($on) {
                        $held["pad$i:{$stick}s_$dir"] = true;
                    }
                }
            }
            foreach (['left', 'right', 'up', 'down'] as $dir) {
                // in actions, a direction is the d-pad or the left stick
                if (isset($held["pad$i:ls_$dir"])) {
                    $held["pad$i:$dir"] = true;
                }
            }
        }
        foreach ($held as $name => $_) {
            if (!isset($this->held[$name])) {
                $this->pressed[$name] = true;
            }
        }
        $this->held = $held;
    }

    private function trigger(array $p, string $t): float
    {
        if ($p['layout'] === null) {   // standard: buttons 6 / 7 carry the pressure
            return (float)($p['raw'][$t === 'lt' ? 6 : 7] ?? 0.0);
        }
        $index = array_search($t, self::LAYOUTS[$p['layout']]['axes'], true);
        if ($index === false || !isset($p['axes'][$index])) {
            $button = array_search($t, self::LAYOUTS[$p['layout']]['buttons'], true);
            return $button === false ? 0.0 : (float)($p['raw'][$button] ?? 0.0);
        }
        return max(0.0, min(1.0, ($p['axes'][$index] + 1) / 2));   // -1 at rest .. 1 pressed
    }

    public function isHeld(string $name): bool
    {
        return isset($this->held[$name]);
    }

    public function isPressed(string $name): bool
    {
        return isset($this->pressed[$name]);
    }

    public function anyPressed(): bool
    {
        return $this->pressed !== [];
    }

    public function resetTransientStates(): void
    {
        $this->pressed = [];
    }
}
