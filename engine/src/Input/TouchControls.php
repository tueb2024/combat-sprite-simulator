<?php

namespace JumPHP\Input;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * On-screen controls drawn by the engine itself (the native Android app: no browser, so no engine/web/touch.js):
 * the same d-pad or floating joystick and A / B / START / SELECT buttons, the same game.yaml `touch` options
 * (pad: dpad, stick or none; a, b, start, select, labels), the same places (the black bands beside the game when they are wide enough).
 * The buttons press the game's real keys; a touch elsewhere is a mouse click on the game (menus); the Android back
 * button is Escape. On with JUMPHP_TOUCH_CONTROLS=1 (the app sets it; on desktop, to try them with the mouse).
 */
class TouchControls
{
    private const IDLE = [255, 255, 255, 46];
    private const LIT = [255, 255, 255, 102];
    private const EDGE = [255, 255, 255, 115];
    private const DEAD = 18;
    private const MAX = 50;
    private const TAP = 0.35;

    /** @var array<string, string> a, b, start, select => key name */
    private array $keys;
    /** @var array<string, string> */
    private array $labels;
    private bool $stick;
    /** game.yaml `touch: {pad: none}`: no pad and no buttons, every touch is a click on the game */
    private bool $none;
    /** @var array<string, array{x: float, y: float, w: float, h: float, round: bool, key: string, label: string}> */
    private array $buttons = [];
    /** @var array{x: float, y: float, w: float, h: float} the joystick's zone */
    private array $zone = ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];
    private float $restX = 0;
    private float $restY = 0;
    private float $unit = 1;
    /** @var array<int|string, string> finger => button name, 'stick' or 'mouse' */
    private array $fingers = [];
    /** @var array<string, int> key name => how many fingers hold it */
    private array $held = [];
    /** @var array{cx: float, cy: float, x: float, y: float, t: float, moved: bool}|null */
    private ?array $stickState = null;
    /** @var array<string, bool> */
    private array $dirs = [];
    private bool $mouseUp = false;
    private int $ow = 0;
    private int $oh = 0;

    public function __construct(
        private Keyboard $keyboard,
        private Mouse $mouse,
        private InputManager $input,
        array $config,
        private ?TextDrawer $font = null,
    ) {
        $this->keys = ['a' => 'Space', 'b' => 'Escape', 'start' => 'Return'];
        foreach (['a', 'b', 'start', 'select'] as $k) {
            if (isset($config[$k]) && is_string($config[$k])) {
                $this->keys[$k] = $config[$k];
            }
        }
        $this->labels = array_merge(['a' => 'A', 'b' => 'B', 'start' => 'START', 'select' => 'SELECT'], (array)($config['labels'] ?? []));
        $this->stick = ($config['pad'] ?? null) === 'stick';
        $this->none = ($config['pad'] ?? null) === 'none';
        if (function_exists('SDL_SetHint')) {
            // the touches are ours: no mouse events made from them by SDL (a button would click the game under it)
            \SDL_SetHint('SDL_TOUCH_MOUSE_EVENTS', '0');
        }
    }

    /** Before the frame's events: the click of a tap is released one frame after it was pressed. */
    public function beforeEvents(): void
    {
        if ($this->mouseUp) {
            $this->mouse->up(1);
            $this->mouseUp = false;
        }
    }

    /** A finger event (SDL_FINGERDOWN / UP / MOTION). @return bool true: it was on the controls */
    public function handle($event, Renderer $renderer): bool
    {
        $this->layout($renderer);
        $f = $event->tfinger;
        $id = (string)$f->fingerId;
        $x = (float)$f->x * $this->ow;
        $y = (float)$f->y * $this->oh;
        $owner = $this->fingers[$id] ?? null;

        if ($event->type === \SDL_FINGERDOWN) {
            foreach ($this->buttons as $name => $b) {
                if ($this->inside($b, $x, $y)) {
                    $this->fingers[$id] = $name;
                    $this->key($b['key'], true);
                    return true;
                }
            }
            if ($this->stick && $this->stickState === null && $this->inside($this->zone, $x, $y)) {
                $this->fingers[$id] = 'stick';
                $this->stickState = ['cx' => $x, 'cy' => $y, 'x' => $x, 'y' => $y, 't' => microtime(true), 'moved' => false];
                return true;
            }
            if (!in_array('mouse', $this->fingers, true)) {
                $this->fingers[$id] = 'mouse';
                $this->moveMouse($renderer, $x, $y);
                $this->mouse->down(1);
            }
            return false;
        }

        if ($owner === null) {
            return false;
        }
        if ($event->type === \SDL_FINGERMOTION) {
            if ($owner === 'stick') {
                $this->moveStick($x, $y);
            } elseif ($owner === 'mouse') {
                $this->moveMouse($renderer, $x, $y);
            } elseif (!$this->inside($this->buttons[$owner], $x, $y)) {
                // slid off the button: released (as the browser's pointerleave)
                $this->key($this->buttons[$owner]['key'], false);
                $this->fingers[$id] = 'gone';
            }
            return $owner !== 'mouse';
        }

        // SDL_FINGERUP
        unset($this->fingers[$id]);
        if ($owner === 'stick') {
            $s = $this->stickState;
            $this->stickState = null;
            $this->setDirs([]);
            // a short tap without sliding is not for the joystick: a click on the game under it
            if ($s !== null && !$s['moved'] && microtime(true) - $s['t'] < self::TAP) {
                $this->moveMouse($renderer, $s['cx'], $s['cy']);
                $this->mouse->down(1);
                $this->mouseUp = true;
            }
            return true;
        }
        if ($owner === 'mouse') {
            $this->moveMouse($renderer, $x, $y);
            $this->mouseUp = true;
            return false;
        }
        if ($owner !== 'gone') {
            $this->key($this->buttons[$owner]['key'], false);
        }
        return true;
    }

    /** Drawn over the game, in window pixels (Renderer::addOverlay). */
    public function draw(Renderer $r): void
    {
        $this->layout($r);
        $u = $this->unit;
        if ($this->stick) {
            $s = $this->stickState;
            $cx = $s['cx'] ?? $this->restX;
            $cy = $s['cy'] ?? $this->restY;
            $r->fillCircle($cx, $cy, 60 * $u, [255, 255, 255, 20]);
            $this->ring($r, $cx, $cy, 60 * $u);
            $kx = $cx;
            $ky = $cy;
            if ($s !== null) {
                [$dx, $dy] = $this->clamp($s['x'] - $cx, $s['y'] - $cy);
                $kx += $dx;
                $ky += $dy;
            }
            $r->fillCircle($kx, $ky, 26 * $u, [255, 255, 255, 90]);
        }
        foreach ($this->buttons as $name => $b) {
            $lit = ($this->held[$b['key']] ?? 0) > 0 && in_array($name, $this->fingers, true);
            $color = $lit ? self::LIT : self::IDLE;
            if ($b['round']) {
                $r->fillCircle($b['x'] + $b['w'] / 2, $b['y'] + $b['h'] / 2, $b['w'] / 2, $color);
                $this->ring($r, $b['x'] + $b['w'] / 2, $b['y'] + $b['h'] / 2, $b['w'] / 2);
            } else {
                $r->fillRect($b['x'], $b['y'], $b['w'], $b['h'], $color);
                $r->drawRect($b['x'], $b['y'], $b['w'], $b['h'], self::EDGE, max(1, (int)round(2 * $u)));
            }
            if (in_array($name, ['left', 'right', 'up', 'down'], true)) {
                $this->arrow($r, $name, $b);
            } elseif ($this->font !== null) {
                $scale = max(1, round(($b['h'] < 40 * $u ? 1.5 : 2) * $u));
                $text = strtoupper($b['label']);
                while ($scale > 1 && $this->font->width($text, $scale) > $b['w'] - 8 * $u) {
                    $scale--;
                }
                $this->font->draw($r, $text, $b['x'] + $b['w'] / 2, $b['y'] + ($b['h'] - $this->font->height($scale)) / 2,
                    $scale, [255, 255, 255], 'center');
            }
        }
    }

    // --- layout (engine/web/touch.js: place()) -----------------------------------------------------------

    private function layout(Renderer $r): void
    {
        [$ow, $oh] = $r->outputSize();
        if ($ow === $this->ow && $oh === $this->oh && $this->buttons !== []) {
            return;
        }
        $this->ow = $ow;
        $this->oh = $oh;
        if ($this->none) {
            // no controls: the touches all go to the game as clicks
            $this->buttons = [];
            return;
        }
        // a CSS pixel of the browser version: a phone held sideways is ~400 of them high
        $u = $this->unit = max(1.0, min($ow, $oh) / 400);
        $box = fn (float $x, float $y, float $w, float $h, bool $round, string $key, string $label): array
            => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'round' => $round, 'key' => $key, 'label' => $label];
        $this->buttons = [];

        [$lx, , $lw] = $r->letterbox();
        $band = min($lx, $ow - ($lx + $lw)) / $u;
        $pills = ['start'];
        if (isset($this->keys['select'])) {
            array_unshift($pills, 'select');
        }

        if (!$this->stick) {
            $s = 56 * $u;
            $this->buttons['left'] = $box(16 * $u, $oh - (78 + 56) * $u, $s, $s, false, 'Left', '');
            $this->buttons['right'] = $box(136 * $u, $oh - (78 + 56) * $u, $s, $s, false, 'Right', '');
            $this->buttons['up'] = $box(76 * $u, $oh - (138 + 56) * $u, $s, $s, false, 'Up', '');
            $this->buttons['down'] = $box(76 * $u, $oh - (18 + 56) * $u, $s, $s, false, 'Down', '');
        }
        $d = 68 * $u;
        if ($band >= 72) {
            // landscape phone: the A / B buttons and the pills in the right band, never over the picture
            $w = $band * $u;
            $right = $ow - $w;
            $this->buttons['a'] = $box($right + ($w - $d) / 2, $oh - 28 * $u - $d, $d, $d, true, $this->keys['a'], (string)$this->labels['a']);
            $this->buttons['b'] = $box($right + ($w - $d) / 2, $oh - 112 * $u - $d, $d, $d, true, $this->keys['b'], (string)$this->labels['b']);
            $y = 8 * $u;
            foreach ($pills as $p) {
                $this->buttons[$p] = $box($right + 8 * $u, $y, $w - 16 * $u, 30 * $u, false, $this->keys[$p], (string)$this->labels[$p]);
                $y += 38 * $u;
            }
            $this->zone = ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $oh];
            $this->restX = $w / 2;
        } else {
            $this->buttons['a'] = $box($ow - 20 * $u - $d, $oh - 40 * $u - $d, $d, $d, true, $this->keys['a'], (string)$this->labels['a']);
            $this->buttons['b'] = $box($ow - 104 * $u - $d, $oh - 24 * $u - $d, $d, $d, true, $this->keys['b'], (string)$this->labels['b']);
            $pw = 84 * $u;
            $x = ($ow - count($pills) * $pw - (count($pills) - 1) * 8 * $u) / 2;
            foreach ($pills as $p) {
                $this->buttons[$p] = $box($x, $oh - 12 * $u - 30 * $u, $pw, 30 * $u, false, $this->keys[$p], (string)$this->labels[$p]);
                $x += $pw + 8 * $u;
            }
            $this->zone = ['x' => 0, 'y' => 0, 'w' => $ow * 0.45, 'h' => $oh];
            $this->restX = 90 * $u;
        }
        $this->restY = $oh - 110 * $u;
    }

    // --- helpers --------------------------------------------------------------------------------------------

    private function inside(array $b, float $x, float $y): bool
    {
        // a little larger than drawn: thumbs are not precise
        $m = 6 * $this->unit;
        return $x >= $b['x'] - $m && $x <= $b['x'] + $b['w'] + $m && $y >= $b['y'] - $m && $y <= $b['y'] + $b['h'] + $m;
    }

    /** A key held by any number of fingers (the d-pad and the stick can both hold Left). */
    private function key(string $name, bool $down): void
    {
        $code = ActionMap::keyCode($name);
        if ($code === 0) {
            return;
        }
        $n = ($this->held[$name] ?? 0) + ($down ? 1 : -1);
        $this->held[$name] = max(0, $n);
        if ($down && $n === 1) {
            $this->input->pressKey($code);
        } elseif (!$down && $n <= 0) {
            $this->keyboard->up($code);
        }
    }

    private function moveStick(float $x, float $y): void
    {
        $s = &$this->stickState;
        $s['x'] = $x;
        $s['y'] = $y;
        $dx = ($x - $s['cx']) / $this->unit;
        $dy = ($y - $s['cy']) / $this->unit;
        $len = hypot($dx, $dy);
        $next = [];
        if ($len > self::DEAD) {
            $s['moved'] = true;
            $a = rad2deg(atan2($dy, $dx)); // 8 sectors of 45 degrees
            if ($a > -67.5 && $a < 67.5) {
                $next['Right'] = true;
            }
            if ($a > 112.5 || $a < -112.5) {
                $next['Left'] = true;
            }
            if ($a < -22.5 && $a > -157.5) {
                $next['Up'] = true;
            }
            if ($a > 22.5 && $a < 157.5) {
                $next['Down'] = true;
            }
        }
        $this->setDirs($next);
    }

    /** @param array<string, bool> $next */
    private function setDirs(array $next): void
    {
        foreach (['Left', 'Right', 'Up', 'Down'] as $d) {
            if (isset($next[$d]) !== isset($this->dirs[$d])) {
                $this->key($d, isset($next[$d]));
            }
        }
        $this->dirs = $next;
    }

    /** @return array{0: float, 1: float} the knob's offset, at most MAX away */
    private function clamp(float $dx, float $dy): array
    {
        $len = hypot($dx, $dy);
        $max = self::MAX * $this->unit;
        return $len > $max ? [$dx * $max / $len, $dy * $max / $len] : [$dx, $dy];
    }

    private function moveMouse(Renderer $r, float $x, float $y): void
    {
        [$lx, $ly, $lw, $lh] = $r->letterbox();
        if ($lw > 0 && $lh > 0) {
            $this->mouse->move(($x - $lx) * $r->getWidth() / $lw, ($y - $ly) * $r->getHeight() / $lh);
        }
    }

    private function ring(Renderer $r, float $cx, float $cy, float $radius): void
    {
        $steps = 48;
        for ($i = 0; $i < $steps; $i++) {
            $a = 2 * M_PI * $i / $steps;
            $b = 2 * M_PI * ($i + 1) / $steps;
            $r->drawLine($cx + cos($a) * $radius, $cy + sin($a) * $radius, $cx + cos($b) * $radius, $cy + sin($b) * $radius, [200, 200, 200]);
        }
    }

    /** A filled triangle pointing $dir, in the middle of the button. */
    private function arrow(Renderer $r, string $dir, array $b): void
    {
        $cx = $b['x'] + $b['w'] / 2;
        $cy = $b['y'] + $b['h'] / 2;
        $s = $b['w'] * 0.22;
        for ($i = 0; $i <= (int)$s; $i++) {
            $half = $s * $i / max(1, $s);  // the width grows from the tip
            $t = -$s / 2 + $i;
            match ($dir) {
                'left' => $r->fillRect($cx + $t, $cy - $half, 1, 2 * $half + 1, [255, 255, 255, 220]),
                'right' => $r->fillRect($cx - $t, $cy - $half, 1, 2 * $half + 1, [255, 255, 255, 220]),
                'up' => $r->fillRect($cx - $half, $cy + $t, 2 * $half + 1, 1, [255, 255, 255, 220]),
                default => $r->fillRect($cx - $half, $cy - $t, 2 * $half + 1, 1, [255, 255, 255, 220]),
            };
        }
    }
}
