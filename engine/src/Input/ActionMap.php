<?php

namespace JumPHP\Input;

/**
 * Named actions instead of raw keys: the game asks isPressed('jump'), config/input.yaml says which keys,
 * pad buttons or gestures do it:
 *
 *   actions:
 *     left:  [Left, A, pad0:left, touch:left]
 *     jump:  [Space, Up, pad0:a, touch:tap]
 *
 * Key names are SDL key names (SDL_GetKeyFromName): Left, Right, Up, Down, Space, Return, Escape,
 * Backspace, Tab, Left Shift, A..Z, 0..9, F1..F12, Keypad 8 ... Other sources: padN:a|b|x|y|start... (Gamepad), padN:buttonM,
 * padN:left|right|up|down, touch:left|right|up|down|tap, mouse:1|2|3.
 *
 * Bots, demos and the network can drive actions too: hold('p2_up', true), press('confirm').
 */
class ActionMap
{
    /** @var array<string, string[]> action => binding names */
    private array $bindings = [];
    /** @var array<string, int[]> action => SDL key codes */
    private array $keys = [];
    /** @var array<string, bool> */
    private array $virtualHeld = [];
    /** @var array<string, bool> */
    private array $virtualPressed = [];

    public function __construct(
        private Keyboard $keyboard,
        private Gamepad $gamepad,
        private Touch $touch,
        private Mouse $mouse,
    ) {
    }

    /** @param array<string, string[]|string> $actions */
    public function load(array $actions): void
    {
        foreach ($actions as $action => $bindings) {
            $this->bind((string)$action, (array)$bindings);
        }
    }

    /** @param string[] $bindings replaces the bindings of $action */
    public function bind(string $action, array $bindings): void
    {
        $this->bindings[$action] = array_values(array_map('strval', $bindings));
        $this->keys[$action] = [];
        foreach ($this->bindings[$action] as $name) {
            if (!str_contains($name, ':') || $name === ':') {
                $code = self::keyCode($name);
                if ($code !== 0) {
                    $this->keys[$action][] = $code;
                }
            }
        }
    }

    public static function keyCode(string $name): int
    {
        if (strlen($name) === 1) {
            return ord(strtolower($name)); // SDLK_a = 'a' ... SDLK_0 = '0'
        }
        return function_exists('SDL_GetKeyFromName') ? (int)\SDL_GetKeyFromName($name) : 0;
    }

    public function has(string $action): bool
    {
        return isset($this->bindings[$action]);
    }

    /** @return string[] */
    public function bindings(string $action): array
    {
        return $this->bindings[$action] ?? [];
    }

    /** The first key bound to $action, for "PRESS ENTER" texts. */
    public function label(string $action): string
    {
        foreach ($this->bindings($action) as $name) {
            if (!str_contains($name, ':')) {
                return strtoupper($name);
            }
        }
        return strtoupper($action);
    }

    public function isHeld(string $action): bool
    {
        if (!empty($this->virtualHeld[$action])) {
            return true;
        }
        foreach ($this->keys[$action] ?? [] as $key) {
            if ($this->keyboard->isKeyHeld($key)) {
                return true;
            }
        }
        foreach ($this->bindings[$action] ?? [] as $name) {
            if ($this->sourceHeld($name)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> every action that has a key or another binding */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->bindings), ...array_keys($this->keys)]));
    }

    /** Went down this frame. */
    public function isPressed(string $action): bool
    {
        if (!empty($this->virtualPressed[$action])) {
            return true;
        }
        foreach ($this->keys[$action] ?? [] as $key) {
            if ($this->keyboard->isKeyPressed($key)) {
                return true;
            }
        }
        foreach ($this->bindings[$action] ?? [] as $name) {
            if ($this->sourcePressed($name)) {
                return true;
            }
        }
        return false;
    }

    public function isReleased(string $action): bool
    {
        foreach ($this->keys[$action] ?? [] as $key) {
            if ($this->keyboard->isKeyReleased($key)) {
                return true;
            }
        }
        return false;
    }

    /** -1, 0 or 1 from two opposite actions: axis('left', 'right'). */
    public function axis(string $negative, string $positive): int
    {
        return ($this->isHeld($positive) ? 1 : 0) - ($this->isHeld($negative) ? 1 : 0);
    }

    /** Holds an action from code (bot, replay, network) until hold($action, false). */
    public function hold(string $action, bool $held = true): void
    {
        if ($held && empty($this->virtualHeld[$action])) {
            $this->virtualPressed[$action] = true;
        }
        $this->virtualHeld[$action] = $held;
    }

    /** One press this frame from code. */
    public function press(string $action): void
    {
        $this->virtualPressed[$action] = true;
    }

    public function anyPressed(): bool
    {
        return $this->keyboard->anyPressed() || $this->gamepad->anyPressed() || $this->touch->isPressed('touch:tap')
            || $this->mouse->isPressed(1) || $this->virtualPressed !== [];
    }

    public function endFrame(): void
    {
        $this->virtualPressed = [];
    }

    private function sourceHeld(string $name): bool
    {
        return match (true) {
            str_starts_with($name, 'pad') => $this->gamepad->isHeld($name),
            str_starts_with($name, 'touch:') => $this->touch->isHeld($name),
            str_starts_with($name, 'mouse:') => $this->mouse->isHeld((int)substr($name, 6)),
            default => false,
        };
    }

    private function sourcePressed(string $name): bool
    {
        return match (true) {
            str_starts_with($name, 'pad') => $this->gamepad->isPressed($name),
            str_starts_with($name, 'touch:') => $this->touch->isPressed($name),
            str_starts_with($name, 'mouse:') => $this->mouse->isPressed((int)substr($name, 6)),
            default => false,
        };
    }
}
